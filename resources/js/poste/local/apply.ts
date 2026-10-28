import { ApiError, type Avis, type Fiche, type SearchResult } from '../types';
import type { LocalList, SnapVisitor } from './list';
import { searchIndex } from './search';

export type OpType = 'identite' | 'valider' | 'refuser' | 'badge' | 'entree' | 'non_liste' | 'controle' | 'sortie';
export type LocalOp = {
  id: string; seq: number; type: OpType; agent_id: string; client_time: string; visitor_rev: string | null; payload: Record<string, unknown>; photoId?: string;
  result?: { code: string; message: string } | null;
};

const refuse = (code: string, message: string, action: string | null = null) => new ApiError(code, message, action, 422);

export function outsidePeriod(visitor: SnapVisitor, now: number): boolean {
  return now < Date.parse(visitor.valid_from) || now > Date.parse(visitor.valid_until);
}

export function ficheOf(list: LocalList, id: string, now: number): Fiche {
  const v = list.visitors.get(id);
  if (!v) throw new ApiError('visiteur_inconnu', 'Visiteur introuvable pour ce poste.', null, 404);
  const category = list.categories.get(v.category_id);
  if (!category) throw new ApiError('visiteur_inconnu', 'Visiteur introuvable pour ce poste.', null, 404);
  return {
    id: v.id, full_name: v.full_name, organisation: v.organisation, host: v.host_id ? list.hostNames.get(v.host_id) ?? null : null, category, zones: list.zonesFor(v),
    accueil_limite: v.source === 'non listé', source: v.source, status: v.status, host_validation: v.host_validation, valid_from: v.valid_from, valid_until: v.valid_until,
    hors_periode: outsidePeriod(v, now), identity_done: v.identity_done, photo_done: v.photo_done, validated: v.validated, badge_code: v.badge_code,
  };
}

export function searchOf(list: LocalList, query: string): SearchResult[] {
  return searchIndex(list.indexed(), query).map(e => {
    const v = list.visitors.get(e.id)!;
    return { id: e.id, full_name: e.full_name, organisation: e.organisation, host: e.host, homonyms: e.homonyms, category: list.categories.get(v.category_id)! };
  });
}

function open(list: LocalList, id: unknown, allowPresent = false): SnapVisitor {
  const v = typeof id === 'string' ? list.visitors.get(id) : undefined;
  if (!v) throw new ApiError('visiteur_inconnu', 'Visiteur introuvable pour ce poste.', null, 404);
  if (v.status === 'refuse') throw refuse('visiteur_refuse', 'L’accès a été refusé à ce visiteur.');
  if ((v.status === 'present' || v.status === 'sorti') && !allowPresent) throw refuse('deja_present', 'Ce visiteur est déjà entré.');
  return v;
}

/** Le contenu d'un QR ou le code imprimé désigne un badge réservé à cette tablette (le jeton n'existe ici que sous forme de hachage). */
export function findBadge(list: LocalList, input: string, hash: (token: string) => Promise<string>): Promise<string | null> | string | null {
  const trimmed = input.trim().replace(/^IKOMA1:/, '');
  if (/^[0-9a-f]{64}$/i.test(trimmed)) {
    return hash(trimmed.toLowerCase()).then(h => { for (const b of list.badges.values()) if (b.token_hash === h) return b.code; return null; });
  }
  return list.badges.has(trimmed.toUpperCase()) ? trimmed.toUpperCase() : null;
}

