import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
  router: { post: vi.fn(), delete: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: () => ({ full_name: '', reason: '', errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }),
  usePage: () => ({ props: { flash: { status: null }, errors: {} } }),
}));
import Interdits from '../../resources/js/pages/Autorite/Interdits.vue';
import StepDecision from '../../resources/js/components/poste/StepDecision.vue';
import { LocalList, type Snapshot, type SnapVisitor } from '../../resources/js/poste/local/list';
import { applyOp, ficheOf, type LocalOp } from '../../resources/js/poste/local/apply';
import { ApiError } from '../../resources/js/poste/types';

const now = Date.now();
const iso = (o: number) => new Date(now + o).toISOString();
const visitor = (over: Partial<SnapVisitor> = {}): SnapVisitor => ({ id: 'v1', full_name: 'Invité fictif', organisation: null, host_id: null, category_id: 'c1', valid_from: iso(-3_600_000), valid_until: iso(3_600_000), source: 'listé', status: 'attendu', host_validation: null, rev: iso(0), identity_done: true, photo_done: true, validated: false, badge_code: null, ...over } as SnapVisitor);
const list = (v: SnapVisitor) => new LocalList({ version: 'v', server_time: iso(0), poste: { id: 'p', name: 'P', station: 'S', event_id: 'e' }, event: { id: 'e', name: 'E', starts_at: iso(-1), ends_at: iso(1) }, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [{ id: 'c1', name: 'Visiteur', color: '#000', priority_circuit: false }], zones: [], category_zone: [], hosts: [], postes: [], visitors: [v], badges: [] } as unknown as Snapshot);
const op = (avis: string | null): LocalOp => ({ id: 'o1', seq: 1, type: 'valider', agent_id: 'a', client_time: iso(0), visitor_rev: null, payload: { visitor_id: 'v1', avis } });

describe('personne signalée (A2)', () => {
  it('montre la fiche comme signalée et bloque la validation hors ligne sans l’avis du superviseur', () => {
    const l = list(visitor({ watch: true }));
    expect(ficheOf(l, 'v1', now).watch).toBe(true);
    expect(() => applyOp(l, op(null), now)).toThrowError(ApiError);
    expect(() => applyOp(l, op('hote'), now)).toThrowError(/Personne signalée/);
    expect(() => applyOp(l, op('superviseur'), now)).not.toThrow();
  });

  it('ne gêne pas un visiteur non signalé', () => {
    expect(() => applyOp(list(visitor()), op(null), now)).not.toThrow();
  });

  it('exige de cocher l’avis du superviseur avant de pouvoir valider', async () => {
    const w = mount(StepDecision, { props: { name: 'Invité fictif', horsPeriode: false, blockedReason: null, signalee: true, busy: false } });
    expect(w.find('[data-testid=avis-signalee]').exists()).toBe(true);
    expect(w.get('.btn-ok').attributes('disabled')).toBeDefined();
    await w.get('[data-testid=avis-signalee] input').setValue(true);
    expect(w.get('.btn-ok').attributes('disabled')).toBeUndefined();
    await w.get('.btn-ok').trigger('click');
    expect(w.emitted('validate')?.[0]).toEqual(['superviseur']);
  });

  it('écran de l’officier : liste les entrées avec leur motif et propose de lever', () => {
    const w = mount(Interdits, { props: { entries: [{ id: 'x1', full_name: 'Personne fictive', reason: 'Motif fictif', created_at: '2027-01-10T10:00:00Z', added_by: 'Officier fictif' }] } });
    expect(w.get('[data-testid=liste-interdits]').text()).toContain('Motif fictif');
    expect(w.find('[data-testid=lever]').exists()).toBe(true);
    expect(mount(Interdits, { props: { entries: [] } }).find('[data-testid=liste-vide]').exists()).toBe(true);
  });
});
