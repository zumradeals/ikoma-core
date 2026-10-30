import { ApiError } from '../types';
import { cookie } from '../repository';
import type { LocalOp } from './apply';
import { getMeta, setMeta } from './db';
import type { Snapshot } from './list';
import type { PosteStore } from './store';
import type { PosteVault } from './vault';

export const BATCH = 50;

export type SyncState = {
  online: boolean; syncing: boolean; pending: number; conflicts: number; lastSyncAt: string | null; lastError: string | null;
  needsLogin: boolean; revoked: boolean; stale: boolean; clockWarning: boolean;
};

type Result = { id: string; status: 'applique' | 'conflit'; code?: string; message?: string };

export class SyncManager {
  state: SyncState = { online: true, syncing: false, pending: 0, conflicts: 0, lastSyncAt: null, lastError: null, needsLogin: false, revoked: false, stale: false, clockWarning: false };
  private running: Promise<void> | null = null;
  private again = false;
  private failures = 0;
  private timer: ReturnType<typeof setTimeout> | undefined;

  constructor(
    private readonly vault: PosteVault, private readonly store: PosteStore,
    private readonly hooks: { onState: (s: SyncState) => void; onSnapshot: (s: Snapshot) => Promise<void>; onRevoked: (reason: 'appareil_revoque' | 'appareil_non_enrole') => Promise<void>; now: () => number; fetcher?: typeof fetch },
  ) {}

  private emit(patch: Partial<SyncState>): void { this.state = { ...this.state, ...patch }; this.hooks.onState(this.state); }

  async refreshCounts(): Promise<void> {
    const lastSyncAt = (await getMeta(this.vault.db, 'lastSyncAt')) ?? null;
    this.emit({ pending: await this.store.count('pending'), conflicts: await this.store.count('conflict'), lastSyncAt, stale: lastSyncAt === null || this.hooks.now() - Date.parse(lastSyncAt) > 3_600_000 });
  }

  /** Demande un envoi sans bloquer l'écran ; les échecs réseau sont repris avec un délai croissant. */
  kick(delay = 0): void {
    clearTimeout(this.timer);
    this.timer = setTimeout(() => { void this.sync(); }, delay);
  }
  stop(): void { clearTimeout(this.timer); }

  /** Un seul envoi à la fois ; une demande reçue pendant un envoi en provoque un second juste après (une opération arrivée en fin de course n'attend pas). */
  sync(): Promise<void> {
    if (this.running) { this.again = true; return this.running; }
    this.running = this.loop().finally(() => { this.running = null; });
    return this.running;
  }

  private async loop(): Promise<void> {
    do { this.again = false; await this.run(); } while (this.again && !this.state.revoked);
  }

  /** Appel authentifié par le jeton d'appareil (aussi utilisé par le contrôle en ligne). */
  request<T>(method: 'GET' | 'POST', url: string, body?: object, operationId?: string, timeoutMs?: number): Promise<T> { return this.call<T>(method, url, body, operationId, timeoutMs); }

  private async call<T>(method: 'GET' | 'POST', url: string, body?: object, operationId?: string, timeoutMs?: number): Promise<T> {
    const token = await this.vault.getSecret<string>('deviceToken');
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN'), 'X-Device-Token': token ?? '' };
    if (body) headers['Content-Type'] = 'application/json';
    if (operationId) headers['X-Operation-Id'] = operationId;
    let response: Response;
    try { response = await (this.hooks.fetcher ?? fetch)(url, { method, headers, body: body ? JSON.stringify(body) : undefined, credentials: 'same-origin', redirect: 'manual', signal: timeoutMs ? AbortSignal.timeout(timeoutMs) : undefined }); }
    catch { throw new ApiError('reseau', 'Pas de connexion au serveur.'); }
    if (response.type === 'opaqueredirect' || response.status === 419 || (response.status === 401 && !(await this.revokedBody(response)))) throw new ApiError('session_expiree', 'Session expirée.', null, 401);
    const data = await response.json().catch(() => null) as (T & { code?: string; message?: string }) | null;
    if (response.status === 401 && data?.code === 'appareil_revoque') throw new ApiError('appareil_revoque', data.message ?? 'Tablette révoquée.', null, 401);
    if (!response.ok) throw new ApiError(data?.code ?? 'erreur', data?.message ?? 'Erreur du serveur.', null, response.status);
    return data as T;
  }

