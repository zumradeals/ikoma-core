import { ApiError, type ActivityChoice, type DeskSummary, type Avis, type Checkpoint, type ControlResult, type DeskContext, type ExitResult, type GiveBackResult, type Fiche, type IdentityDocument, type PointRef, type Poste, type SearchResult, type UnlistedInput, type PassageDirection, type ExpectedVisit, type IncidentsView, type VehicleMove, type VehicleResult } from '../types';
import type { GroupPosteRepository } from '../repository';
import { posteGate, OP_POLICY } from './capabilities';
import { applyOp, type ApplyResult, ficheOf, findBadge, searchOf, type LocalOp, type OpType } from './apply';
import { sha256Hex } from './crypto';
import { getMeta } from './db';
import { applyGroupOp, groupSummaries, groupView, type ArrivalResult, type BoardResult, type GroupSummary, type GroupView } from './group';
import { LocalList, type Snapshot } from './list';
import { PERMANENT_PREFIX, searchPermanentDirectory, type PermanentResult } from './permanent';
import { checkpointsOf, controlLocal, exitLocal, refusedControl, refusedExit, resolveBadge } from './site';
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
    await this.exclusive(async () => {
      this.list = new LocalList(snapshot);
      await this.replayPending();
    });
  }

  /** Une écriture (règle appliquée à la copie puis enregistrement dans la file) et un remplacement de la copie ne se chevauchent jamais : sinon l'écriture pouvait disparaître de l'écran jusqu'à la synchronisation suivante. */
  private tail: Promise<unknown> = Promise.resolve();
  private exclusive<T>(job: () => Promise<T>): Promise<T> {
    const run = this.tail.then(job, job);
    this.tail = run.catch(() => undefined);
    return run;
  }

  private async replayPending(): Promise<void> {
    for (const op of await this.store.ops('pending')) {
      try { applyOp(this.list, op, Date.parse(op.client_time)); } catch { /* la règle sera rejugée par le serveur : il mettra l'opération en quarantaine */ }
    }
  }

  async refreshHost(visitorId:string):Promise<Fiche>{
    if(this.sync && this.online){
      await this.sync.sync();
      // La copie chiffrée reste la source de travail ; l’avis est une information en ligne de la fiche courante.
      const fresh=await this.sync.request<Fiche>('GET','/poste/api/visiteurs/'+encodeURIComponent(visitorId),undefined,undefined,3000);
      return {...ficheOf(this.list,visitorId,this.now()),host_validation:fresh.host_validation,host_advice:fresh.host_advice};
    }
    return this.visitor(visitorId);
  }
  async requestHostAdvice(visitorId:string):Promise<void>{
    if(!this.sync || !this.online)throw new ApiError('reseau','Reconnectez-vous pour demander l’avis de l’hôte.');
    await this.sync.sync();await this.sync.request('POST','/poste/api/avis-hote',{visitor_id:visitorId},undefined,3000);
  }
  /** Portrait de la personne, en ligne seulement : hors ligne ou sans portrait, l'accueil affiche l'initiale. */
  async portrait(visitorId: string): Promise<Blob | null> { return this.sync && this.online ? this.sync.image('/poste/api/visiteurs/' + encodeURIComponent(visitorId) + '/portrait') : null; }
  /** Activités autorisées pour cette tablette, lues sur le serveur : hors ligne, la liste reste celle de l'activité en cours. */
  async activities(): Promise<ActivityChoice[]> {
    if (!this.sync || !this.online) throw new ApiError('reseau', 'Changer d’activité demande le réseau.');
    return (await this.sync.request<{ activites: ActivityChoice[] }>('GET', '/poste/api/activites', undefined, undefined, 4000)).activites;
  }

  /**
   * Change l'activité du jour. Toute la file est d'abord envoyée : l'ancienne activité n'est jamais changée sous des opérations en attente ;
   * ensuite la synchronisation recharge la copie de la nouvelle activité (la version de la liste dépend de l'activité).
   */
  async switchActivity(eventId: string): Promise<void> {
    if (!this.sync || !this.online) throw new ApiError('reseau', 'Changer d’activité demande le réseau : reconnectez la tablette.');
    await this.sync.sync();
    if ((await this.store.count('pending')) > 0) throw new ApiError('operations_en_attente', 'Des opérations ne sont pas encore envoyées : attendez la fin de l’envoi avant de changer d’activité.');
    await this.sync.request('POST', '/poste/api/activite', { event_id: eventId }, undefined, 6000);
    await this.sync.sync();
  }

  async listPostes(): Promise<Poste[]> {
    const s = this.list.snapshot;
    return [{ id: s.poste.id, name: s.poste.name, station: s.poste.station, event_id: s.poste.event_id, event_name: s.event.name }];
  }

  async context(): Promise<DeskContext> {
    const s = this.list.snapshot;
    return { poste: s.poste, event: { id: s.event.id, name: s.event.name, starts_at: s.event.starts_at, ends_at: s.event.ends_at, site: s.event.site ?? null }, visitor_count: this.list.visitors.size,
      present_count: [...this.list.visitors.values()].filter(v => v.status === 'present').length, absent_count: [...this.list.visitors.values()].filter(v => v.status === 'attendu').length, hosts: s.hosts, categories: s.categories.map(c => ({ ...c, visitor_count: [...this.list.visitors.values()].filter(v => v.category_id === c.id).length })) };
  }

  async search(query: string, categoryId: string | null = null): Promise<SearchResult[]> { return searchOf(this.list, query, this.now(), categoryId); }
  /** Recherche d'une personne permanente (lecture seule, copie locale). */
  async searchPermanent(query: string): Promise<PermanentResult[]> { return searchPermanentDirectory(this.list.snapshot.permanent, this.list.snapshot.zones, query, Date.now()); }
  async visitor(id: string): Promise<Fiche> { return ficheOf(this.list, id, this.now()); }
  async pendingCount(): Promise<number> { return this.store.count('pending'); }

  /** Chiffres du panneau de l'accueil : la liste arrêtée à la dernière synchronisation, plus tout ce que cette tablette a fait depuis. */
  async deskSummary(): Promise<DeskSummary> {
    const { counts, pending, recent } = await this.store.deskOps(['badge', 'refuser'], 3);
    const mine = [...this.list.badges.values()].filter(b => b.mine && !b.group_id);
    const left = mine.filter(b => b.status === 'Disponible').length;
    const thresholds = this.list.snapshot.settings;
    const waiting = [...this.list.visitors.values()].filter(v => v.source === 'non listé' && v.host_validation === 'en_attente' && !['refuse', 'present', 'sorti'].includes(v.status));
    return {
      accueillis: counts.entree ?? 0, refuses: counts.refuser ?? 0,
      badges: { restants: left, total: mine.length }, lowBadges: mine.length > 0 && left <= Math.max(thresholds.low_badges_min ?? 5, Math.ceil(mine.length * (thresholds.low_badges_percent ?? 10) / 100)), offlineCallMinutes: thresholds.offline_call_minutes ?? 15,
      waiting: waiting.map(v => ({ id: v.id, name: v.full_name })),
      pending: Object.entries(pending).map(([type, count]) => ({ type, count })),
      recent: recent.map(op => ({ kind: op.type === 'refuser' ? 'refus' as const : 'badge' as const, name: this.list.visitors.get(String(op.payload.visitor_id))?.full_name ?? 'Visiteur inconnu', at: op.client_time })),
      asOf: (await getMeta(this.vault.db, 'lastSyncAt')) ?? null,
    };
  }

  private enqueue(operationId: string, type: OpType, payload: Record<string, unknown>, photo?: Blob, documentPhoto?: Blob): Promise<ApplyResult> {
    return this.exclusive(() => this.enqueueNow(operationId, type, payload, photo, documentPhoto));
  }

  private async enqueueNow(operationId: string, type: OpType, payload: Record<string, unknown>, photo?: Blob, documentPhoto?: Blob): Promise<ApplyResult> {
    const existing = await this.store.getOp(operationId);
    if (existing && type.startsWith('groupe_')) return applyGroupOp(this.list, type, existing.op.payload); // toutes les opérations de groupe sont sans effet quand elles sont rejouées
    if (existing) { // même opération rejouée (coupure, double clic) : rien de plus n'est enregistré
      const v = this.list.visitors.get(String(existing.op.payload.visitor_id));
      return { badge_code: String(existing.op.payload.code ?? v?.badge_code ?? ''), zones: v ? this.list.zonesFor(v) : [], visitor_id: String(existing.op.payload.visitor_id ?? '') };
    }
    // S02 : rien n'est décidé ni mis en file par un poste sans capacité, non identifié, ou sans délai hors ligne approuvé pour une décision favorable.
    // Une décision favorable embarque l'âge de la copie (known_at) et si elle a été prise sans réseau (offline) : le serveur les revérifie au rejeu.
    const policy = OP_POLICY[type];
    if (policy) {
      const known = await this.knownAt();
      const offline = typeof payload.offline === 'boolean' ? payload.offline : !this.online;
      const permanent = type === 'controle' && String(payload.code ?? '').startsWith(PERMANENT_PREFIX);
      const refusal = posteGate(this.list, policy, this.now(), known, { offline, permanent });
      if (refusal) throw new ApiError(refusal.reason, refusal.message, null, 403);
      if (policy.favorable) payload = { ...payload, known_at: known, offline };
    }
    const visitorId = typeof payload.visitor_id === 'string' ? payload.visitor_id : null;
    const op: LocalOp = { id: operationId, seq: await this.store.nextSeq(), type, agent_id: this.profile.id, client_time: new Date(this.now()).toISOString(), visitor_rev: visitorId ? this.list.visitors.get(visitorId)?.rev ?? null : null, payload };
    const result = applyOp(this.list, op, this.now()); // refuse avant d'écrire dans la file si une règle l'interdit
    await this.store.addOp(op, photo, documentPhoto);
    this.onQueued();
    return result;
  }

  async saveIdentity(operationId: string, visitorId: string, photo: Blob, doc?: IdentityDocument): Promise<void> { await this.enqueue(operationId, 'identite', { visitor_id: visitorId, identity_checked: true, ...(doc ? { document_type: doc.type, document_number: doc.number ?? '', document_photo: true } : {}) }, photo, doc?.photo); }
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
  async groupBoard(operationId: string, groupId: string, code: string): Promise<BoardResult> {
    const printed = await resolveBadge(this.list, code);
    if (!printed) throw new ApiError('badge_inconnu', 'Badge inconnu : vérifiez le code ou scannez à nouveau.', null, 422);
    return (await this.enqueue(operationId, 'groupe_embarquer', { group_id: groupId, code: printed })).boarding as BoardResult;
  }
  async groupAbsent(operationId: string, groupId: string, visitorId: string, reason: string): Promise<void> { await this.enqueue(operationId, 'groupe_absent', { group_id: groupId, visitor_id: visitorId, reason }); }
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

  requiresDirection(): boolean { return this.list.snapshot.poste.direction_required ?? this.list.snapshot.poste.point_code === 'soute'; }

  async control(operationId: string, code: string, point: PointRef | null, direction?: PassageDirection): Promise<ControlResult> {
    if (this.requiresDirection() && !direction) throw new ApiError('sens_requis', 'Choisissez Entrée ou Sortie avant de scanner un badge.');
    const server = await this.remote<ControlResult>('/poste/api/controle', { code, point, direction }, operationId);
    if (server) return { ...server, source: 'serveur', direction: direction ?? null };
    const known = await this.knownAt();
    const printed = await resolveBadge(this.list, code);
    const refusal = posteGate(this.list, OP_POLICY.controle, this.now(), known, { offline: true, permanent: printed?.startsWith(PERMANENT_PREFIX) ?? false });
    if (refusal) return { ...refusedControl(this.list, point, refusal, known), direction: direction ?? null };
    const result = { ...controlLocal(this.list, printed, point, this.now(), known), direction: direction ?? null };
    // Seul le code imprimé part dans la file : le contenu du QR n'est conservé nulle part sur la tablette.
    await this.enqueue(operationId, 'controle', { code: printed ?? 'INCONNU', point, local_valid: result.valid, known_at: known, offline: true, direction });
    return result;
  }

  async exit(operationId: string, code: string, point: PointRef | null): Promise<ExitResult> {
    const server = await this.remote<ExitResult>('/poste/api/sortie', { code, point }, operationId);
    if (server) return { ...server, source: 'serveur' };
    const known = await this.knownAt();
    const refusal = posteGate(this.list, OP_POLICY.sortie, this.now(), known, { offline: true }); // la sortie n'accorde rien : capacité de poste seulement, jamais de péremption
    if (refusal) return refusedExit(this.list, point, refusal, known);
    const printed = await resolveBadge(this.list, code);
    const result = exitLocal(this.list, printed, point, known);
    if (result.ok) await this.enqueue(operationId, 'sortie', { code: printed, point });
    return result;
  }

  /** Restitution : en ligne uniquement. Aucune file hors ligne, aucune décision locale : le serveur seul tranche. */
  private requireOnline(): void {
    if (!this.sync || !this.online) throw new ApiError('hors_ligne', 'Cette opération nécessite le réseau. Reconnectez le poste, puis recommencez.');
  }

  async giveBackCheck(code: string): Promise<GiveBackResult> {
    this.requireOnline();
    return await this.sync!.request<GiveBackResult>('POST', '/poste/api/restitution/verifier', { code }, undefined, 4000);
  }

  async giveBack(operationId: string, code: string): Promise<GiveBackResult> {
    this.requireOnline();
    return await this.sync!.request<GiveBackResult>('POST', '/poste/api/restitution', { code }, operationId, 6000);
  }

  capabilities(): string[] { const caps = this.list.snapshot.poste.capabilities; return Array.isArray(caps) ? caps : []; }

  /** Consultations (visites attendues, refus et incidents) : en ligne uniquement, lecture seule. */
  async expectedVisits(): Promise<ExpectedVisit[]> {
    this.requireOnline();
    return (await this.sync!.request<{ results: ExpectedVisit[] }>('GET', '/poste/api/attendus', undefined, undefined, 4000)).results;
  }

  async incidents(): Promise<IncidentsView> {
    this.requireOnline();
    return await this.sync!.request<IncidentsView>('GET', '/poste/api/incidents', undefined, undefined, 4000);
  }

  /** Véhicules : en ligne uniquement, le serveur seul tranche (aucune file hors ligne, aucune décision locale). */
  async searchVehicles(query: string): Promise<VehicleResult[]> {
    this.requireOnline();
    return (await this.sync!.request<{ results: VehicleResult[] }>('GET', '/poste/api/vehicules?q=' + encodeURIComponent(query), undefined, undefined, 4000)).results;
  }

  async vehicleEnter(operationId: string, vehicleId: string): Promise<VehicleMove> {
    this.requireOnline();
    return await this.sync!.request<VehicleMove>('POST', '/poste/api/vehicules/entree', { vehicle_id: vehicleId }, operationId, 6000);
  }

  async vehicleExit(operationId: string, vehicleId: string): Promise<VehicleMove> {
    this.requireOnline();
    return await this.sync!.request<VehicleMove>('POST', '/poste/api/vehicules/sortie', { vehicle_id: vehicleId }, operationId, 6000);
  }

  trustMinutes(): number { return this.list.snapshot.permanent?.trust_minutes ?? 0; }

  async presentCount(): Promise<number | null> {
    if (!this.sync || !this.online) return null;
    try { return (await this.sync.request<{ count: number }>('GET', '/poste/api/presents', undefined, undefined, 3000)).count; } catch { return null; }
  }
}
