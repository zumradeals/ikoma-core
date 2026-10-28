import { createDeviceKey, createVault, fromB64, openBytes, openJson, openVaultMode, sealBytes, sealJson, toB64, PBKDF2_ITERATIONS } from './crypto';
import { getMeta, setMeta, type PosteDb } from './db';

export class PinError extends Error {
  constructor(public readonly code: 'pin_incorrect' | 'verrouille' | 'efface' | 'vide', message: string, public readonly remaining = 0, public readonly lockedUntil = 0) { super(message); }
}

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

export type EnrolInput = {
  token: string; secret: string; pin: string; duressPin?: string; agent: { id: string; name: string }; poste: { id: string; name: string }; eventEndsAt: string | null;
  settings: { inactivity_minutes: number; clock_skew_seconds: number; pin_max_failures: number; offline_max_hours?: number };
};

/**
 * Coffre de la tablette : enrôlement, déverrouillage par PIN, essais limités puis effacement, verrouillage, effacement local.
 * Tant que le coffre est verrouillé, la clé de données n'existe pas en mémoire et rien n'est lisible.
 */
export class PosteVault {
  private dek: CryptoKey | null = null;
  constructor(readonly db: PosteDb, private readonly options: { iterations?: number; now?: () => number } = {}) {}

  private now(): number { return this.options.now ? this.options.now() : Date.now(); }
  get key(): CryptoKey | null { return this.dek; }
  get unlocked(): boolean { return this.dek !== null; }

  async state(): Promise<'vide' | 'verrouille' | 'deverrouille'> {
    if (this.dek) return 'deverrouille';
    return (await getMeta(this.db, 'vault')) ? 'verrouille' : 'vide';
  }

  async enrol(input: EnrolInput): Promise<void> {
    const problem = pinProblem(input.pin);
    if (problem) throw new PinError('pin_incorrect', problem);
    if (input.duressPin) { // le code de contrainte suit les mêmes règles que le PIN et doit en être différent
      const duressProblem = pinProblem(input.duressPin);
      if (duressProblem) throw new PinError('pin_incorrect', `Code de contrainte : ${duressProblem}`);
      if (input.duressPin === input.pin) throw new PinError('pin_incorrect', 'Le code de contrainte doit être différent du PIN.');
    }
    await this.wipe();
    const deviceKey = await createDeviceKey();
    const secret = fromB64(input.secret);
    const { record, dek } = await createVault(input.pin, secret, deviceKey, this.options.iterations ?? PBKDF2_ITERATIONS, input.duressPin);
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

  /** Ouvre le coffre. Renvoie « contrainte » quand c'est le code de contrainte qui a été saisi : l'écran n'en montre rien, l'appelant prévient en silence. */
  async unlock(pin: string): Promise<'normal' | 'contrainte'> {
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
    let mode: 'normal' | 'contrainte';
    try {
      const opened = await openVaultMode(pin, record, await openBytes(deviceKey, sealedSecret, 'serverSecret'), deviceKey);
      this.dek = opened.dek; mode = opened.duress ? 'contrainte' : 'normal';
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

    return mode;
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
  private async writeFailures(deviceKey: CryptoKey, value: Failures): Promise<void> {
    await this.db.chunks.put({ id: FAILURES, data: await sealJson(deviceKey, value, FAILURES) });
  }
  static b64 = toB64;
}
