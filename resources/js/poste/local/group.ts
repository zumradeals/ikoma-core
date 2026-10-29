import { ApiError } from '../types';
import type { LocalList, SnapGroup, SnapMember, SnapVisitor } from './list';

/** Règles des groupes appliquées à la copie de la tablette (mêmes refus que le serveur, App\Services\Groups). */
const refuse = (code: string, message: string, action: string | null = null) => new ApiError(code, message, action, 422);

export type GroupMemberView = {
  visitor_id: string; name: string; organisation: string | null; badge_code: string | null;
  checked: boolean; confirmed: boolean; absent: boolean; boarded: boolean; arrived: boolean;
};
export type GroupProgress = { total: number; badged: number; boarded: number; arrived: number; absent: number; complete: boolean };
export type GroupView = { group: SnapGroup; members: GroupMemberView[]; progress: GroupProgress; mode: 'identite' | 'encadreur' };
export type GroupSummary = { id: string; name: string; bus_number: string | null; total: number; badged: number; departed: boolean; arrival_closed: boolean };
export type ArrivalResult = { badge_code: string; name: string; group: string; ecart: 'non_embarque' | null; already: boolean };
export type GroupDelta = { badge_code?: string; arrival?: ArrivalResult; boarded?: number; absent?: number; arrived?: number; missing?: number };

export function groupMode(list: LocalList): 'identite' | 'encadreur' {
  return list.snapshot.settings.controle_stagiaires === 'identite' ? 'identite' : 'encadreur';
}

function membersOf(list: LocalList, groupId: string): SnapMember[] {
  return Array.from(list.members.values()).filter(m => m.group_id === groupId);
}

export function groupSummaries(list: LocalList): GroupSummary[] {
  return Array.from(list.groups.values()).map(g => {
    const members = membersOf(list, g.id);
    return { id: g.id, name: g.name, bus_number: g.bus_number, total: members.length, badged: members.filter(m => list.visitors.get(m.visitor_id)?.badge_code).length, departed: g.departed, arrival_closed: g.arrival_closed };
  }).sort((a, b) => a.name.localeCompare(b.name, 'fr'));
}

export function groupView(list: LocalList, groupId: string): GroupView {
  const group = list.groups.get(groupId);
  if (!group) throw new ApiError('groupe_inconnu', 'Groupe introuvable pour ce poste.', null, 404);
  const members = membersOf(list, groupId).map(m => {
    const v = list.visitors.get(m.visitor_id);
    return { visitor_id: m.visitor_id, name: v?.full_name ?? '', organisation: v?.organisation ?? null, badge_code: v?.badge_code ?? null, checked: m.checked, confirmed: m.confirmed, absent: m.absent, boarded: m.boarded, arrived: m.arrived };
  }).sort((a, b) => a.name.localeCompare(b.name, 'fr') || a.visitor_id.localeCompare(b.visitor_id));
  const badged = members.filter(m => m.badge_code).length;
  return { group, members, progress: { total: members.length, badged, boarded: members.filter(m => m.boarded).length, arrived: members.filter(m => m.arrived).length, absent: members.filter(m => m.absent).length, complete: members.length > 0 && badged === members.length }, mode: groupMode(list) };
}

function memberOf(list: LocalList, groupId: unknown, visitorId: unknown): { group: SnapGroup; member: SnapMember; visitor: SnapVisitor } {
  const group = typeof groupId === 'string' ? list.groups.get(groupId) : undefined;
  if (!group) throw new ApiError('groupe_inconnu', 'Groupe introuvable pour ce poste.', null, 404);
  const member = typeof visitorId === 'string' ? list.members.get(visitorId) : undefined;
  const visitor = member ? list.visitors.get(member.visitor_id) : undefined;
  if (!member || member.group_id !== group.id || !visitor) throw refuse('pas_du_groupe', 'Cette personne ne fait pas partie de ce groupe.');
  return { group, member, visitor };
}

/** Le code imprimé désigne un badge ; `holder` est la personne qui le porte. */
function holderOf(list: LocalList, code: string): SnapVisitor | undefined {
  return Array.from(list.visitors.values()).find(v => v.badge_code === code && v.status !== 'sorti');
}

