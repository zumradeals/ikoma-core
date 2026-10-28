import { ApiError, type Avis, type Checkpoint, type ControlResult, type DeskContext, type ExitResult, type Fiche, type PointRef, type Poste, type SearchResult, type UnlistedInput } from '../types';
import type { GroupPosteRepository } from '../repository';
import { applyOp, type ApplyResult, ficheOf, findBadge, searchOf, type LocalOp, type OpType } from './apply';
import { sha256Hex } from './crypto';
import { getMeta } from './db';
import { applyGroupOp, groupSummaries, groupView, type ArrivalResult, type GroupSummary, type GroupView } from './group';
import { LocalList, type Snapshot } from './list';
import { checkpointsOf, controlLocal, exitLocal, resolveBadge } from './site';
import type { PosteStore } from './store';
import type { SyncManager } from './sync';
import type { PosteVault } from './vault';

type Profile = { id: string; name: string };

/**
 * Implémentation locale du dépôt de données du poste : tout est lu dans la copie chiffrée de la tablette et toute écriture
 * est d'abord enregistrée dans la file (IndexedDB) avant d'être envoyée, réseau ou non.
 */
export class LocalPosteRepository implements GroupPosteRepository {
  list: LocalList;
  constructor(snapshot: Snapshot, private readonly vault: PosteVault, private readonly store: PosteStore, private readonly profile: Profile, private readonly onQueued: () => void, private clockOffset = 0, private readonly sync: SyncManager | null = null) {
    this.list = new LocalList(snapshot);
  }

  static async open(vault: PosteVault, store: PosteStore, onQueued: () => void, sync: SyncManager | null = null): Promise<LocalPosteRepository | null> {
    const snapshot = await store.loadSnapshot();
    const profile = await vault.getSecret<Profile>('profile');
    if (!snapshot || !profile) return null;
    const repo = new LocalPosteRepository(snapshot, vault, store, profile, onQueued, (await getMeta(vault.db, 'clockOffsetMs')) ?? 0, sync);
    await repo.replayPending();
    return repo;
  }

  /** Heure de référence : l'horloge de la tablette corrigée de l'écart mesuré avec le serveur à la dernière synchronisation. */
  now(): number { return Date.now() + this.clockOffset; }
  setClockOffset(ms: number): void { this.clockOffset = ms; }

  /** Nouvelle copie du serveur : on y rejoue les opérations pas encore envoyées pour ne rien faire disparaître à l'écran. */
  async replaceSnapshot(snapshot: Snapshot): Promise<void> {
    this.list = new LocalList(snapshot);
    await this.replayPending();
  }

  private async replayPending(): Promise<void> {
    for (const op of await this.store.ops('pending')) {
      try { applyOp(this.list, op, Date.parse(op.client_time)); } catch { /* la règle sera rejugée par le serveur : il mettra l'opération en quarantaine */ }
    }
  }

  async listPostes(): Promise<Poste[]> {
    const s = this.list.snapshot;
    return [{ id: s.poste.id, name: s.poste.name, station: s.poste.station, event_id: s.poste.event_id, event_name: s.event.name }];
  }

  async context(): Promise<DeskContext> {
    const s = this.list.snapshot;
    return { poste: s.poste, event: { id: s.event.id, name: s.event.name }, hosts: s.hosts, categories: s.categories };
  }

  async search(query: string): Promise<SearchResult[]> { return searchOf(this.list, query); }
  async visitor(id: string): Promise<Fiche> { return ficheOf(this.list, id, this.now()); }
  async pendingCount(): Promise<number> { return this.store.count('pending'); }

  private async enqueue(operationId: string, type: OpType, payload: Record<string, unknown>, photo?: Blob): Promise<ApplyResult> {
    const existing = await this.store.getOp(operationId);
    if (existing && type.startsWith('groupe_')) return applyGroupOp(this.list, type, existing.op.payload); // toutes les opérations de groupe sont sans effet quand elles sont rejouées
    if (existing) { // même opération rejouée (coupure, double clic) : rien de plus n'est enregistré
      const v = this.list.visitors.get(String(existing.op.payload.visitor_id));
      return { badge_code: String(existing.op.payload.code ?? v?.badge_code ?? ''), zones: v ? this.list.zonesFor(v) : [], visitor_id: String(existing.op.payload.visitor_id ?? '') };
    }
    const visitorId = typeof payload.visitor_id === 'string' ? payload.visitor_id : null;
    const op: LocalOp = { id: operationId, seq: await this.store.nextSeq(), type, agent_id: this.profile.id, client_time: new Date(this.now()).toISOString(), visitor_rev: visitorId ? this.list.visitors.get(visitorId)?.rev ?? null : null, payload };
    const result = applyOp(this.list, op, this.now()); // refuse avant d'écrire dans la file si une règle l'interdit
    await this.store.addOp(op, photo);
    this.onQueued();
    return result;
  }

