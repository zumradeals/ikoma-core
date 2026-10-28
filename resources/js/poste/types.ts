export type Category = { id: string; name: string; color: string; priority_circuit: boolean };
export type Poste = { id: string; name: string; station: string | null; event_id: string; event_name: string };
export type DeskContext = { poste: { id: string; name: string; station: string | null; event_id: string }; event: { id: string; name: string }; hosts: { id: string; name: string; service: string | null }[]; categories: Category[] };
export type SearchResult = { id: string; full_name: string; organisation: string | null; host: string | null; homonyms: number; category: Category };
export type Fiche = {
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
  category: Category | null; zones: string[]; source: 'serveur' | 'local'; known_at?: string | null;
};
export type ExitResult = { ok: boolean; reason?: string; message?: string; badge_code: string | null; badge_status?: string; person?: { name: string }; point?: string; presents?: number | null; warning?: string | null; source: 'serveur' | 'local'; known_at?: string | null };
export type Avis = 'hote' | 'superviseur';
export type UnlistedInput = { name: string; organisation: string; host_id: string; category_id: string };

/** Erreur de l'API du poste : `code` est stable, `message` et `action` sont en français pour l'agent. */
export class ApiError extends Error {
  constructor(public readonly code: string, message: string, public readonly action: string | null = null, public readonly status = 0) { super(message); }
}
