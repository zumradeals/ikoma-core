import type { PointRef } from '../types';

/** Copie de la situation des titulaires permanents pour cet événement (hachages de jetons uniquement, jamais de jeton). */
export type DirectoryEntry = { id: string; identifier: string; name: string; organisation: string | null; category: { id: string; name: string; color: string; priority_circuit: boolean } | null; card: string };

export type PermanentSnapshot = {
  /** Annuaire en lecture seule (recherche à l'accueil) : nom, identifiant, catégorie, état de la carte ; ni photo ni jeton. N'ouvre aucun accès. */
  directory?: DirectoryEntry[];
  trust_minutes: number;
  cards: { hash: string; status: string; holder_id: string; identifier: string }[];
  policies: { zone_id: string; permanent_allowed: boolean; offline_entry_allowed: boolean }[];
  holders: { id: string; category?: { id: string; name: string; color: string; priority_circuit: boolean } | null; identifier: string; name: string; organisation: string | null; status: string; starts_on: string | null; ends_on: string | null; entitlements: { zone_id: string; valid_from: string | null; valid_until: string | null }[] }[];
};

export type PermanentDecision = { allowed: boolean; reason: string | null; message: string; zones: string[]; holder: { identifier: string; name: string; organisation: string | null; category?: { id: string; name: string; color: string; priority_circuit: boolean } | null } | null };

export const PERMANENT_PREFIX = 'PC:';

const CARD_REFUSALS: Record<string, [string, string]> = {
  perdue: ['carte_perdue', 'Carte déclarée perdue : accès refusé. Prévenez le superviseur.'],
  non_activee: ['carte_non_activee', 'Carte pas encore remise : activez-la dans la fiche du titulaire.'],
  volee: ['carte_volee', 'Carte déclarée volée : accès refusé. Prévenez le superviseur.'],
};

/** Carte permanente connue de cette tablette (par empreinte du jeton scanné), quel que soit son statut. */
export function findPermanentCard(snap: PermanentSnapshot | undefined, hash: string): PermanentSnapshot['cards'][number] | undefined {
  return snap?.cards.find(c => c.hash === hash);
}

/**
 * Décision d'entrée hors ligne : MÊMES règles que PermanentAccess::decide puis offlineRefusal côté serveur, dans le même ordre, avec refus par défaut.
 * L'ancienneté de la confiance est jugée ICI, à chaque décision, avec l'heure courante : une session ouverte depuis longtemps ne la prolonge jamais.
 * Aucun privilège n'est jamais ajouté par la tablette : elle ne fait que refuser plus souvent que le serveur ne le ferait en ligne.
 */
