import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['badges.gerer', 'listes.importer', 'categories.gerer'] as string[] }, badgesCartes: null as unknown } }));
const put = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => page,
  router: { get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: Record<string, unknown>) => {
    const keys = Object.keys(data);
    const form = reactive({ ...data, errors: {}, processing: false, put, defaults: () => form, reset: vi.fn(), clearErrors: vi.fn(), data: () => Object.fromEntries(keys.map(key => [key, form[key as keyof typeof form]])) });
    return form;
  },
}));

import Modeles from '../../resources/js/pages/Admin/Modeles.vue';

describe('Page Modèles institutionnels', () => {
  const cards = { categories: [{ code: 'AILCT-01', label: 'Catégorie un' }], selected: 'AILCT-01', model: { category_code: 'AILCT-01', label: 'Catégorie un', color: '#2E7D32', mention: 'M', show_validity: true, qr_side: 'verso', version: 1, status: 'brouillon', validated: false, validated_at: null, validated_by: null, saved: false }, preview: { recto: '<i>r</i>', verso: '<i>v</i>' }, settings: { ligne1: '', ligne2: '', contact: '', avis: '' }, limits: { ligne1: 80, ligne2: 80, contact: 80, avis: 160 }, initialized: true, canConfigure: true };
  const mountPage = () => mount(Modeles, { props: { cards } as never, global: { stubs: { AdminLayout: { template: '<main><slot /></main>' } } } });

  it('montre le cadre à trois onglets et les modèles institutionnels par catégorie', async () => {
    const wrapper = mountPage(); await flushPromises();
    expect(wrapper.get('[data-testid="onglet-modeles"]').attributes('aria-current')).toBe('page');
    expect(wrapper.findAll('[data-testid^="onglet-"]').map(a => a.attributes('data-testid'))).toEqual(['onglet-registre', 'onglet-modeles', 'onglet-impression', 'onglet-lots']);
    expect(wrapper.find('[data-testid="page-modeles-cartes"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="choix-categorie"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="enregistrer-modele"]').exists()).toBe(true);
  });

  it('ne propose plus l’ancien modèle des badges de lots par activité', async () => {
    const wrapper = mountPage(); await flushPromises();
    for (const gone of ['modele-historique', 'bloc-modele-badge', 'modele-type-badges', 'modele-type-cartes']) expect(wrapper.find(`[data-testid="${gone}"]`).exists()).toBe(false);
    expect(wrapper.text()).not.toMatch(/stock existant|par activité/);
  });
});
