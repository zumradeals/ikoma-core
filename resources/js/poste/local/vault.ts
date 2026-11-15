import { createDeviceKey, createVault, createVaultV2, fromB64, isV2, openBytes, openJson, openVault, openVaultV2, sealBytes, sealJson, toB64, unwrapV1Raw, wrapRawV2, PBKDF2_ITERATIONS, type VaultRecordV1 } from './crypto';
import { getMeta, setMeta, type PosteDb } from './db';

export class PinError extends Error {
  constructor(public readonly code: 'pin_incorrect' | 'verrouille' | 'efface' | 'vide', message: string, public readonly remaining = 0, public readonly lockedUntil = 0) { super(message); }
}

/** Le bail de poste ne permet pas d'ouvrir le coffre (autre appareil, autre compte, bail remplacé) : rien n'est effacé, aucun compteur n'est touché. */
export class LeaseError extends Error {
  constructor(public readonly code: 'bail_invalide' | 'bail_absent' | 'vide', message: string) { super(message); }
}

/** Bail de poste tel que le serveur le remet : secret en base 64 et échéance. */
export type Lease = { secret: string; expires_at: string };

const FAILURES = 'failures';
type Failures = { count: number; lockedUntil: number };

/** PIN refusés : suites, répétitions, motifs alternés (docs/AUTH_HORS_LIGNE.md, section 4). */
export function pinProblem(pin: string): string | null {
  if (!/^\d{6}$/.test(pin)) return 'Le PIN doit comporter exactement 6 chiffres.';
  const digits = pin.split('').map(Number);
  const step = (i: number) => (digits[i + 1] - digits[i] + 10) % 10;
  const sequence = [0, 1, 2, 3, 4].every(i => step(i) === 1) || [0, 1, 2, 3, 4].every(i => step(i) === 9);
  const repeated = new Set(digits).size === 1;
  const alternating = pin.slice(0, 2).repeat(3) === pin || pin.slice(0, 3).repeat(2) === pin;
  return sequence || repeated || alternating ? 'Ce PIN est trop facile à deviner : évitez les suites et les répétitions.' : null;
}

/** Enrôlement SANS PIN (espace Agent) : le coffre est enveloppé par le bail de poste remis par le serveur. */
export type EnrolLeaseInput = {
  token: string; lease: Lease; agent: { id: string; name: string }; poste: { id: string; name: string }; eventEndsAt: string | null;
  settings: { inactivity_minutes: number; clock_skew_seconds: number; pin_max_failures: number; offline_max_hours?: number };
};

export type EnrolInput = {
  token: string; secret: string; pin: string; agent: { id: string; name: string }; poste: { id: string; name: string }; eventEndsAt: string | null;
  settings: { inactivity_minutes: number; clock_skew_seconds: number; pin_max_failures: number; offline_max_hours?: number };
};

/**
 * Coffre de la tablette : enrôlement, déverrouillage par PIN, essais limités puis effacement, verrouillage, effacement local.
 * Tant que le coffre est verrouillé, la clé de données n'existe pas en mémoire et rien n'est lisible.
 */
export class PosteVault {
  protected dek: CryptoKey | null = null;
  constructor(readonly db: PosteDb, private readonly options: { iterations?: number; now?: () => number } = {}) {}

  private now(): number { return this.options.now ? this.options.now() : Date.now(); }
  get key(): CryptoKey | null { return this.dek; }
  get unlocked(): boolean { return this.dek !== null; }

  async state(): Promise<'vide' | 'verrouille' | 'deverrouille'> {
    if (this.dek) return 'deverrouille';
    return (await getMeta(this.db, 'vault')) ? 'verrouille' : 'vide';
  }

