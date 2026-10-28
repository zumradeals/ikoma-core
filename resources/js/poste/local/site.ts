import type { Checkpoint, ControlResult, ExitResult, PointRef } from '../types';
import { outsidePeriod } from './apply';
import { sha256Hex } from './crypto';
import type { LocalList } from './list';

/** Retrouve un badge de l'événement à partir du code imprimé ou du contenu du QR (comparé par hachage : le jeton n'est jamais conservé). */
export async function resolveBadge(list: LocalList, input: string): Promise<string | null> {
  const value = input.trim().replace(/^IKOMA1:/, '');
  if (/^[0-9a-f]{64}$/i.test(value)) {
    const hash = await sha256Hex(value.toLowerCase());
    for (const b of list.badges.values()) if (b.token_hash === hash) return b.code;
    return null;
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
  inconnu: 'Badge inconnu.', annule: 'Badge annulé : accès refusé.', restitue: 'Badge restitué : il n’est plus valable.', non_associe: 'Badge non associé à une personne.', hors_periode: 'Badge hors de sa période de validité.',
};

/** Même règle que BadgeService::controler, appliquée à la copie de la tablette (situation connue à la dernière synchronisation). */
export function controlLocal(list: LocalList, printed: string | null, point: PointRef | null, now: number, knownAt: string | null): ControlResult {
  const base = { badge_code: printed, zone_ok: null, point: pointName(list, point), person: null, organisation: null, category: null, zones: [] as string[], source: 'local' as const, known_at: knownAt };
  const refuse = (reason: string): ControlResult => ({ ...base, valid: false, reason, message: REFUSALS[reason] });
  const badge = printed ? list.badges.get(printed) : undefined;
  if (!badge) return refuse('inconnu');
  if (badge.status === 'Annulé') return refuse('annule');
  if (badge.status === 'Restitué' || badge.status === 'Clos') return refuse('restitue');
  const holder = Array.from(list.visitors.values()).find(v => v.badge_code === badge.code && v.status !== 'sorti' && v.status !== 'refuse');
  if (badge.status === 'Disponible' || !holder) return refuse('non_associe');
  if (outsidePeriod(holder, now)) return refuse('hors_periode');
  const category = list.categories.get(holder.category_id) ?? null;
  const allowed = new Set(list.snapshot.category_zone.filter(cz => cz.category_id === holder.category_id).map(cz => cz.zone_id));
  const zones = list.snapshot.zones.filter(z => allowed.has(z.id)).map(z => z.name).sort((a, b) => a.localeCompare(b, 'fr'));
  return { ...base, valid: true, reason: null, message: 'Badge valide.', person: { name: holder.full_name }, organisation: holder.organisation, category, zones, zone_ok: point?.type === 'zone' ? allowed.has(point.id) : null, watch: holder.watch === true };
}

/** Sortie jugée sur la copie locale. Une personne « attendue » sur cette tablette a peut-être été saisie à l'entrée ailleurs : on enregistre, le serveur tranchera. */
export function exitLocal(list: LocalList, printed: string | null, point: PointRef | null, knownAt: string | null): ExitResult {
  const base = { source: 'local' as const, known_at: knownAt, point: pointName(list, point) };
  const refuse = (reason: string, message: string): ExitResult => ({ ...base, ok: false, reason, message, badge_code: printed });
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
