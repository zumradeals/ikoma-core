export type Category = { id: string; name: string; color: string; priority_circuit: boolean; visitor_count?: number };
export type Poste = { id: string; name: string; station: string | null; event_id: string; event_name: string };
export type DeskContext = { poste: { id: string; name: string; station: string | null; event_id: string }; event: { id: string; name: string; starts_at?: string; ends_at?: string; site?: string | null }; visitor_count?: number; present_count?: number; absent_count?: number; hosts: { id: string; name: string; service: string | null }[]; categories: Category[] };
/** Situation d'accès d'une personne dans la liste de l'accueil : « Autorisé » seulement dans la période, sans refus, avant l'entrée. */
export type Access = 'autorise' | 'hors_periode' | 'refuse' | 'present' | 'sorti';
export type SearchResult = { id: string; full_name: string; organisation: string | null; host: string | null; homonyms: number; category: Category; job_title?: string | null; access?: Access; has_portrait?: boolean };
export type IdentityDocument = { type: string; number?: string; photo: Blob };
export const documentLabels: Record<string, string> = { cni: 'Carte nationale d’identité', passeport: 'Passeport', permis: 'Permis de conduire', carte_militaire: 'Carte militaire', carte_professionnelle: 'Carte professionnelle', autre: 'Autre pièce' };

export type Fiche = {
  job_title?: string | null; visit_purpose?: string | null;
  identity_document?: boolean;
  id: string; full_name: string; organisation: string | null; host: string | null; category: Category; zones: string[]; accueil_limite: boolean; source: string; status: string;
  host_advice?: {state:string|null};
  host_validation: 'en_attente' | 'accepte' | 'refuse' | null; valid_from: string; valid_until: string; hors_periode: boolean;
  identity_done: boolean; photo_done: boolean; validated: boolean; badge_code: string | null; access_note?: string | null; a_rebadger?: boolean;
};
export type Checkpoint = { type: 'zone' | 'poste'; id: string; name: string };
export type PointRef = { type: 'zone' | 'poste'; id: string };
/** Verdict d'un contrôle : `source` dit qui l'a rendu ; `known_at` n'existe que pour une réponse de la tablette (situation connue à cette heure). */
export type ControlResult = {
  valid: boolean; reason: string | null; message: string; badge_code: string | null; zone_ok: boolean | null; point: string; person: { name: string } | null; organisation: string | null;
  category: Category | null; zones: string[]; source: 'serveur' | 'local'; known_at?: string | null; direction?: PassageDirection | null;
  permanent?: { identifier: string; entered?: boolean } | null;
};
export type ExitResult = { ok: boolean; reason?: string; message?: string; badge_code: string | null; badge_status?: string; person?: { name: string }; point?: string; presents?: number | null; warning?: string | null; source: 'serveur' | 'local'; known_at?: string | null; permanent?: { identifier: string; card_kept?: boolean } | null };
/** Restitution d'un badge (en ligne uniquement) : vérification sans écriture, puis restitution confirmée. */
export type GiveBackResult = { ok: boolean; reason?: string; message?: string; badge_code: string | null; badge_status?: string; person?: { name: string }; source: 'serveur' };
export type Avis = 'hote' | 'superviseur';
/** Chiffres du poste d'accueil, calculés sur la copie de la tablette (donc disponibles sans réseau). */
export type DeskSummary = {
  accueillis: number; refuses: number; badges: { restants: number; total: number }; waiting: { id: string; name: string }[];
  pending: { type: string; count: number }[]; lowBadges: boolean; offlineCallMinutes: number; recent: { kind: 'badge' | 'refus'; name: string; at: string }[]; asOf: string | null;
};
export type UnlistedInput = { name: string; organisation: string; host_id: string; category_id: string };

/** Erreur de l'API du poste : `code` est stable, `message` et `action` sont en français pour l'agent. */
export class ApiError extends Error {
  constructor(public readonly code: string, message: string, public readonly action: string | null = null, public readonly status = 0) { super(message); }
}

/** Véhicule annoncé ou présent retrouvé par sa plaque au poste (en ligne uniquement). Un véhicule présent ne vaut jamais présence de la personne. */
export type VehicleResult = { id: string; plate: string; kind: string; status: 'annonce' | 'present'; visitor_id: string; full_name: string; visitor_status: string };
export type VehicleMove = { ok: boolean; already?: boolean; plate?: string; status?: string };

/** Visite annoncée pas encore arrivée, vue du poste d'accueil (en ligne uniquement). */
export type ExpectedVisit = { id: string; full_name: string; organisation: string | null; expected_at: string; state: 'attendu' | 'en_retard'; category: Category; host: string | null; plates: string | null };
/** Refus et incidents pertinents pour le poste : contrôles refusés à ce poste et, à l'accueil, accès refusés de l'activité. */
export type IncidentControl = { id: string; occurred_at: string; checkpoint: string | null; full_name: string | null; badge_code: string | null; reason: string | null };
export type IncidentRefusal = { id: string; reason: string; refused_at: string; decision: string | null; visitor_id: string; full_name: string };
export type IncidentsView = { controls: IncidentControl[]; refusals: IncidentRefusal[]; can_see_refusals: boolean };

/** Sens d'un passage contrôlé (S10) : choisi avant le scan à la soute ; inscrit dans le mouvement « contrôle » du journal de supervision. */
export type PassageDirection = 'entree' | 'sortie';