export function applyGroupOp(list: LocalList, type: string, p: Record<string, unknown>): GroupDelta {
  switch (type) {
    case 'groupe_controle': {
      const { group, member } = memberOf(list, p.group_id, p.visitor_id);
      if (group.departed || member.absent) throw refuse('car_ferme', 'Le car est déjà parti : plus aucun contrôle de ce groupe n’est possible.');
      group.opened = true;
      if (member.checked) return {};
      if (groupMode(list) === 'identite') {
        if (p.identity_checked !== true) throw refuse('identite_non_cochee', 'Cochez « pièce d’identité contrôlée » après avoir vérifié la pièce.');
      } else if (!member.confirmed) {
        throw refuse('confirmation_encadreur_requise', 'L’encadreur n’a pas encore confirmé la présence de cette personne.', 'Demandez à l’encadreur de confirmer sur son téléphone.');
      }
      member.checked = true;
      return {};
    }
    case 'groupe_badge': {
      const { group, member, visitor } = memberOf(list, p.group_id, p.visitor_id);
      if (group.departed) throw refuse('car_ferme', 'Le car est déjà parti : plus aucun badge ne peut être remis.');
      group.opened = true;
      if (!member.checked) throw refuse('controle_requis', 'Contrôlez d’abord la correspondance de cette personne.', groupMode(list) === 'encadreur' ? 'Attendez la confirmation de l’encadreur.' : 'Cochez la pièce d’identité contrôlée.');
      const code = String(p.code ?? '').toUpperCase();
      const badge = list.badges.get(code);
      if (!badge) throw refuse('badge_inconnu', 'Badge inconnu : vérifiez le code imprimé ou prenez un autre badge.', 'Saisissez le code imprimé ou prenez un autre badge.');
      if (visitor.badge_code === badge.code) return { badge_code: badge.code };
      if (badge.group_id !== group.id) throw refuse('hors_lot_groupe', 'Ce badge n’appartient pas au lot réservé à ce groupe.', 'Prenez un badge du lot de ce groupe.');
      if (badge.printed_for_visitor_id && badge.printed_for_visitor_id !== visitor.id) throw refuse('destination_nominative', 'Ce badge nominatif est réservé à une autre personne.');
      if (badge.status !== 'Disponible') {
        if (badge.status === 'Associé') throw refuse('deja_associe', `Badge déjà associé${holderOf(list, badge.code) ? ` à ${holderOf(list, badge.code)!.full_name}` : ''}. Prenez un autre badge.`, 'Prenez un autre badge du lot du groupe.');
        throw refuse(badge.status === 'Annulé' ? 'annule' : 'indisponible', badge.status === 'Annulé' ? 'Badge annulé : il ne peut plus être utilisé.' : 'Badge restitué ou clos : il ne peut plus être associé.', 'Prenez un autre badge du lot du groupe.');
      }
      if (!badge.category_id) throw refuse('sans_categorie', 'Badge sans catégorie (lot hérité) : il ne peut pas être associé. Prenez un autre badge.', 'Prenez un autre badge du lot du groupe.');
      if (badge.category_id !== visitor.category_id) throw refuse('categorie', `Badge de catégorie ${list.categories.get(badge.category_id)?.name ?? ''}, personne de catégorie ${list.categories.get(visitor.category_id)?.name ?? ''}`, 'Prenez un autre badge du lot du groupe.');
      if (visitor.badge_code) throw refuse('visiteur_deja_badge', `Cette personne a déjà le badge ${visitor.badge_code}.`);
      badge.status = 'Associé'; visitor.badge_code = badge.code; list.touch();
      return { badge_code: badge.code };
    }
    case 'groupe_depart': {
      const group = typeof p.group_id === 'string' ? list.groups.get(p.group_id) : undefined;
      if (!group) throw new ApiError('groupe_inconnu', 'Groupe introuvable pour ce poste.', null, 404);
      const members = membersOf(list, group.id);
      if (!group.departed) {
        group.opened = true; group.departed = true;
        for (const m of members) { if (list.visitors.get(m.visitor_id)?.badge_code) m.boarded = true; else m.absent = true; }
      }
      return { boarded: members.filter(m => m.boarded).length, absent: members.filter(m => m.absent).length };
    }
    case 'groupe_arrivee': {
      const code = String(p.code ?? '').toUpperCase();
      const badge = list.badges.get(code);
      if (!badge) throw refuse('badge_inconnu', 'Badge inconnu : vérifiez le code ou scannez à nouveau.');
      const holder = holderOf(list, code);
      if (!holder) throw refuse('non_associe', 'Badge non associé à une personne.');
      const member = list.members.get(holder.id);
      if (!member) throw refuse('pas_dun_groupe', 'Ce badge n’est pas celui d’un stagiaire de groupe : utilisez le contrôle sur site.');
      const group = list.groups.get(member.group_id)!;
      const base = { badge_code: code, name: holder.full_name, group: group.bus_number ?? group.name };
      if (member.arrived) return { arrival: { ...base, ecart: null, already: true } };
      const ecart = member.boarded ? null : 'non_embarque';
      member.arrived = true; holder.status = 'present'; list.touch();
      return { arrival: { ...base, ecart, already: false } };
    }
    case 'groupe_arrivee_fin': {
      const group = typeof p.group_id === 'string' ? list.groups.get(p.group_id) : undefined;
      if (!group) throw new ApiError('groupe_inconnu', 'Groupe introuvable pour ce poste.', null, 404);
      group.arrival_closed = true;
      const members = membersOf(list, group.id);
      return { arrived: members.filter(m => m.arrived).length, missing: members.filter(m => m.boarded && !m.arrived).length };
    }
  }
  return {};
}
