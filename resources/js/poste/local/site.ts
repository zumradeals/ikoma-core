import type { Checkpoint, ControlResult, ExitResult, PointRef } from '../types';
import { outsidePeriod } from './apply';
import type { PosteRefusal } from './capabilities';
import { sha256Hex } from './crypto';
import type { LocalList } from './list';
import { PERMANENT_PREFIX, decidePermanentOffline, findPermanentCard } from './permanent';

/** Retrouve un badge de l'événement à partir du code imprimé ou du contenu du QR (comparé par hachage : le jeton n'est jamais conservé). */
export async function resolveBadge(list: LocalList, input: string): Promise<string | null> {
  const value = input.trim().replace(/^IKOMA1:/, '');
  if (/^[0-9a-f]{64}$/i.test(value)) {
    const hash = await sha256Hex(value.toLowerCase());
    for (const b of list.badges.values()) if (b.token_hash === hash) return b.code;
    // Carte permanente : seule l'empreinte du jeton est conservée et mise en file (jamais le jeton).
    return findPermanentCard(list.snapshot.permanent, hash) ? PERMANENT_PREFIX + hash : null;
  }
  return list.badges.has(value.toUpperCase()) ? value.toUpperCase() : null;
}

export function checkpointsOf(list: LocalList): Checkpoint[] {
  const s = list.snapshot;
  return [...s.zones.map(z => ({ type: 'zone' as const, id: z.id, name: z.name })), ...s.postes.map(p => ({ type: 'poste' as const, id: p.id, name: p.name }))];
}

function pointName(list: LocalList, point: PointRef | null): string {
  if (!point) return list.snapshot.poste.station || list.snapshot.poste.name;
  const found = checkpointsOf(list).find(c => c.type === point.type && c.id === point.id);
  return found?.name ?? '';
}

const REFUSALS: Record<string, string> = {
  inconnu: 'Badge inconnu.', annule: 'Badge annulé : accès refusé.', restitue: 'Badge restitué : il n’est plus valable.', personne_sortie: 'Cette personne est sortie : le badge est à restituer et ne donne plus accès.', non_associe: 'Badge non associé à une personne.', hors_periode: 'Badge hors de sa période de validité.',
};

/** Même règle que BadgeService::controler, appliquée à la copie de la tablette (situation connue à la dernière synchronisation). */
export function controlLocal(list: LocalList, printed: string | null, point: PointRef | null, now: number, knownAt: string | null): ControlResult {
  const base = { badge_code: printed, zone_ok: null, point: pointName(list, point), person: null, organisation: null, category: null, zones: [] as string[], source: 'local' as const, known_at: knownAt };
  const refuse = (reason: string): ControlResult => ({ ...base, valid: false, reason, message: REFUSALS[reason] });
  if (printed?.startsWith(PERMANENT_PREFIX) && list.snapshot.permanent) {
    const decision = decidePermanentOffline(list.snapshot.permanent, printed.slice(PERMANENT_PREFIX.length), point, list.snapshot.zones, now, knownAt);

    return { ...base, badge_code: decision.holder?.identifier ?? null, valid: decision.allowed, reason: decision.reason, message: decision.message, person: decision.allowed && decision.holder ? { name: decision.holder.name } : null, organisation: decision.holder?.organisation ?? null,
      category: decision.allowed ? (decision.holder?.category ?? null) : null,
      zones: decision.zones, zone_ok: decision.allowed && point?.type === 'zone' ? true : null, permanent: decision.holder ? { identifier: decision.holder.identifier } : null };
  }
  const badge = printed ? list.badges.get(printed) : undefined;
  if (!badge) return refuse('inconnu');
  if (badge.status === 'Annulé') return refuse('annule');
  if (badge.status === 'Restitué' || badge.status === 'Clos') return refuse('restitue');
  const holder = Array.from(list.visitors.values()).find(v => v.badge_code === badge.code && v.status !== 'refuse');
  if (badge.status === 'Disponible' || !holder) return refuse('non_associe');
  if (holder.status === 'sorti') return refuse('personne_sortie'); // sortie ≠ restitution : le badge reste associé mais ne donne plus accès
  if (outsidePeriod(holder, now)) return refuse('hors_periode');
  const category = list.categories.get(holder.category_id) ?? null;
  // S06 : zones de la catégorie + habilitations actives de la participation (dans leur période) ; mêmes règles que le serveur.
  const allowed = new Set(list.snapshot.category_zone.filter(cz => cz.category_id === holder.category_id).map(cz => cz.zone_id));
  for (const e of list.snapshot.entitlements ?? []) {
    if (e.visitor_id === holder.id && Date.parse(e.valid_from) <= now && now <= Date.parse(e.valid_until)) allowed.add(e.zone_id);
  }
  const zones = list.snapshot.zones.filter(z => allowed.has(z.id)).map(z => z.name).sort((a, b) => a.localeCompare(b, 'fr'));
  let zoneOk: boolean | null = null;
  if (point?.type === 'zone') zoneOk = allowed.has(point.id);
  else {
    const judged = (list.snapshot.point_zones ?? []).filter(m => m.device_id === (point?.id ?? list.snapshot.poste.id)).map(m => m.zone_id);
    if (judged.length > 0) zoneOk = judged.some(id => allowed.has(id));
  }
  return { ...base, valid: true, reason: null, message: 'Badge valide.', person: { name: holder.full_name }, organisation: holder.organisation, category, zones, zone_ok: zoneOk };
}