  /**
   * État du coffre de l'espace Agent (sans PIN) : vide (rien d'enrôlé), ferme (s'ouvre par le bail), migration (ancien coffre par PIN, à migrer une fois), deverrouille.
   * L'ancien état `state()` reste celui du coffre par PIN (évacuation du superviseur).
   */
  async agentState(): Promise<'vide' | 'ferme' | 'migration' | 'deverrouille'> {
    if (this.dek) return 'deverrouille';
    const record = await getMeta(this.db, 'vault');
    if (!record) return 'vide';
    return isV2(record) ? 'ferme' : 'migration';
  }

  async enrol(input: EnrolInput): Promise<void> {
    const problem = pinProblem(input.pin);
    if (problem) throw new PinError('pin_incorrect', problem);
    await this.wipe();
    const deviceKey = await createDeviceKey();
    const secret = fromB64(input.secret);
    const { record, dek } = await createVault(input.pin, secret, deviceKey, this.options.iterations ?? PBKDF2_ITERATIONS);
    await setMeta(this.db, 'deviceKey', deviceKey);
    await setMeta(this.db, 'serverSecret', await sealBytes(deviceKey, secret, 'serverSecret')); // scellé par la clé de l'appareil : jamais en clair
    await setMeta(this.db, 'vault', record);
    await setMeta(this.db, 'posteId', input.poste.id);
    await setMeta(this.db, 'posteName', input.poste.name);
    await setMeta(this.db, 'eventEndsAt', input.eventEndsAt);
    await setMeta(this.db, 'settings', input.settings);
    await setMeta(this.db, 'listVersion', '');
    await setMeta(this.db, 'lastSyncAt', null);
    await setMeta(this.db, 'clockOffsetMs', 0);
    await setMeta(this.db, 'lastSeq', 0);
    await this.writeFailures(deviceKey, { count: 0, lockedUntil: 0 });
    this.dek = dek;
    await this.putSecret('deviceToken', input.token);
    await this.putSecret('profile', input.agent);
  }

  async unlock(pin: string): Promise<void> {
    const record = await getMeta(this.db, 'vault');
    const deviceKey = await getMeta(this.db, 'deviceKey');
    const sealedSecret = await getMeta(this.db, 'serverSecret');
    if (!record || !deviceKey || !sealedSecret) throw new PinError('vide', 'Cette tablette n’est pas enrôlée.');
    const settings = await getMeta(this.db, 'settings');
    const max = settings?.pin_max_failures ?? 15;
    let failures = await this.readFailures(deviceKey);
    if (failures === null) { // compteur supprimé ou altéré : on ne donne aucun essai de plus
      await this.wipe();
      throw new PinError('efface', 'Les données de cette tablette ont été effacées par sécurité.');
    }
    if (failures.lockedUntil > this.now()) throw new PinError('verrouille', 'Trop d’essais : patientez avant de réessayer.', Math.max(0, max - failures.count), failures.lockedUntil);
    try {
      this.dek = await openVault(pin, record as VaultRecordV1, await openBytes(deviceKey, sealedSecret, 'serverSecret'), deviceKey);
    } catch {
      failures = { count: failures.count + 1, lockedUntil: 0 };
      if (failures.count >= max) {
        await this.wipe();
        throw new PinError('efface', 'Trop d’échecs : les données de cette tablette ont été effacées.');
      }
      if (failures.count % 5 === 0) failures.lockedUntil = this.now() + (failures.count >= 10 ? 300_000 : 60_000);
      await this.writeFailures(deviceKey, failures);
      throw new PinError(failures.lockedUntil ? 'verrouille' : 'pin_incorrect', failures.lockedUntil ? 'Trop d’essais : patientez avant de réessayer.' : 'PIN incorrect.', max - failures.count, failures.lockedUntil);
    }
    await this.writeFailures(deviceKey, { count: 0, lockedUntil: 0 });
  }