  async saveIdentity(operationId: string, visitorId: string, photo: Blob): Promise<void> { await this.enqueue(operationId, 'identite', { visitor_id: visitorId, identity_checked: true }, photo); }
  async validate(operationId: string, visitorId: string, avis?: Avis): Promise<void> { await this.enqueue(operationId, 'valider', { visitor_id: visitorId, avis: avis ?? null }); }
  async refuse(operationId: string, visitorId: string, motif: string): Promise<void> { await this.enqueue(operationId, 'refuser', { visitor_id: visitorId, motif }); }
  async assignBadge(operationId: string, visitorId: string, code: string): Promise<{ badge_code: string }> {
    const printed = await findBadge(this.list, code, sha256Hex);
    if (!printed) throw new ApiError('badge_inconnu', 'Badge inconnu : vérifiez le code imprimé ou prenez un autre badge.', 'Saisissez le code imprimé ou prenez un autre badge.', 422);
    // Seul le code imprimé part dans la file : le contenu du QR n'est conservé nulle part sur la tablette.
    return { badge_code: (await this.enqueue(operationId, 'badge', { visitor_id: visitorId, code: printed })).badge_code as string };
  }
  async enter(operationId: string, visitorId: string, durationMs: number): Promise<{ zones: string[] }> {
    return { zones: (await this.enqueue(operationId, 'entree', { visitor_id: visitorId, duration_ms: Math.max(0, Math.round(durationMs)) })).zones ?? [] };
  }
  async createUnlisted(operationId: string, input: UnlistedInput): Promise<{ visitor_id: string }> {
    const existing = await this.store.getOp(operationId);
    const id = existing ? String(existing.op.payload.visitor_id) : crypto.randomUUID();
    await this.enqueue(operationId, 'non_liste', { visitor_id: id, ...input });
    return { visitor_id: id };
  }

  async checkpoints(): Promise<Checkpoint[]> { return checkpointsOf(this.list); }

  async groups(): Promise<GroupSummary[]> { return groupSummaries(this.list); }
  async groupView(groupId: string): Promise<GroupView> { return groupView(this.list, groupId); }
  async groupCheck(operationId: string, groupId: string, visitorId: string, identityChecked: boolean): Promise<void> { await this.enqueue(operationId, 'groupe_controle', { group_id: groupId, visitor_id: visitorId, identity_checked: identityChecked }); }
  async groupDeliver(operationId: string, groupId: string, visitorId: string, code: string): Promise<{ badge_code: string }> {
    const printed = await resolveBadge(this.list, code);
    if (!printed) throw new ApiError('badge_inconnu', 'Badge inconnu : vérifiez le code imprimé ou prenez un autre badge.', 'Saisissez le code imprimé ou prenez un autre badge.', 422);
    return { badge_code: (await this.enqueue(operationId, 'groupe_badge', { group_id: groupId, visitor_id: visitorId, code: printed })).badge_code as string };
  }
  async groupDepart(operationId: string, groupId: string): Promise<{ boarded: number; absent: number }> {
    const r = await this.enqueue(operationId, 'groupe_depart', { group_id: groupId });
    return { boarded: r.boarded ?? 0, absent: r.absent ?? 0 };
  }
  async arrive(operationId: string, code: string): Promise<ArrivalResult> {
    const printed = await resolveBadge(this.list, code);
    if (!printed) throw new ApiError('badge_inconnu', 'Badge inconnu : vérifiez le code ou scannez à nouveau.', null, 422);
    return (await this.enqueue(operationId, 'groupe_arrivee', { code: printed })).arrival as ArrivalResult;
  }
  async arriveFinish(operationId: string, groupId: string): Promise<{ arrived: number; missing: number }> {
    const r = await this.enqueue(operationId, 'groupe_arrivee_fin', { group_id: groupId });
    return { arrived: r.arrived ?? 0, missing: r.missing ?? 0 };
  }

  private get online(): boolean { return typeof navigator === 'undefined' || navigator.onLine; }
  private async knownAt(): Promise<string | null> { return (await getMeta(this.vault.db, 'lastSyncAt')) ?? null; }

  /** Envoie d'abord ce qui attend dans la file (le serveur doit connaître l'entrée avant le contrôle ou la sortie), sans bloquer plus de 2,5 s. */
  private async flushQueue(): Promise<void> {
    if (!this.sync || (await this.store.count('pending')) === 0) return;
    await Promise.race([this.sync.sync(), new Promise<void>(resolve => setTimeout(resolve, 2500))]);
  }

  /** Réponse du serveur, ou null si le réseau manque (la tablette répond alors seule). Un refus du serveur, lui, est une réponse. */
  private async remote<T>(path: string, body: object, operationId: string): Promise<T | null> {
    if (!this.sync || !this.online) return null;
    try {
      await this.flushQueue();
      return await this.sync.request<T>('POST', path, body, operationId, 4000);
    } catch (e) {
      if (e instanceof ApiError && (e.code === 'reseau' || e.code === 'session_expiree')) return null;
      throw e;
    }
  }

  async control(operationId: string, code: string, point: PointRef | null): Promise<ControlResult> {
    const server = await this.remote<ControlResult>('/poste/api/controle', { code, point }, operationId);
    if (server) return { ...server, source: 'serveur' };
    const printed = await resolveBadge(this.list, code);
    const result = controlLocal(this.list, printed, point, this.now(), await this.knownAt());
    // Seul le code imprimé part dans la file : le contenu du QR n'est conservé nulle part sur la tablette.
    await this.enqueue(operationId, 'controle', { code: printed ?? 'INCONNU', point, local_valid: result.valid });
    return result;
  }

  async exit(operationId: string, code: string, point: PointRef | null): Promise<ExitResult> {
    const server = await this.remote<ExitResult>('/poste/api/sortie', { code, point }, operationId);
    if (server) return { ...server, source: 'serveur' };
    const printed = await resolveBadge(this.list, code);
    const result = exitLocal(this.list, printed, point, await this.knownAt());
    if (result.ok) await this.enqueue(operationId, 'sortie', { code: printed, point });
    return result;
  }

  async presentCount(): Promise<number | null> {
    if (!this.sync || !this.online) return null;
    try { return (await this.sync.request<{ count: number }>('GET', '/poste/api/presents', undefined, undefined, 3000)).count; } catch { return null; }
  }
}
