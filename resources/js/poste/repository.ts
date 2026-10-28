import type { ArrivalResult, GroupSummary, GroupView } from './local/group';
import { ApiError, type Avis, type Checkpoint, type ControlResult, type DeskContext, type DeskSummary, type ExitResult, type Fiche, type Poste, type PointRef, type SearchResult, type UnlistedInput } from './types';

/**
 * Tous les accès aux données du poste passent par cette interface (plan, section 4.1). Le lot L5 n'a qu'une implémentation (appels JSON) ;
 * le lot L6 ajoutera l'implémentation locale (IndexedDB) sans toucher aux écrans. Chaque écriture reçoit un identifiant d'opération
 * créé par la tablette : le rejouer ne crée rien de plus côté serveur.
 */
export interface PosteRepository {
  refreshHost?(visitorId: string): Promise<Fiche>;
  /** Chiffres du poste pour le panneau de l'accueil ; absent quand le poste travaille directement sur le serveur. */
  deskSummary?(): Promise<DeskSummary>;
  requestHostAdvice?(visitorId: string): Promise<void>;
  listPostes(): Promise<Poste[]>;
  context(): Promise<DeskContext>;
  search(query: string): Promise<SearchResult[]>;
  visitor(id: string): Promise<Fiche>;
  saveIdentity(operationId: string, visitorId: string, photo: Blob): Promise<void>;
  validate(operationId: string, visitorId: string, avis?: Avis): Promise<void>;
  refuse(operationId: string, visitorId: string, motif: string): Promise<void>;
  assignBadge(operationId: string, visitorId: string, code: string): Promise<{ badge_code: string }>;
  enter(operationId: string, visitorId: string, durationMs: number): Promise<{ zones: string[] }>;
  createUnlisted(operationId: string, input: UnlistedInput): Promise<{ visitor_id: string }>;
  /** Nombre d'opérations en attente d'envoi : toujours 0 tant que le mode hors ligne n'existe pas (lot L6). */
  pendingCount(): Promise<number>;
}

/**
 * Contrôle sur site et sortie (lot L7). En ligne, le serveur répond (BadgeService::controler) ; sans réseau, la tablette répond
 * d'après sa copie locale et met l'opération dans la file du lot L6. Chaque appel reçoit un identifiant d'opération.
 */
export interface SitePosteRepository extends PosteRepository {
  checkpoints(): Promise<Checkpoint[]>;
  control(operationId: string, code: string, point: PointRef | null): Promise<ControlResult>;
  exit(operationId: string, code: string, point: PointRef | null): Promise<ExitResult>;
  /** Nombre de personnes présentes selon le serveur, ou null sans réseau. */
  presentCount(): Promise<number | null>;
}

/**
 * Groupes de stagiaires (lot L9) : appel nominal, contrôle, remise des badges du lot du groupe, embarquement et arrivée du car.
 * Tout passe par la file locale du lot L6 : la même action rejouée ne change rien, avec ou sans réseau.
 */
export interface GroupPosteRepository extends SitePosteRepository {
  groups(): Promise<GroupSummary[]>;
  groupView(groupId: string): Promise<GroupView>;
  groupCheck(operationId: string, groupId: string, visitorId: string, identityChecked: boolean): Promise<void>;
  groupDeliver(operationId: string, groupId: string, visitorId: string, code: string): Promise<{ badge_code: string }>;
  groupDepart(operationId: string, groupId: string): Promise<{ boarded: number; absent: number }>;
  arrive(operationId: string, code: string): Promise<ArrivalResult>;
  arriveFinish(operationId: string, groupId: string): Promise<{ arrived: number; missing: number }>;
}

export function cookie(name: string): string {
  const match = document.cookie.split('; ').find(c => c.startsWith(name + '='));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : '';
}

export class HttpPosteRepository implements PosteRepository {
  constructor(private readonly posteId: () => string | null) {}