  /** Enrôlement sans PIN (espace Agent) : DEK aléatoire enveloppée par le bail puis par la clé d'appareil ; le bail est conservé scellé par la clé d'appareil. */
  async enrolLease(input: EnrolLeaseInput): Promise<void> {
    await this.wipe();
    const deviceKey = await createDeviceKey();
    const { record, dek } = await createVaultV2(fromB64(input.lease.secret), deviceKey);
    await setMeta(this.db, 'deviceKey', deviceKey);
    await setMeta(this.db, 'vault', record);
    await this.storeLease(deviceKey, input.lease);
    await setMeta(this.db, 'posteId', input.poste.id);
    await setMeta(this.db, 'posteName', input.poste.name);
    await setMeta(this.db, 'eventEndsAt', input.eventEndsAt);
    await setMeta(this.db, 'settings', input.settings);
    await setMeta(this.db, 'listVersion', '');
    await setMeta(this.db, 'lastSyncAt', null);
    await setMeta(this.db, 'clockOffsetMs', 0);
    await setMeta(this.db, 'lastSeq', 0);
    this.dek = dek;
    await this.putSecret('deviceToken', input.token);
    await this.putSecret('profile', input.agent);
  }

  /** Bail conservé sur la tablette, scellé par la clé d'appareil (jamais en clair) ; null s'il est absent, altéré ou échu. */
  async storedLease(): Promise<{ secret: Uint8Array; expiresAt: number } | null> {
    const sealed = await getMeta(this.db, 'lease');
    const deviceKey = await getMeta(this.db, 'deviceKey');
    if (!sealed || !deviceKey) return null;
    try {
      const lease = await openJson<Lease>(deviceKey, sealed, 'lease');
      const expiresAt = Date.parse(lease.expires_at);
      if (Number.isNaN(expiresAt) || expiresAt <= this.now()) return null;
      return { secret: fromB64(lease.secret), expiresAt };
    } catch { return null; }
  }

  private async storeLease(deviceKey: CryptoKey, lease: Lease): Promise<void> {
    await setMeta(this.db, 'lease', await sealJson(deviceKey, lease, 'lease'));
  }

  /** Remplace le bail (renouvellement en ligne). Sans effet si la tablette n'est pas enrôlée. */
  async saveLease(lease: Lease): Promise<void> {
    const deviceKey = await getMeta(this.db, 'deviceKey');
    if (deviceKey) await this.storeLease(deviceKey, lease);
  }

  /** Supprime le bail de la tablette (déconnexion, page de connexion) : le coffre ne s'ouvrira qu'après une nouvelle connexion en ligne. Les données restent chiffrées. */
  async dropLease(): Promise<void> {
    this.dek = null;
    await this.db.meta.delete('lease');
  }

  /** Ouvre le coffre avec le bail. Échoue sans rien effacer si le bail n'est pas celui de ce coffre. */
  async openWithLease(lease: Uint8Array): Promise<void> {
    const record = await getMeta(this.db, 'vault');
    const deviceKey = await getMeta(this.db, 'deviceKey');
    if (!record || !deviceKey) throw new LeaseError('vide', 'Cette tablette n’est pas enrôlée.');
    if (!isV2(record)) throw new LeaseError('bail_absent', 'Cette tablette garde un ancien coffre : il doit être migré une fois.');
    try { this.dek = await openVaultV2(record, lease, deviceKey); }
    catch { throw new LeaseError('bail_invalide', 'Ce bail n’ouvre pas le coffre de cette tablette.'); }
  }