/** Sortie jugée sur la copie locale. Une personne « attendue » sur cette tablette a peut-être été saisie à l'entrée ailleurs : on enregistre, le serveur tranchera. */
export function exitLocal(list: LocalList, printed: string | null, point: PointRef | null, knownAt: string | null): ExitResult {
  const base = { source: 'local' as const, known_at: knownAt, point: pointName(list, point) };
  const refuse = (reason: string, message: string): ExitResult => ({ ...base, ok: false, reason, message, badge_code: printed });
  if (printed?.startsWith(PERMANENT_PREFIX)) {
    // Sortie d'un titulaire permanent : jamais soumise à l'habilitation, au statut de la carte ni à la confiance hors ligne ; le serveur vérifie qu'une entrée existe.
    const card = findPermanentCard(list.snapshot.permanent, printed.slice(PERMANENT_PREFIX.length));
    const holder = list.snapshot.permanent?.holders.find(h => h.id === card?.holder_id);
    return { ...base, ok: true, badge_code: card?.identifier ?? null, person: holder ? { name: holder.name } : undefined, permanent: { identifier: card?.identifier ?? '', card_kept: true }, warning: 'Sortie enregistrée hors ligne : la carte est conservée, le serveur vérifiera l’entrée à la synchronisation.' };
  }
  const badge = printed ? list.badges.get(printed) : undefined;
  if (!badge) return refuse('inconnu', 'Badge inconnu : vérifiez le code ou scannez à nouveau.');
  if (badge.status === 'Annulé') return refuse('annule', 'Badge annulé : la sortie n’est pas enregistrée. Prévenez le superviseur.');
  const holder = Array.from(list.visitors.values()).find(v => v.badge_code === badge.code);
  if (holder?.status === 'sorti') return refuse('deja_sorti', 'Sortie déjà enregistrée pour ce badge.');
  if (badge.status === 'Disponible' || !holder) return refuse('non_associe', 'Badge non associé à une personne : aucune sortie à enregistrer.');
  if (badge.status !== 'Associé') return refuse('restitue', 'Badge restitué : aucune sortie à enregistrer.');
  if (holder.status === 'refuse') return refuse('visiteur_refuse', 'Accès refusé à cette personne : aucune sortie à enregistrer.');
  return { ...base, ok: true, badge_code: badge.code, person: { name: holder.full_name }, warning: holder.status === 'present' ? null : 'Aucune entrée connue sur cette tablette : la sortie sera vérifiée à la synchronisation.' };
}

/** Refus de poste (S02) rendu comme un résultat de contrôle : jamais favorable, rien n'est mis en file. */
export function refusedControl(list: LocalList, point: PointRef | null, refusal: PosteRefusal, knownAt: string | null): ControlResult {
  return { badge_code: null, zone_ok: null, point: pointName(list, point), person: null, organisation: null, category: null, zones: [], source: 'local', known_at: knownAt, valid: false, reason: refusal.reason, message: refusal.message };
}

export function refusedExit(list: LocalList, point: PointRef | null, refusal: PosteRefusal, knownAt: string | null): ExitResult {
  return { source: 'local', known_at: knownAt, point: pointName(list, point), ok: false, reason: refusal.reason, message: refusal.message, badge_code: null };
}
