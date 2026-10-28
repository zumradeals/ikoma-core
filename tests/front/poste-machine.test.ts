import { describe, expect, it } from 'vitest';
import { initial, reduce, stepNumber, validateBlockedReason, type Ctx, type Ev } from '../../resources/js/poste/machine';

const fiche = (over: Partial<Parameters<typeof reduce>[1] extends infer E ? (E extends { type: 'SELECT'; fiche: infer F } ? F : never) : never> = {}) => ({ id: 'v1', identity_done: false, photo_done: false, validated: false, badge_code: null, source: 'listé', host_validation: null, ...over });
const run = (events: Ev[], start: Ctx = initial(), t0 = 1000): Ctx => events.reduce((ctx, ev, i) => reduce(ctx, ev, t0 + i * 1000), start);

describe('automate des 7 étapes', () => {
  it('suit le parcours complet d’un visiteur listé et mesure la durée depuis la première frappe', () => {
    let ctx = reduce(initial(), { type: 'START' }, 1000);
    expect(ctx.startedAt).toBe(1000);
    ctx = run([{ type: 'SELECT', fiche: fiche() }], ctx, 5000); expect(ctx.step).toBe('fiche'); expect(stepNumber[ctx.step]).toBe(2);
    ctx = run([{ type: 'CONFIRM_FICHE' }], ctx); expect(ctx.step).toBe('identite');
    ctx = run([{ type: 'IDENTITY_SAVED' }], ctx); expect(ctx.step).toBe('decision');
    ctx = run([{ type: 'VALIDATED' }], ctx); expect(ctx.step).toBe('badge');
    ctx = run([{ type: 'BADGE_ASSIGNED', code: 'B-014' }], ctx); expect(ctx).toMatchObject({ step: 'remise', badgeCode: 'B-014' });
    ctx = reduce(ctx, { type: 'ENTERED' }, 41000);
    expect(ctx).toMatchObject({ step: 'entree', durationMs: 40000 });
    expect(reduce(ctx, { type: 'NEXT' })).toEqual(initial());
  });
  it('ignore toute transition interdite et ne saute jamais d’étape', () => {
    const start = initial();
    for (const ev of [{ type: 'CONFIRM_FICHE' }, { type: 'IDENTITY_SAVED' }, { type: 'VALIDATED' }, { type: 'ENTERED' }, { type: 'BADGE_ASSIGNED', code: 'B-1' }, { type: 'NEXT' }, { type: 'REFUSED' }] as Ev[]) expect(reduce(start, ev)).toEqual(start);
    const fichePhase = run([{ type: 'SELECT', fiche: fiche() }]);
    expect(reduce(fichePhase, { type: 'VALIDATED' })).toEqual(fichePhase);
    expect(reduce(fichePhase, { type: 'BADGE_ASSIGNED', code: 'B-1' })).toEqual(fichePhase);
    const identity = run([{ type: 'CONFIRM_FICHE' }], fichePhase);
    expect(reduce(identity, { type: 'VALIDATED' })).toEqual(identity);
  });
  it('refuse de valider sans identité et photo, avec la raison', () => {
    expect(validateBlockedReason(initial())).toContain('pièce d’identité et la photo');
    expect(validateBlockedReason({ ...initial(), identityDone: true })).toContain('photo');
    expect(validateBlockedReason({ ...initial(), photoDone: true })).toContain('identité');
    expect(validateBlockedReason({ ...initial(), identityDone: true, photoDone: true })).toBeNull();
    const decision: Ctx = { ...initial(), step: 'decision', visitorId: 'v1' };
    expect(reduce(decision, { type: 'VALIDATED' })).toEqual(decision);
  });
  it('reprend au bon endroit un visiteur déjà avancé et interdit tout retour après un enregistrement irréversible', () => {
    expect(run([{ type: 'SELECT', fiche: fiche({ validated: true, identity_done: true, photo_done: true }) }, { type: 'CONFIRM_FICHE' }]).step).toBe('badge');
    expect(run([{ type: 'SELECT', fiche: fiche({ validated: true, badge_code: 'B-002' }) }, { type: 'CONFIRM_FICHE' }]).step).toBe('remise');
    const badge = run([{ type: 'SELECT', fiche: fiche({ validated: true }) }, { type: 'CONFIRM_FICHE' }]);
    expect(reduce(badge, { type: 'BACK' })).toEqual(badge);
    const back = run([{ type: 'SELECT', fiche: fiche() }, { type: 'CONFIRM_FICHE' }, { type: 'BACK' }, { type: 'BACK' }]);
    expect(back.step).toBe('recherche'); expect(back.visitorId).toBeNull();
  });
  it('mène un refus à son écran final, sans badge', () => {
    const ctx = run([{ type: 'SELECT', fiche: fiche() }, { type: 'CONFIRM_FICHE' }, { type: 'IDENTITY_SAVED' }, { type: 'REFUSED' }]);
    expect(ctx).toMatchObject({ step: 'refus', badgeCode: null, validated: false });
    expect(reduce(ctx, { type: 'BADGE_ASSIGNED', code: 'B-1' })).toEqual(ctx);
    expect(reduce(ctx, { type: 'NEXT' })).toEqual(initial());
  });
  it('attend l’accord de l’hôte pour un non listé avant l’identité', () => {
    let ctx = run([{ type: 'NEW_UNLISTED' }, { type: 'UNLISTED_CREATED', visitorId: 'n1' }]);
    expect(ctx).toMatchObject({ step: 'attente_hote', unlisted: true, hostAccepted: false });
    expect(reduce(ctx, { type: 'IDENTITY_SAVED' })).toEqual(ctx);
    const refused = reduce(ctx, { type: 'HOST_DECISION', accepted: false });
    expect(refused.hostRefused).toBe(true); expect(reduce(refused, { type: 'NEXT' })).toEqual(initial());
    ctx = reduce(ctx, { type: 'HOST_DECISION', accepted: true }); expect(ctx).toMatchObject({ step: 'identite', hostAccepted: true });
    // un non listé retrouvé par la recherche retourne à l'attente tant que l'hôte n'a pas accepté
    const again = run([{ type: 'SELECT', fiche: fiche({ source: 'non listé', host_validation: 'en_attente' }) }, { type: 'CONFIRM_FICHE' }]);
    expect(again.step).toBe('attente_hote');
  });
});