  private async call<T>(method: 'GET' | 'POST', url: string, body?: object | FormData, operationId?: string): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') };
    const poste = this.posteId();
    if (poste) headers['X-Poste-Id'] = poste;
    if (operationId) headers['X-Operation-Id'] = operationId;
    if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    let response: Response;
    try {
      response = await fetch(url, { method, headers, body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined, credentials: 'same-origin', redirect: 'manual' });
    } catch {
      throw new ApiError('reseau', 'Pas de connexion au serveur. Vérifiez le réseau puis réessayez.', 'Réessayez : rien n’est enregistré en double.');
    }
    if (response.type === 'opaqueredirect' || response.status === 401 || response.status === 419) {
      throw new ApiError('session_expiree', 'Votre session a expiré : reconnectez-vous.', 'Rechargez la page pour vous reconnecter.', response.status || 401);
    }
    const data = await response.json().catch(() => null) as (T & { code?: string; message?: string; action?: string | null }) | null;
    if (!response.ok) {
      const first = data && typeof data === 'object' && 'errors' in data ? Object.values((data as { errors: Record<string, string[]> }).errors)[0]?.[0] : undefined;
      throw new ApiError(data?.code ?? 'erreur', data?.message ?? first ?? 'Une erreur est survenue. Réessayez ou appelez le superviseur.', data?.action ?? null, response.status);
    }
    return data as T;
  }

  refreshHost(visitorId:string):Promise<Fiche>{return this.visitor(visitorId);}
  async requestHostAdvice(visitorId:string):Promise<void>{await this.call('POST','/poste/api/avis-hote',{visitor_id:visitorId});}
  async listPostes(): Promise<Poste[]> { return (await this.call<{ postes: Poste[] }>('GET', '/poste/api/postes')).postes; }
  context(): Promise<DeskContext> { return this.call<DeskContext>('GET', '/poste/api/contexte'); }
  async search(query: string): Promise<SearchResult[]> { return (await this.call<{ results: SearchResult[] }>('GET', '/poste/api/recherche?q=' + encodeURIComponent(query))).results; }
  visitor(id: string): Promise<Fiche> { return this.call<Fiche>('GET', '/poste/api/visiteurs/' + encodeURIComponent(id)); }
  async saveIdentity(operationId: string, visitorId: string, photo: Blob): Promise<void> {
    const form = new FormData();
    form.append('visitor_id', visitorId); form.append('identity_checked', '1'); form.append('photo', photo, 'photo.jpg');
    await this.call('POST', '/poste/api/identite', form, operationId);
  }
  async validate(operationId: string, visitorId: string, avis?: Avis): Promise<void> { await this.call('POST', '/poste/api/valider', { visitor_id: visitorId, avis: avis ?? null }, operationId); }
  async refuse(operationId: string, visitorId: string, motif: string): Promise<void> { await this.call('POST', '/poste/api/refuser', { visitor_id: visitorId, motif }, operationId); }
  assignBadge(operationId: string, visitorId: string, code: string): Promise<{ badge_code: string }> { return this.call('POST', '/poste/api/badge', { visitor_id: visitorId, code }, operationId); }
  enter(operationId: string, visitorId: string, durationMs: number): Promise<{ zones: string[] }> { return this.call('POST', '/poste/api/entree', { visitor_id: visitorId, duration_ms: Math.max(0, Math.round(durationMs)) }, operationId); }
  createUnlisted(operationId: string, input: UnlistedInput): Promise<{ visitor_id: string }> { return this.call('POST', '/poste/api/non-listes', input, operationId); }
  async pendingCount(): Promise<number> { return 0; }
}

/** Identifiants d'opération : conservés jusqu'à la réponse définitive, pour qu'un nouvel essai après une coupure rejoue la même opération. */
export class OperationIds {
  private readonly ids = new Map<string, string>();
  get(key: string): string {
    let id = this.ids.get(key);
    if (!id) { id = crypto.randomUUID(); this.ids.set(key, id); }
    return id;
  }
  done(key: string): void { this.ids.delete(key); }
}
