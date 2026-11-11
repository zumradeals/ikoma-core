import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['listes.importer', 'badges.gerer', 'categories.gerer'] } } }),
  router: { get: vi.fn(), post, put: vi.fn(), delete: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => ({ ...data, errors: {}, processing: false, get: vi.fn(), post: vi.fn(), put: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), transform() { return this; } }),
}));

import Titulaires from '../../resources/js/pages/Admin/Titulaires.vue';

const base = {
  holders: { data: [], links: [], total: 0 }, filters: { q: '', statut: 'actif', categorie: '', photo: '' }, categories: [], importPreview: null, bulkMax: 200,
  identifierFormat: { prefix: 'ID', width: 6, check: true }, identifierExample: 'ID-000001-8', permanentContext: { id: 'c1', name: 'Contexte permanent' }, canConfigure: true, canCards: true,
  pipeline: { sans_carte: 3, sans_photo: 2, a_imprimer: 4, a_remettre: 1, actives: 7, prochaine_remise: 'h9' },
};

describe('parcours des cartes permanentes en trois étapes', () => {
  it('affiche les compteurs de chaque étape et le lien vers la prochaine remise', () => {
    const wrapper = mount(Titulaires, { props: base });
    expect(wrapper.get('[data-testid=nb-sans-carte]').text()).toBe('3');
    expect(wrapper.get('[data-testid=nb-sans-photo]').text()).toContain('2 autre(s) sans photo');
    expect(wrapper.get('[data-testid=nb-a-imprimer]').text()).toBe('4');
    expect(wrapper.get('[data-testid=nb-a-remettre]').text()).toBe('1');
    expect(wrapper.get('[data-testid=nb-actives]').text()).toContain('7');
    expect(wrapper.get('[data-testid=aller-atelier]').attributes('href')).toBe('/administration/badges?type=carte_permanente');
    expect(wrapper.get('[data-testid=remettre-suivante]').attributes('href')).toBe('/administration/titulaires/h9/carte');
  });

  it('« Tout préparer » envoie la demande ; il est désactivé quand rien n’est à préparer', async () => {
    post.mockClear();
    const wrapper = mount(Titulaires, { props: base });
    await wrapper.get('[data-testid=tout-preparer]').trigger('click');
    expect(post).toHaveBeenCalledWith('/administration/titulaires/cartes/preparer-toutes', {}, expect.anything());
    const idle = mount(Titulaires, { props: { ...base, pipeline: { ...base.pipeline, sans_carte: 0, sans_photo: 0, prochaine_remise: null } } });
    expect(idle.get('[data-testid=tout-preparer]').attributes('disabled')).toBeDefined();
    expect(idle.find('[data-testid=remettre-suivante]').exists()).toBe(false);
    expect(idle.find('[data-testid=nb-sans-photo]').exists()).toBe(false);
  });

  it('n’affiche le parcours qu’à qui gère les badges ; le registre technique des cartes n’est plus une étape visible', () => {
    expect(mount(Titulaires, { props: { ...base, canCards: false, pipeline: null } }).find('[data-testid=parcours-cartes]').exists()).toBe(false);
    expect(mount(Titulaires, { props: { ...base, permanentContext: null } }).find('[data-testid=parcours-cartes]').exists()).toBe(true);
    expect(mount(Titulaires, { props: { ...base, permanentContext: null } }).find('[data-testid=creer-contexte]').exists()).toBe(false);
  });
});