/** Règles du poste appliquées à la copie locale ; lève la même erreur que le serveur quand l'action est impossible. Le badge est déjà résolu en code imprimé. */
export function applyOp(list: LocalList, op: LocalOp, now: number): { badge_code?: string; zones?: string[]; visitor_id?: string } {
  const p = op.payload;
  switch (op.type) {
    case 'identite': {
      const v = open(list, p.visitor_id);
      v.identity_done = true; v.photo_done = true; list.touch();
      return {};
    }
    case 'valider': {
      const v = open(list, p.visitor_id);
      if (v.source === 'non listé' && v.host_validation !== 'accepte') {
        throw refuse('hote_en_attente', v.host_validation === 'refuse' ? 'L’hôte a refusé cette visite : l’entrée est impossible.' : 'En attente de l’accord de l’hôte : la validation et l’entrée sont impossibles pour le moment.', 'Attendez la réponse de l’hôte ou appelez-le.');
      }
      if (!v.identity_done || !v.photo_done) throw refuse('identite_requise', 'La pièce d’identité et la photo sont obligatoires avant de valider.');
      if (outsidePeriod(v, now) && p.avis !== 'hote' && p.avis !== 'superviseur') {
        throw refuse('hors_periode', 'Visiteur hors période : avis de l’hôte ou du superviseur requis', 'Demandez l’avis de l’hôte ou du superviseur, puis indiquez-le.');
      }
      v.validated = true;
      return {};
    }
    case 'refuser': {
      const v = open(list, p.visitor_id);
      if (String(p.motif ?? '').trim() === '') throw refuse('motif_obligatoire', 'Le motif du refus est obligatoire.');
      if (v.badge_code) throw refuse('badge_deja_remis', 'Un badge est déjà associé à ce visiteur : le refus doit passer par le superviseur.');
      v.status = 'refuse'; list.touch();
      return {};
    }
    case 'badge': {
      const v = open(list, p.visitor_id);
      if (!v.validated) throw refuse('validation_requise', 'Validez d’abord le visiteur avant de lui remettre un badge.');
      const code = String(p.code ?? '').toUpperCase();
      const badge = list.badges.get(code);
      if (!badge) throw refuse('badge_inconnu', 'Badge inconnu : vérifiez le code imprimé ou prenez un autre badge.', 'Saisissez le code imprimé ou prenez un autre badge.');
      if (v.badge_code === badge.code) return { badge_code: badge.code };
      if (!badge.mine) throw refuse('autre_poste', 'Ce badge n’est pas réservé à ce poste : il ne peut pas être associé depuis cette tablette.', 'Prenez un badge réservé à ce poste.');
      if (badge.status !== 'Disponible') {
        if (badge.status === 'Associé') {
          const holder = Array.from(list.visitors.values()).find(x => x.badge_code === badge.code)?.full_name;
          throw refuse('deja_associe', `Badge déjà associé${holder ? ` à ${holder}` : ''}. Prenez un autre badge.`, 'Prenez un autre badge.');
        }
        throw refuse(badge.status === 'Annulé' ? 'annule' : 'indisponible', badge.status === 'Annulé' ? 'Badge annulé : il ne peut plus être utilisé.' : 'Badge restitué ou clos : il ne peut plus être associé.', 'Prenez un autre badge.');
      }
      if (!badge.category_id) throw refuse('sans_categorie', 'Badge sans catégorie (lot hérité) : il ne peut pas être associé. Prenez un autre badge.', 'Prenez un badge d’un lot par catégorie.');
      if (badge.category_id !== v.category_id) throw refuse('categorie', `Badge de catégorie ${list.categories.get(badge.category_id)?.name ?? ''}, visiteur de catégorie ${list.categories.get(v.category_id)?.name ?? ''}`, `Prenez un badge de catégorie ${list.categories.get(v.category_id)?.name ?? ''}.`);
      if (v.badge_code) throw refuse('visiteur_deja_badge', `Ce visiteur a déjà le badge ${v.badge_code}.`, 'Ce visiteur a déjà son badge : passez à la remise.');
      badge.status = 'Associé'; v.badge_code = badge.code;
      return { badge_code: badge.code };
    }
    case 'entree': {
      const v = open(list, p.visitor_id, true);
      if (!v.validated || !v.badge_code) throw refuse('badge_requis', 'Le visiteur doit être validé et avoir un badge avant d’entrer.');
      v.status = 'present'; list.touch();
      return { zones: list.zonesFor(v) };
    }
    case 'controle':
      return {}; // un contrôle ne change rien à la copie locale ; l'événement est écrit par le serveur au rejeu
    case 'sortie': {
      const holder = Array.from(list.visitors.values()).find(x => x.badge_code === String(p.code).toUpperCase());
      if (holder) { holder.status = 'sorti'; list.touch(); const badge = list.badges.get(String(p.code).toUpperCase()); if (badge) badge.status = 'Restitué'; }
      return {};
    }
    case 'non_liste': {
      const id = String(p.visitor_id ?? '');
      if (list.visitors.has(id)) throw new ApiError('fiche_existante', 'Cette fiche existe déjà.', null, 409);
      const category = list.categories.get(String(p.category_id)); const host = String(p.host_id ?? '');
      if (!category || !list.hostNames.has(host) || String(p.name ?? '').trim() === '') throw refuse('operation_invalide', 'Opération incomplète.');
      const from = Math.min(now, Date.parse(list.snapshot.event.starts_at));
      list.visitors.set(id, { id, full_name: String(p.name).trim().slice(0, 160), organisation: p.organisation ? String(p.organisation).trim().slice(0, 160) : null, host_id: host, category_id: category.id, valid_from: new Date(from).toISOString(), valid_until: list.snapshot.event.ends_at, source: 'non listé', status: 'attendu', host_validation: 'en_attente', rev: null, identity_done: false, photo_done: false, validated: false, badge_code: null });
      list.touch();
      return { visitor_id: id };
    }
  }
}

export type { Avis };
