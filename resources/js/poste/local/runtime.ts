import { ApiError } from '../types';
import { cookie } from '../repository';
import type { LocalOp } from './apply';
import { PosteDb, getMeta, setMeta } from './db';
import { LocalPosteRepository } from './local';
import type { Snapshot } from './list';
import { PosteStore } from './store';
import { SyncManager, type SyncState } from './sync';
import { PosteVault } from './vault';

export const WIPE_AFTER_EVENT_MS = 24 * 3_600_000;
export type WipeReason = 'echecs_pin' | 'deconnexion' | 'fin_evenement' | 'manuel';
type Enrolled = { device_token: string; device_secret: string; poste: { id: string; name: string; station: string | null; event_id: string; event_name: string } };

/** Assemble le coffre, le magasin chiffré, la synchronisation et le dépôt local d'une tablette. Un seul exemplaire par page. */
export const syncRequiredMessage = (hours: number): string => `Cette tablette n’a pas communiqué avec le serveur depuis plus de ${hours} heure${hours > 1 ? 's' : ''} : connectez-la au réseau pour reprendre. Rien n’est perdu.`;

export class PosteRuntime {
  readonly db: PosteDb;
  readonly vault: PosteVault;
  readonly store: PosteStore;
  readonly sync: SyncManager;
  repo: LocalPosteRepository | null = null;
  profile: { id: string; name: string } | null = null;

  constructor(public handlers: { onState: (s: SyncState) => void; onWiped: (reason: WipeReason) => void } = { onState: () => undefined, onWiped: () => undefined }, options: { dbName?: string; iterations?: number; fetcher?: typeof fetch } = {}) {
    this.db = new PosteDb(options.dbName);
    this.vault = new PosteVault(this.db, { iterations: options.iterations });
    this.store = new PosteStore(this.vault);
    this.sync = new SyncManager(this.vault, this.store, {
      onState: s => this.handlers.onState(s), now: () => Date.now(), fetcher: options.fetcher,
      onSnapshot: async snapshot => { await this.afterSnapshot(snapshot); },
      onRevoked: async () => { await this.wipe('deconnexion', true); },
    });
    this.fetcher = options.fetcher ?? ((...args) => fetch(...args));
  }
  private readonly fetcher: typeof fetch;

  state(): ReturnType<PosteVault['state']> { return this.vault.state(); }

  private async afterSnapshot(snapshot: Snapshot): Promise<void> {
    await setMeta(this.db, 'settings', snapshot.settings);
    await setMeta(this.db, 'eventEndsAt', snapshot.event.ends_at);
    await setMeta(this.db, 'posteName', snapshot.poste.station || snapshot.poste.name);
    const offset = (await getMeta(this.db, 'clockOffsetMs')) ?? 0;
    if (this.repo) { this.repo.setClockOffset(offset); await this.repo.replaceSnapshot(snapshot); }
  }

  async enrol(code: string, pin: string, agent: { id: string; name: string }): Promise<void> {
    const response = await this.fetcher('/poste/api/enroler', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, body: JSON.stringify({ code }), credentials: 'same-origin' }).catch(() => { throw new ApiError('reseau', 'Pas de connexion au serveur : l’enrôlement demande le réseau.'); });
    const data = await response.json().catch(() => null) as (Enrolled & { message?: string; code?: string; errors?: Record<string, string[]> }) | null;
    if (!response.ok || !data?.device_token) throw new ApiError(data?.code ?? 'erreur', data?.message ?? Object.values(data?.errors ?? {})[0]?.[0] ?? 'Enrôlement impossible. Vérifiez le code ou demandez-en un nouveau.', null, response.status);
    await this.vault.enrol({ token: data.device_token, secret: data.device_secret, pin, agent, poste: { id: data.poste.id, name: data.poste.station || data.poste.name }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    await this.sync.sync();
    if (!(await this.db.chunks.get('list:head'))) {
      await this.vault.wipe();
      throw new ApiError('liste_indisponible', 'La liste des visiteurs n’a pas pu être copiée sur la tablette : réessayez avec une bonne connexion.');
    }
    await this.open();
  }

  async unlock(pin: string): Promise<void> {
    try { await this.vault.unlock(pin); }
    catch (e) { if (e instanceof Error && 'code' in e && (e as { code: string }).code === 'efface') this.handlers.onWiped('echecs_pin'); throw e; }
    if (await this.expired()) {
      this.vault.lock();
      await this.wipe('fin_evenement', true);
      return;
    }
    await this.open();
    // Audit global F2 : une tablette restée trop longtemps sans réseau (volée, oubliée) ne reprend le travail qu'après une synchronisation réussie.
    const hours = await this.syncRequiredHours();
    if (hours !== null) {
      await this.sync.sync();
      if ((await this.syncRequiredHours()) !== null) { this.lock(); throw new ApiError('synchronisation_requise', syncRequiredMessage(hours)); }
    }
    this.sync.kick();
  }

  /** Nombre d'heures au-delà duquel le réseau est exigé, si la dernière synchronisation est plus ancienne ; sinon null (0 dans les réglages : jamais). */
  async syncRequiredHours(): Promise<number | null> {
    const max = (await getMeta(this.db, 'settings'))?.offline_max_hours ?? 0;
    const last = await getMeta(this.db, 'lastSyncAt');
    if (!max || !last) return null;
    return Date.now() - Date.parse(last) > max * 3_600_000 ? max : null;
  }

  private async open(): Promise<void> {
    this.profile = await this.vault.getSecret<{ id: string; name: string }>('profile');
    this.repo = await LocalPosteRepository.open(this.vault, this.store, () => { void this.sync.refreshCounts(); this.sync.kick(); }, this.sync);
    await this.sync.refreshCounts();
  }

  /** Effacement 24 h après la fin de l'événement, à condition que plus rien ne reste à envoyer. */
  async expired(): Promise<boolean> {
    const ends = await getMeta(this.db, 'eventEndsAt');
    if (!ends || Date.now() < Date.parse(ends) + WIPE_AFTER_EVENT_MS) return false;
    return (await this.store.count('pending')) === 0;
  }

  lock(): void { this.vault.lock(); this.repo = null; this.sync.stop(); }

  /** Efface la tablette ; l'effacement est déclaré au serveur quand le réseau le permet (sans jamais bloquer l'effacement). */
  async wipe(reason: WipeReason, report = true): Promise<void> {
    let token: string | null = null; let lost = 0;
    if (report && this.vault.unlocked) { token = await this.vault.getSecret<string>('deviceToken').catch(() => null); lost = await this.store.count('pending'); }
    this.sync.stop();
    await this.vault.wipe();
    this.repo = null; this.profile = null;
    this.handlers.onWiped(reason);
    if (token) {
      void this.fetcher('/poste/api/efface', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN'), 'X-Device-Token': token }, body: JSON.stringify({ motif: reason, operations_perdues: lost }), credentials: 'same-origin', keepalive: true }).catch(() => undefined);
    }
  }

  conflicts(): Promise<LocalOp[]> { return this.store.ops('conflict'); }
}
