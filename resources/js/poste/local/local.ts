import { ApiError, type Avis, type DeskContext, type Fiche, type Poste, type SearchResult, type UnlistedInput } from '../types';
import type { PosteRepository } from '../repository';
import { applyOp, ficheOf, findBadge, searchOf, type LocalOp, type OpType } from './apply';
import { sha256Hex } from './crypto';
import { getMeta } from './db';
import { LocalList, type Snapshot } from './list';
import type { PosteStore } from './store';
import type { PosteVault } from './vault';

type Profile = { id: string; name: string };

/**
 * Implémentation locale du dépôt de données du poste : tout est lu dans la copie chiffrée de la tablette et toute écriture
 * est d'abord enregistrée dans la file (IndexedDB) avant d'être envoyée, réseau ou non.
 */
export class LocalPosteRepository implements PosteRepository {
  list: LocalList;
  constructor(snapshot: Snapshot, private readonly vault: PosteVault, private readonly store: PosteStore, private readonly profile: Profile, private readonly onQueued: () => void, private clockOffset = 0) {
    this.list = new LocalList(snapshot);
  }

  static async open(vault: PosteVault, store: PosteStore, onQueued: () => void): Promise<LocalPosteRepository | null> {
    const snapshot = await store.loadSnapshot();
    const profile = await vault.getSecret<Profile>('profile');
    if (!snapshot || !profile) return null;
    const repo = new LocalPosteRepository(snapshot, vault, store, profile, onQueued, (await getMeta(vault.db, 'clockOffsetMs')) ?? 0);
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

  private async enqueue(operationId: string, type: OpType, payload: Record<string, unknown>, photo?: Blob): Promise<{ badge_code?: string; zones?: string[]; visitor_id?: string }> {
    const existing = await this.store.getOp(operationId);
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
}