  private async revokedBody(response: Response): Promise<boolean> {
    const data = await response.clone().json().catch(() => null) as { code?: string } | null;
    return data?.code === 'appareil_revoque' || data?.code === 'appareil_non_enrole';
  }

  private async run(): Promise<void> {
    if (!this.vault.unlocked || this.state.revoked) return;
    this.emit({ syncing: true, lastError: null, online: typeof navigator === 'undefined' ? true : navigator.onLine });
    try {
      for (;;) {
        const batch = (await this.store.ops('pending')).slice(0, BATCH);
        if (batch.length === 0) break;
        const sentAt = new Date(this.hooks.now()).toISOString();
        const reply = await this.call<{ results: Result[]; clock_skew_seconds: number | null }>('POST', '/poste/api/sync', { sent_at: sentAt, operations: await Promise.all(batch.map(op => this.wire(op))) });
        const byId = new Map(reply.results.map(r => [r.id, r]));
        for (const op of batch) {
          const result = byId.get(op.id);
          if (!result) throw new ApiError('reponse_incomplete', 'Réponse incomplète du serveur.');
          if (result.status === 'applique') await this.store.setStatus(op, 'done');
          else await this.store.setStatus({ ...op, result: { code: result.code ?? 'conflit', message: result.message ?? 'Opération refusée.' } }, 'conflict');
        }
        const threshold = (await getMeta(this.vault.db, 'settings'))?.clock_skew_seconds ?? 300;
        this.emit({ clockWarning: reply.clock_skew_seconds !== null && Math.abs(reply.clock_skew_seconds) > threshold });
        await this.refreshCounts();
      }
      const known = (await getMeta(this.vault.db, 'listVersion')) ?? '';
      const current = await this.call<{ version: string; server_time: string }>('GET', '/poste/api/liste/version');
      await setMeta(this.vault.db, 'clockOffsetMs', Date.parse(current.server_time) - Date.now());
      if (current.version !== known || (await this.store.count('done')) > 0) {
        const snapshot = await this.call<Snapshot>('GET', '/poste/api/liste');
        if (!Array.isArray(snapshot.visitors) || !snapshot.poste) throw new ApiError('liste_invalide', 'La copie de la liste reçue est incomplète : la copie actuelle est conservée.');
        await this.store.purgeDone();
        await this.store.saveSnapshot(snapshot);
        await this.hooks.onSnapshot(snapshot);
      }
      await setMeta(this.vault.db, 'lastSyncAt', new Date(this.hooks.now()).toISOString());
      this.failures = 0;
      this.emit({ needsLogin: false });
    } catch (e) {
      const error = e instanceof ApiError ? e : new ApiError('erreur', 'Erreur inattendue pendant la synchronisation.');
      if (error.code === 'appareil_revoque' || error.code === 'appareil_non_enrole') {
        this.emit({ revoked: true, syncing: false, lastError: error.message });
        await this.hooks.onRevoked(error.code);
        return;
      }
      if (error.code === 'session_expiree') this.emit({ needsLogin: true, lastError: 'Votre session a expiré : reconnectez-vous pour envoyer vos opérations.' });
      else {
        this.failures++;
        this.emit({ lastError: error.code === 'reseau' ? null : error.message });
        this.kick(Math.min(60_000, 2_000 * 2 ** Math.min(this.failures, 5)));
      }
    } finally {
      this.emit({ syncing: false });
      await this.refreshCounts();
    }
  }

  private async wire(op: LocalOp) {
    const payload = { ...op.payload };
    if (op.type === 'identite') { payload.photo_b64 = await this.store.photoBase64(op.id); if (payload.document_photo === true) payload.document_photo_b64 = await this.store.photoBase64(`doc:${op.id}`); }
    return { id: op.id, seq: op.seq, type: op.type, agent_id: op.agent_id, client_time: op.client_time, visitor_rev: op.visitor_rev, payload };
  }
}
