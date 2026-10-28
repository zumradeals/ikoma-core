import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StepIdentity from '../../resources/js/components/poste/StepIdentity.vue';
import { LocalList, type Snapshot, type SnapVisitor } from '../../resources/js/poste/local/list';
import { applyOp, ficheOf, type LocalOp } from '../../resources/js/poste/local/apply';

const now = Date.now();
const iso = (o: number) => new Date(now + o).toISOString();
const v = { id: 'v1', full_name: 'Invité fictif', organisation: null, host_id: null, category_id: 'c1', valid_from: iso(-3_600_000), valid_until: iso(3_600_000), source: 'listé', status: 'attendu', host_validation: null, rev: iso(0), identity_done: false, photo_done: false, validated: false, badge_code: null } as SnapVisitor;
const list = (active: boolean) => new LocalList({ version: 'v', server_time: iso(0), poste: { id: 'p', name: 'P', station: 'S', event_id: 'e' }, event: { id: 'e', name: 'E', starts_at: iso(-1), ends_at: iso(1) }, settings: { identity_document: active, inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [{ id: 'c1', name: 'Visiteur', color: '#000', priority_circuit: false }], zones: [], category_zone: [], hosts: [], postes: [], visitors: [{ ...v }], badges: [] } as unknown as Snapshot);
const op = (payload: Record<string, unknown>): LocalOp => ({ id: 'o1', seq: 1, type: 'identite', agent_id: 'a', client_time: iso(0), visitor_rev: null, payload: { visitor_id: 'v1', identity_checked: true, ...payload } });

describe('pièce d’identité photographiée (option de l’administrateur), hors ligne', () => {
  it('ne demande rien par défaut : l’accueil reste inchangé', () => {
    const l = list(false);
    expect(ficheOf(l, 'v1', now).identity_document).toBe(false);
    expect(() => applyOp(l, op({}), now)).not.toThrow();
    expect(l.visitors.get('v1')?.identity_done).toBe(true);
  });
  it('exige le type et la photo quand l’option est activée, le numéro restant facultatif', () => {
    expect(ficheOf(list(true), 'v1', now).identity_document).toBe(true);
    expect(() => applyOp(list(true), op({}), now)).toThrowError(/photographiez-la/);
    expect(() => applyOp(list(true), op({ document_type: 'cni' }), now)).toThrowError(/photographiez-la/);
    expect(() => applyOp(list(true), op({ document_type: 'inconnu', document_photo: true }), now)).toThrowError(/photographiez-la/);
    expect(() => applyOp(list(true), op({ document_type: 'cni', document_photo: true, document_number: 'AB' }), now)).toThrowError(/numéro/);
    expect(() => applyOp(list(true), op({ document_type: 'cni', document_photo: true }), now)).not.toThrow();
    expect(() => applyOp(list(true), op({ document_type: 'passeport', document_photo: true, document_number: ' ci 01.23456 ' }), now)).not.toThrow();
  });
});

describe('étape identité', () => {
  it('n’affiche pas le bloc pièce par défaut', () => {
    expect(mount(StepIdentity, { props: { name: 'Invité fictif', busy: false } }).find('[data-testid=bloc-piece]').exists()).toBe(false);
  });
  it('affiche le type, le numéro facultatif et la photo de la pièce quand l’option est activée', () => {
    const w = mount(StepIdentity, { props: { name: 'Invité fictif', busy: false, documentRequired: true } });
    expect(w.find('[data-testid=bloc-piece]').exists()).toBe(true);
    expect(w.find('[data-testid=piece-type]').exists()).toBe(true);
    expect(w.find('[data-testid=photo-piece]').exists()).toBe(true);
    expect(w.text()).toContain('Numéro (facultatif)');
  });
});