export function decidePermanentOffline(snap: PermanentSnapshot, hash: string, point: PointRef | null, zones: { id: string; name: string }[], now: number, knownAt: string | null): PermanentDecision {
  const refuse = (reason: string, message: string, holder: PermanentDecision['holder'] = null): PermanentDecision => ({ allowed: false, reason, message, zones: [], holder });
  const card = findPermanentCard(snap, hash);
  if (!card) return refuse('inconnu', 'Badge inconnu.');
  if (card.status !== 'active') {
    const [reason, message] = CARD_REFUSALS[card.status] ?? ['carte_revoquee', 'Carte révoquée : accès refusé. Prévenez le superviseur.'];
    return refuse(reason, message);
  }
  const holder = snap.holders.find(h => h.id === card.holder_id);
  if (!holder) return refuse('habilitation_absente', 'Aucune habilitation valable pour cet événement : accès refusé.');
  const who = { identifier: holder.identifier, name: holder.name, organisation: holder.organisation, category: holder.category ?? null };
  if (holder.status !== 'actif') return refuse('titulaire_clos', 'Titulaire clos : accès refusé.', who);
  const today = new Date(now).toISOString().slice(0, 10);
  if (holder.starts_on && today < holder.starts_on.slice(0, 10)) return refuse('engagement_non_commence', 'L’engagement de ce titulaire n’a pas encore commencé.', who);
  if (holder.ends_on && today > holder.ends_on.slice(0, 10)) return refuse('engagement_termine', 'L’engagement de ce titulaire est terminé.', who);
  const scoped = holder.entitlements.filter(e => point?.type !== 'zone' || e.zone_id === point.id);
  const current = scoped.filter(e => (e.valid_from === null || Date.parse(e.valid_from) <= now) && (e.valid_until !== null && Date.parse(e.valid_until) > now));
  if (current.length === 0) {
    const expired = scoped.some(e => e.valid_until !== null && Date.parse(e.valid_until) <= now);
    return expired ? refuse('habilitation_expiree', 'Habilitation expirée : accès refusé.', who)
      : refuse('habilitation_absente', point?.type === 'zone' ? 'Aucune habilitation valable pour cette zone : accès refusé.' : 'Aucune habilitation valable pour cet événement : accès refusé.', who);
  }
  const policyOf = (zoneId: string) => snap.policies.find(p => p.zone_id === zoneId);
  const permitted = current.filter(e => policyOf(e.zone_id)?.permanent_allowed === true);
  if (permitted.length === 0) return refuse('zone_sans_politique', 'Zone sans politique d’accès pour les titulaires permanents : entrée refusée par défaut.', who);
  // Garde propre au hors ligne : confiance bornée dans le temps, jugée maintenant.
  if (!(snap.trust_minutes >= 1)) return refuse('hors_ligne_non_autorise', 'Entrée hors ligne non autorisée : aucune durée de confiance approuvée par l’institution.', who);
  const known = knownAt ? Date.parse(knownAt) : NaN;
  if (Number.isNaN(known) || known > now || (now - known) / 60_000 > snap.trust_minutes) {
    return refuse('confiance_expiree', 'Confiance hors ligne expirée : synchronisez la tablette avant toute nouvelle entrée.', who);
  }
  if (!permitted.some(e => policyOf(e.zone_id)?.offline_entry_allowed === true)) return refuse('hors_ligne_non_autorise', 'Cette zone n’accepte pas l’entrée des titulaires permanents hors ligne.', who);
  const names = permitted.map(e => zones.find(z => z.id === e.zone_id)?.name).filter((n): n is string => !!n);

  return { allowed: true, reason: null, message: 'Carte permanente valide (contrôle hors ligne).', zones: [...new Set(names)].sort((a, b) => a.localeCompare(b, 'fr')), holder: who };
}

/** Minutes de confiance hors ligne restantes (négatif ou nul : expirée), ou null si aucune politique n'est approuvée. Sert à l'affichage permanent du poste. */
export function trustRemainingMinutes(snap: PermanentSnapshot | undefined, now: number, knownAt: string | null): number | null {
  if (!snap || !(snap.trust_minutes >= 1)) return null;
  const known = knownAt ? Date.parse(knownAt) : NaN;
  if (Number.isNaN(known) || known > now) return 0;
  return Math.floor(snap.trust_minutes - (now - known) / 60_000);
}

export type PermanentResult = DirectoryEntry & { zones: string[] };

const CARD_LABELS: Record<string, string> = { active: 'Carte active', non_activee: 'Carte pas encore remise', perdue: 'Carte perdue', volee: 'Carte volée', revoquee: 'Carte révoquée', retiree: 'Carte retirée', aucune: 'Aucune carte' };
export const cardLabel = (state: string): string => CARD_LABELS[state] ?? state;

const fold = (v: string): string => v.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

/**
 * Recherche d'une personne permanente à l'accueil (lecture seule, sans réseau). Chaque mot saisi doit se trouver dans le nom, l'identifiant, l'organisation ou la catégorie.
 * Les zones sont celles des habilitations EN COURS pour cet événement : information seulement, la décision d'entrée reste celle du contrôle.
 */
export function searchPermanentDirectory(snap: PermanentSnapshot | undefined, zones: { id: string; name: string }[], query: string, now: number, limit = 10): PermanentResult[] {
  const words = fold(query).split(/\s+/).filter(w => w.length > 0);
  if (!snap?.directory || words.length === 0) return [];
  const zoneName = new Map(zones.map(z => [z.id, z.name]));
  const found = snap.directory.filter(d => { const hay = fold([d.name, d.identifier, d.organisation ?? '', d.category?.name ?? ''].join(' ')); return words.every(w => hay.includes(w)); }).slice(0, limit);
  return found.map(d => {
    const holder = snap.holders.find(h => h.id === d.id);
    const names = (holder?.entitlements ?? []).filter(e => (e.valid_from === null || Date.parse(e.valid_from) <= now) && e.valid_until !== null && Date.parse(e.valid_until) > now).map(e => zoneName.get(e.zone_id)).filter((n): n is string => !!n);
    return { ...d, zones: [...new Set(names)].sort((a, b) => a.localeCompare(b, 'fr')) };
  });
}
