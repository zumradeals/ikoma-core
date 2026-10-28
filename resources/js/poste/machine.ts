import type { Fiche } from './types';

/** Les 7 étapes du document de fonctionnement (section 6), plus les cas particuliers. Aucune visite Inertia : tout est un état interne. */
export type Step = 'recherche' | 'fiche' | 'identite' | 'decision' | 'badge' | 'remise' | 'entree' | 'refus' | 'non_liste' | 'attente_hote';

export type Ctx = {
  step: Step;
  visitorId: string | null;
  startedAt: number | null;
  unlisted: boolean;
  hostAccepted: boolean;
  identityDone: boolean;
  photoDone: boolean;
  validated: boolean;
  badgeCode: string | null;
  durationMs: number | null;
  hostRefused: boolean;
};

export type Ev =
  | { type: 'START' }
  | { type: 'SELECT'; fiche: Pick<Fiche, 'id' | 'identity_done' | 'photo_done' | 'validated' | 'badge_code' | 'source' | 'host_validation'> }
  | { type: 'CONFIRM_FICHE' }
  | { type: 'IDENTITY_SAVED' }
  | { type: 'VALIDATED' }
  | { type: 'REFUSED' }
  | { type: 'BADGE_ASSIGNED'; code: string }
  | { type: 'ENTERED' }
  | { type: 'BACK' }
  | { type: 'NEW_UNLISTED' }
  | { type: 'UNLISTED_CREATED'; visitorId: string }
  | { type: 'HOST_DECISION'; accepted: boolean }
  | { type: 'NEXT' };

export function initial(): Ctx {
  return { step: 'recherche', visitorId: null, startedAt: null, unlisted: false, hostAccepted: true, identityDone: false, photoDone: false, validated: false, badgeCode: null, durationMs: null, hostRefused: false };
}

/** Raison pour laquelle « Valider » est grisé, ou null s'il peut être actif. */
export function validateBlockedReason(ctx: Ctx): string | null {
  if (!ctx.identityDone && !ctx.photoDone) return 'La pièce d’identité et la photo ne sont pas encore enregistrées.';
  if (!ctx.identityDone) return 'La pièce d’identité n’est pas encore contrôlée.';
  if (!ctx.photoDone) return 'La photo n’est pas encore prise.';
  return null;
}

/** Fonction pure : une transition interdite renvoie l'état inchangé. */
export function reduce(ctx: Ctx, event: Ev, now: number = Date.now()): Ctx {
  switch (event.type) {
    case 'START':
      return ctx.step === 'recherche' && ctx.startedAt === null ? { ...ctx, startedAt: now } : ctx;
    case 'SELECT': {
      if (ctx.step !== 'recherche') return ctx;
      const f = event.fiche;
      return { ...ctx, step: 'fiche', visitorId: f.id, startedAt: ctx.startedAt ?? now, unlisted: f.source === 'non listé', hostAccepted: f.source !== 'non listé' || f.host_validation === 'accepte', identityDone: f.identity_done, photoDone: f.photo_done, validated: f.validated, badgeCode: f.badge_code, hostRefused: f.host_validation === 'refuse' };
    }
    case 'CONFIRM_FICHE': {
      if (ctx.step !== 'fiche') return ctx;
      if (ctx.unlisted && !ctx.hostAccepted) return { ...ctx, step: 'attente_hote' };
      if (ctx.badgeCode) return { ...ctx, step: 'remise' };
      if (ctx.validated) return { ...ctx, step: 'badge' };
      return { ...ctx, step: 'identite' };
    }
    case 'IDENTITY_SAVED':
      return ctx.step === 'identite' ? { ...ctx, identityDone: true, photoDone: true, step: 'decision' } : ctx;
    case 'VALIDATED':
      return ctx.step === 'decision' && validateBlockedReason(ctx) === null ? { ...ctx, validated: true, step: 'badge' } : ctx;
    case 'REFUSED':
      return ctx.step === 'decision' || ctx.step === 'attente_hote' || ctx.step === 'identite' ? { ...ctx, step: 'refus' } : ctx;
    case 'BADGE_ASSIGNED':
      return ctx.step === 'badge' && ctx.validated ? { ...ctx, badgeCode: event.code, step: 'remise' } : ctx;
    case 'ENTERED':
      return ctx.step === 'remise' && ctx.badgeCode ? { ...ctx, step: 'entree', durationMs: ctx.startedAt === null ? 0 : Math.max(0, now - ctx.startedAt) } : ctx;
    case 'BACK': {
      // On ne revient jamais en arrière après un enregistrement irréversible (validation, badge, entrée).
      if (ctx.step === 'fiche' || ctx.step === 'non_liste') return { ...ctx, step: 'recherche', visitorId: ctx.step === 'fiche' ? null : ctx.visitorId };
      if (ctx.step === 'identite') return { ...ctx, step: 'fiche' };
      if (ctx.step === 'decision') return { ...ctx, step: 'identite' };
      return ctx;
    }
    case 'NEW_UNLISTED':
      return ctx.step === 'recherche' ? { ...ctx, step: 'non_liste', startedAt: ctx.startedAt ?? now } : ctx;
    case 'UNLISTED_CREATED':
      return ctx.step === 'non_liste' ? { ...ctx, step: 'attente_hote', visitorId: event.visitorId, unlisted: true, hostAccepted: false } : ctx;
    case 'HOST_DECISION':
      if (ctx.step !== 'attente_hote') return ctx;
      return event.accepted ? { ...ctx, hostAccepted: true, step: 'identite' } : { ...ctx, hostRefused: true };
    case 'NEXT':
      return ctx.step === 'entree' || ctx.step === 'refus' || ctx.hostRefused ? initial() : ctx;
  }
}

/** Libellés des 7 étapes pour le fil d'Ariane (étape 1 à 7). */
export const stepNumber: Record<Step, number> = { recherche: 1, fiche: 2, identite: 3, decision: 4, badge: 5, remise: 6, entree: 7, refus: 4, non_liste: 1, attente_hote: 2 };
export const stepLabels = ['Recherche', 'Fiche', 'Identité et photo', 'Décision', 'Badge', 'Remise', 'Entrée'];
