import { ApiError } from '../types';
import { cookie } from '../repository';
import type { LocalOp } from './apply';
import { PosteDb, getMeta, setMeta } from './db';
import { fromB64 } from './crypto';
import { LocalPosteRepository } from './local';
import type { Snapshot } from './list';
import { PosteStore } from './store';
import { SyncManager, type SyncState } from './sync';
import { PosteVault, LeaseError, PinError, type Lease } from './vault';

export const WIPE_AFTER_EVENT_MS = 24 * 3_600_000;
export type WipeReason = 'echecs_pin' | 'deconnexion' | 'fin_evenement' | 'manuel' | 'poste_supprime' | 'compte_change';
type Enrolled = { device_token: string; device_secret: string; poste: { id: string; name: string; station: string | null; event_id: string | null; event_name: string | null } };

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
      onRevoked: async reason => {
        await this.wipe(reason === 'appareil_non_enrole' ? 'poste_supprime' : 'deconnexion', reason === 'appareil_revoque');
      },
    });
    this.fetcher = options.fetcher ?? ((...args) => fetch(...args));
  }
  private readonly fetcher: typeof fetch;

  state(): ReturnType<PosteVault['agentState']> { return this.vault.agentState(); }

  private async afterSnapshot(snapshot: Snapshot): Promise<void> {
    await setMeta(this.db, 'settings', snapshot.settings);
    await setMeta(this.db, 'eventEndsAt', snapshot.event?.ends_at ?? null);
    await setMeta(this.db, 'posteName', snapshot.poste.station || snapshot.poste.name);
    const offset = (await getMeta(this.db, 'clockOffsetMs')) ?? 0;
    if (this.repo) { this.repo.setClockOffset(offset); await this.repo.replaceSnapshot(snapshot); }
  }

  async enrol(code: string, agent: { id: string; name: string }): Promise<void> {
    // S02 / C3 : un nouvel enrôlement ne remplace jamais un coffre qui garde des opérations non envoyées.
    if ((await this.store.count('pending')) > 0) throw new ApiError('operations_en_attente', 'Des opérations non envoyées restent sur cette tablette : elles doivent être synchronisées par leur auteur avant un nouvel enrôlement.', null, 409);
    const response = await this.fetcher('/poste/api/enroler', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, body: JSON.stringify({ code }), credentials: 'same-origin' }).catch(() => { throw new ApiError('reseau', 'Pas de connexion au serveur : l’enrôlement demande le réseau.'); });
    const data = await response.json().catch(() => null) as (Enrolled & { message?: string; code?: string; errors?: Record<string, string[]> }) | null;
    if (!response.ok || !data?.device_token) throw new ApiError(data?.code ?? 'erreur', data?.message ?? Object.values(data?.errors ?? {})[0]?.[0] ?? 'Enrôlement impossible. Vérifiez le code ou demandez-en un nouveau.', null, response.status);
    const lease = await this.fetchLease(data.poste.id);
    await this.vault.enrolLease({ token: data.device_token, lease, agent, poste: { id: data.poste.id, name: data.poste.station || data.poste.name }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    this.sync.resetAfterEnrollment();
    await this.sync.sync();
    if (!(await this.db.chunks.get('list:head'))) {
      await this.vault.wipe();
      throw new ApiError('liste_indisponible', 'La liste des visiteurs n’a pas pu être copiée sur la tablette : réessayez avec une bonne connexion.');
    }
    await this.open();
  }

  /** Bail de poste demandé au serveur (session de l'agent exigée) : secret propre à cet appareil ET à ce compte, remis sur HTTPS, jamais conservé en clair. */
  async fetchLease(posteId: string): Promise<Lease> {
    let response: Response;
    try { response = await this.fetcher(`/poste/api/coffre/bail?poste=${encodeURIComponent(posteId)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }); }
    catch { throw new ApiError('reseau', 'Le réseau est indisponible : la tablette ne peut pas s’ouvrir sans lui pour le moment. Rien n’est perdu.'); }
    const data = await response.json().catch(() => null) as (Lease & { message?: string; code?: string }) | null;
    if (!response.ok || !data?.secret) throw new ApiError(data?.code ?? 'bail_refuse', data?.message ?? 'Le serveur n’a pas ouvert cette tablette : reconnectez-vous ou contactez l’administrateur.', null, response.status);
    return { secret: data.secret, expires_at: data.expires_at };
  }

  /**
   * Ouvre le coffre SANS saisie : avec le bail conservé s'il est encore valable, sinon avec un bail demandé au serveur (en ligne, session valide).
   * Résultats : « vide » (rien d'enrôlé), « migration » (ancien coffre par PIN, à migrer une fois), « ouvert », « ferme » (réseau ou connexion nécessaire, `reason` dit pourquoi).
   * Le coffre d'un autre compte n'est jamais ouvert ni effacé tant que des opérations attendent leur auteur (S02 / C3).
   */
  async resume(expectedAgentId?: string): Promise<{ state: 'vide' | 'migration' | 'ouvert' | 'ferme'; reason?: string }> {
    const current = await this.vault.agentState();
    if (current === 'vide') return { state: 'vide' };
    if (current === 'migration') return { state: 'migration' };
    if (current === 'deverrouille') { await this.afterUnlock(expectedAgentId); return { state: this.vault.unlocked ? 'ouvert' : 'vide' }; }
    const posteId = await getMeta(this.db, 'posteId');
    const stored = await this.vault.storedLease();
    try {
      if (stored) await this.vault.openWithLease(stored.secret);
      else throw new LeaseError('bail_absent', 'Aucun bail valable sur cette tablette.');
    } catch (first) {
      // Bail absent, échu ou remplacé : un nouveau bail demandé au serveur (en ligne, session valide) ; sinon la tablette attend le réseau.
      if (!posteId) return { state: 'ferme', reason: 'Cette tablette n’est pas identifiée : réenrôlez-la.' };
      let lease: Lease;
      try { lease = await this.fetchLease(posteId); }
      catch (e) { return { state: 'ferme', reason: e instanceof ApiError ? e.message : 'La tablette ne peut pas s’ouvrir pour le moment.' }; }
      try { await this.vault.openWithLease(fromB64Lease(lease)); }
      catch {
        // Le bail du compte connecté n'ouvre pas ce coffre : la tablette appartient à un autre agent.
        const pending = await this.store.count('pending');
        if (pending > 0) return { state: 'ferme', reason: `Cette tablette garde ${pending} opération${pending > 1 ? 's' : ''} non envoyée${pending > 1 ? 's' : ''}, saisie${pending > 1 ? 's' : ''} par un autre agent : reconnectez-vous avec son compte pour les envoyer. Rien n’est perdu.` };
        await this.wipe('compte_change', false);
        return { state: 'vide' };
      }
      await this.vault.saveLease(lease);
      void first;
    }
    await this.afterUnlock(expectedAgentId);
    return { state: this.vault.unlocked ? 'ouvert' : 'vide' };
  }

  /** Migration unique d'un ancien coffre par PIN : le PIN est saisi une dernière fois ; ni les données ni la file d'opérations ne sont touchées. */
  async migrate(pin: string, expectedAgentId?: string): Promise<void> {
    const posteId = await getMeta(this.db, 'posteId');
    if (!posteId) throw new PinError('vide', 'Cette tablette n’est pas enrôlée.');
    const lease = await this.fetchLease(posteId);
    try { await this.vault.migrate(pin, lease); }
    catch (e) { if (e instanceof Error && 'code' in e && (e as { code: string }).code === 'efface') this.handlers.onWiped('echecs_pin'); throw e; }
    await this.afterUnlock(expectedAgentId);
  }

  /** Après ouverture : fin d'activité, compte différent, ouverture du dépôt local et synchronisation. */
  private async afterUnlock(expectedAgentId?: string): Promise<void> {
    if (await this.expired()) {
      this.vault.lock();
      await this.wipe('fin_evenement', true);
      return;
    }
    // Après un reset, un compte recréé avec le même email possède un nouvel UUID.
    // Ne jamais utiliser l'ancien coffre avec l'identité d'une nouvelle session.
    if (expectedAgentId) {
      const formerAgent = await this.vault.getSecret<{ id: string; name: string }>('profile');
      if (!formerAgent || formerAgent.id !== expectedAgentId) {
        // S02 / C3 : les opérations en attente appartiennent à l'agent qui les a saisies. Un autre compte ne les efface pas, ne les envoie pas et ne les reprend pas :
        // le coffre reste fermé, intact, jusqu'à ce que leur auteur se reconnecte et les synchronise. Sans opération en attente, l'ancien comportement s'applique.
        const pending = await this.store.count('pending');
        if (pending > 0) {
          this.lock();
          throw new ApiError('operations_en_attente_autre_agent', `Cette tablette garde ${pending} opération${pending > 1 ? 's' : ''} non envoyée${pending > 1 ? 's' : ''}, saisie${pending > 1 ? 's' : ''} par ${formerAgent?.name ?? 'un autre agent'} : reconnectez-vous avec son compte pour les envoyer. Rien n’est perdu.`, null, 409);
        }
        await this.wipe('compte_change', false);
        return;
      }
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

  /** Renouvelle le bail en silence (en ligne) quand il approche de son échéance : la tablette ne se ferme jamais pendant le travail. Échec sans effet. */
  async renewLease(): Promise<void> {
    if (!this.vault.unlocked || !navigator.onLine) return;
    const posteId = await getMeta(this.db, 'posteId');
    const stored = await this.vault.storedLease();
    if (!posteId) return;
    // Renouvelé dès que la moitié du temps est passée (ou si le bail a disparu) ; la durée vient du serveur.
    if (stored && stored.expiresAt - Date.now() > 3_600_000 * 3) return;
    try { await this.vault.saveLease(await this.fetchLease(posteId)); } catch { /* session échue ou réseau coupé : le bail courant continue jusqu'à son échéance */ }
  }

  /** Ferme la tablette jusqu'à une nouvelle connexion en ligne (déconnexion, page de connexion). Les données et la file restent chiffrées. */
  async closeSession(): Promise<void> {
    this.sync.stop(); this.repo = null; this.profile = null;
    await this.vault.dropLease();
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

/** Bail du serveur en octets. */
function fromB64Lease(lease: Lease): Uint8Array { return fromB64(lease.secret); }
