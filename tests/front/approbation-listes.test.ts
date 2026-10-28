import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
  router: { post: (...a: unknown[]) => post(...a), reload: vi.fn(), get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  usePage: () => ({ props: { flash: { status: null }, errors: {} } }),
}));
import Listes from '../../resources/js/pages/Autorite/Listes.vue';
import { assertApproved } from '../../resources/js/poste/local/approval';

const event = { id: 'e1', name: 'Événement fictif' };
const batches = [{ key: 'b1', label: 'Import fictif', count: 2, created_at: '2027-01-10T10:00:00Z' }];
const visitors = [
  { id: 'v1', full_name: 'Invité Un', organisation: null, category: 'Visiteur', host: null },
  { id: 'v2', full_name: 'Invité Deux', organisation: null, category: 'Visiteur', host: null },
];

describe('approbation des listes (A3)', () => {
  beforeEach(() => { post.mockClear(); vi.stubGlobal('confirm', () => true); window.confirm = () => true; });

  it('affiche un message quand rien n’attend', () => {
    const w = mount(Listes, { props: { event, batches: [], selected: '', visitors: [] } });
    expect(w.find('[data-testid=rien-a-approuver]').exists()).toBe(true);
  });

  it('liste les invités en attente par lot', () => {
    const w = mount(Listes, { props: { event, batches, selected: 'b1', visitors } });
    expect(w.text()).toContain('Invité Un');
    expect(w.text()).toContain('Invité Deux');
  });

  it('bloque l’accueil d’un invité non approuvé ou refusé, pas d’un invité approuvé', () => {
    expect(() => assertApproved({ approval: 'approuve' })).not.toThrow();
    expect(() => assertApproved({ approval: undefined })).not.toThrow();
    expect(() => assertApproved({ approval: 'a_approuver' })).toThrowError(/attend l’approbation/);
    expect(() => assertApproved({ approval: 'refuse' })).toThrowError(/n’a pas approuvé/);
  });
});