  /**
   * Migration unique d'un ancien coffre (par PIN) : l'agent saisit son PIN une dernière fois, la clé de données est rééveloppée par le bail, vérifiée, puis l'ancienne
   * enveloppe, le secret d'appareil et le compteur d'essais sont supprimés. Les données et la file d'opérations ne sont JAMAIS rechiffrées ni touchées.
   * Les essais restent comptés comme avant : trop d'échecs effacent la tablette.
   */
  async migrate(pin: string, lease: Lease): Promise<void> {
    const record = await getMeta(this.db, 'vault');
    const deviceKey = await getMeta(this.db, 'deviceKey');
    const sealedSecret = await getMeta(this.db, 'serverSecret');
    if (!record || !deviceKey) throw new PinError('vide', 'Cette tablette n’est pas enrôlée.');
    if (isV2(record)) return;
    if (!sealedSecret) throw new PinError('vide', 'Cette tablette n’est pas enrôlée.');
    const settings = await getMeta(this.db, 'settings');
    const max = settings?.pin_max_failures ?? 15;
    let failures = await this.readFailures(deviceKey);
    if (failures === null) { // compteur supprimé ou altéré : aucun essai de plus
      await this.wipe();
      throw new PinError('efface', 'Les données de cette tablette ont été effacées par sécurité.');
    }
    if (failures.lockedUntil > this.now()) throw new PinError('verrouille', 'Trop d’essais : patientez avant de réessayer.', Math.max(0, max - failures.count), failures.lockedUntil);
    let raw: Uint8Array;
    try { raw = await unwrapV1Raw(pin, record as VaultRecordV1, await openBytes(deviceKey, sealedSecret, 'serverSecret'), deviceKey); }
    catch {
      failures = { count: failures.count + 1, lockedUntil: 0 };
      if (failures.count >= max) {
        await this.wipe();
        throw new PinError('efface', 'Trop d’échecs : les données de cette tablette ont été effacées.');
      }
      if (failures.count % 5 === 0) failures.lockedUntil = this.now() + (failures.count >= 10 ? 300_000 : 60_000);
      await this.writeFailures(deviceKey, failures);
      throw new PinError(failures.lockedUntil ? 'verrouille' : 'pin_incorrect', failures.lockedUntil ? 'Trop d’essais : patientez avant de réessayer.' : 'PIN incorrect.', max - failures.count, failures.lockedUntil);
    }
    const next = await wrapRawV2(raw, fromB64(lease.secret), deviceKey);
    const verified = await openVaultV2(next, fromB64(lease.secret), deviceKey); // le nouveau format doit ouvrir la clé AVANT de remplacer l'ancien
    await setMeta(this.db, 'vault', next);
    await this.storeLease(deviceKey, lease);
    await this.db.meta.delete('serverSecret');
    await this.db.chunks.delete(FAILURES);
    this.dek = verified;
  }

  lock(): void { this.dek = null; }

  /** Efface tout ce que la tablette conserve (liste, file, photos, clés, compteurs). */
  async wipe(): Promise<void> {
    this.dek = null;
    // Fonction de transaction non asynchrone : Dexie attend lui-même la fin des requêtes (pas de validation anticipée).
    await this.db.transaction('rw', [this.db.meta, this.db.chunks, this.db.ops, this.db.photos], () => {
      void this.db.meta.clear(); void this.db.chunks.clear(); void this.db.ops.clear(); void this.db.photos.clear();
    });
  }

  async putSecret(name: string, value: unknown): Promise<void> {
    if (!this.dek) throw new PinError('verrouille', 'Tablette verrouillée.');
    await this.db.chunks.put({ id: `secret:${name}`, data: await sealJson(this.dek, value, `secret:${name}`) });
  }
  async getSecret<T>(name: string): Promise<T | null> {
    if (!this.dek) throw new PinError('verrouille', 'Tablette verrouillée.');
    const row = await this.db.chunks.get(`secret:${name}`);
    return row ? openJson<T>(this.dek, row.data, `secret:${name}`) : null;
  }

  private async readFailures(deviceKey: CryptoKey): Promise<Failures | null> {
    const row = await this.db.chunks.get(FAILURES);
    if (!row) return null;
    try { return await openJson<Failures>(deviceKey, row.data, FAILURES); } catch { return null; }
  }
  protected async writeFailures(deviceKey: CryptoKey, value: Failures): Promise<void> {
    await this.db.chunks.put({ id: FAILURES, data: await sealJson(deviceKey, value, FAILURES) });
  }
  static b64 = toB64;
}
