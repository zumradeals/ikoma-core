import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const { put, post } = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { errors: {}, auth: { name: 'Administrateur fictif', permissions: ['postes.gerer'] } } }),
  router: { put, post, get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn() }),
}));

import Postes from '../../resources/js/pages/Admin/Postes.vue';

const poste = (over: object = {}) => ({ id: 'p1', name: 'Tablette fictive', station: 'Entrée', point_code: 'poste_2', event_id: null, event_name: 'Aucune activité en cours', activities: ['e1'], state: 'non_enrole', enrolled_at: null, ...over });
const props = (postes: object[]) => ({ events: [{ id: 'e1', name: 'Stage fictif' }], postes, points: { poste_2: 'Poste 2' }, settings: { inactivity: 5, skew: 300, pin: 15, pointsDelay: 15, burstCount: 3, burstMinutes: 10 }, newCode: null, conflicts: [] });

describe('écran Postes : l’activité du jour se choisit sur la tablette', () => {
  it('ne propose plus de choisir une « activité en cours » : il l’affiche, avec le mode site quand elle est vide', () => {
    const w = mount(Postes, { props: props([poste()]) });
    expect(w.text()).not.toContain('Définir comme activité en cours');
    expect(w.get('[data-testid="activites-p1"]').find('select').exists()).toBe(false);
    expect(w.get('[data-testid="activite-en-cours-p1"]').text()).toContain('aucune (mode site)');
    expect(w.get('[data-testid="activite-en-cours-p1"]').text()).toContain('L’agent choisit l’activité du jour sur la tablette');
    // Les activités autorisées restent réglables.
    expect(w.get('[data-testid="activites-p1"]').text()).toContain('Stage fictif');
  });

  it('affiche le nom de l’activité en cours quand il y en a une', () => {
    const w = mount(Postes, { props: props([poste({ event_id: 'e1', event_name: 'Stage fictif', state: 'enrole' })]) });
    expect(w.get('[data-testid="activite-en-cours-p1"]').text()).toContain('Stage fictif');
    expect(w.text()).not.toContain('aucune (mode site)');
  });
});
