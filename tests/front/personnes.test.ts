import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
let formErrors: Record<string, string> = {};
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['listes.importer'] } } }),
  router: { get, post },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => { const form: Record<string, unknown> = reactive({ ...data, errors: formErrors, processing: false, post, reset: vi.fn() }); form.transform = () => form; return form; },
}));

import Personnes from '../../resources/js/pages/Admin/Personnes.vue';
import Personne from '../../resources/js/pages/Admin/Personne.vue';

const person = { id: 'p1', full_name: 'Koné Mariam', organisation: 'Atelier fictif', status: 'actif' };
const participation = (over: object = {}) => ({ id: 'v1', event_id: 'e1', event_name: 'Stage de sécurité', event_kind: 'stage', category: 'Stagiaire', status: 'sorti', valid_from: '2027-01-10T08:00:00Z', valid_until: '2027-01-14T18:00:00Z', withdrawn_at: null, closed_at: null, closure_reason: null, holder_id: null, ...over });

describe('registre des personnes', () => {
  it('liste les personnes avec leurs participations et propose la recherche et la création', () => {
    formErrors = {};
    const wrapper = mount(Personnes, { props: { query: '', persons: [{ id: 'p1', full_name: 'Koné Mariam', organisation: null, status: 'actif', participations: 2, permanent: false }, { id: 'p2', full_name: 'Awa Fictive', organisation: null, status: 'actif', participations: '0', permanent: true }] } });
    expect(wrapper.get('[data-testid=ouvrir-personne-p1]').attributes('href')).toBe('/administration/personnes/p1');
    expect(wrapper.text()).toContain('2 participations');
    expect(wrapper.text()).toContain('Personnel permanent');
    expect(wrapper.find('[data-testid=form-personne]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=confirmer-homonyme]').exists()).toBe(false);
  });

  it('demande une confirmation explicite quand une personne de même nom existe', () => {
    formErrors = { full_name: 'Une personne de même nom existe déjà (1). Reprenez-la depuis la recherche, ou confirmez qu’il s’agit d’une autre personne.' };
    const wrapper = mount(Personnes, { props: { query: '', persons: [] } });
    expect(wrapper.get('[data-testid=confirmer-homonyme]').text()).toContain('autre personne');
    expect(wrapper.find('[data-testid=aucune-personne]').exists()).toBe(true);
    formErrors = {};
  });
});

describe('fiche d’une personne', () => {
  const base = { person, participations: [participation(), participation({ id: 'v2', event_id: 'e2', event_name: 'Visite fictive', event_kind: 'visite', closed_at: '2027-02-01T10:00:00Z', closure_reason: 'Visite terminée' })], assignments: [{ id: 'a1', badge_code: 'B-007', badge_id: 'b7', event_name: 'Stage de sécurité', assigned_at: '2027-01-10T09:00:00Z', returned_at: '2027-01-14T17:00:00Z', cancelled_at: null, cancellation_reason: null }], holder: null,
    events: [{ id: 'e3', name: 'Stage suivant', kind: 'stage' }, { id: 'e4', name: 'Autre activité', kind: 'evenement' }], categories: [{ id: 'c3', event_id: 'e3', name: 'Stagiaire' }, { id: 'c4', event_id: 'e4', name: 'Visiteur' }], hosts: [] };

  it('affiche l’historique des participations, la nature des activités et les badges restitués', () => {
    formErrors = {};
    const wrapper = mount(Personne, { props: base });
    expect(wrapper.get('[data-testid=nom-personne]').text()).toBe('Koné Mariam');
    expect(wrapper.get('[data-testid=participation-v1]').text()).toContain('Stage');
    expect(wrapper.get('[data-testid=participation-v2]').text()).toContain('Clôturée');
    expect(wrapper.get('[data-testid=participation-v2]').text()).toContain('Visite terminée');
    expect(wrapper.get('[data-testid=liste-attributions]').text()).toContain('B-007');
    expect(wrapper.get('[data-testid=liste-attributions]').text()).toContain('Restitué');
  });

  it('n’offre la clôture que pour une participation ouverte, jamais pour un titulaire permanent', () => {
    const wrapper = mount(Personne, { props: { ...base, participations: [participation(), participation({ id: 'v2', closed_at: '2027-02-01T10:00:00Z', closure_reason: 'x' }), participation({ id: 'v3', holder_id: 'h1' })] } });
    expect(wrapper.find('[data-testid=cloturer-v1]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=cloturer-v2]').exists()).toBe(false);
    expect(wrapper.find('[data-testid=cloturer-v3]').exists()).toBe(false);
  });

  it('ne propose à l’inscription que les catégories de l’activité choisie, et envoie à la bonne route', async () => {
    post.mockClear();
    const wrapper = mount(Personne, { props: base });
    const options = () => wrapper.findAll('[data-testid=form-inscription] select')[1].findAll('option').map(o => o.text());
    expect(options()).toContain('Stagiaire');
    expect(options()).not.toContain('Visiteur');
    await wrapper.findAll('[data-testid=form-inscription] select')[0].setValue('e4');
    expect(options()).toContain('Visiteur');
    await wrapper.get('[data-testid=form-inscription]').trigger('submit');
    expect(post).toHaveBeenCalledWith('/administration/personnes/p1/participations');
  });

  it('indique clairement l’absence de participation, de badge et d’activité ouverte', () => {
    const wrapper = mount(Personne, { props: { ...base, participations: [], assignments: [], events: [], categories: [] } });
    expect(wrapper.find('[data-testid=aucune-participation]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=aucun-badge]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=aucune-activite]').exists()).toBe(true);
  });
});
