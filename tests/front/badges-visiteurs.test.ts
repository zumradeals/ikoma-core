import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['badges.gerer'] } } }),
  router: { get: vi.fn(), post: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => ({ ...data, errors: {}, processing: false, get: vi.fn(), post: vi.fn(), put: vi.fn(), reset: vi.fn(), transform() { return this; } }),
}));

import Badges from '../../resources/js/pages/Admin/Badges.vue';

const base = {
  badges: { data: [
    { id: 'b1', code: 'B-001', status: 'Disponible', batch_name: 'Lot A', reserved_station: null, reserved_group: null, category_name: 'Visiteur', category_color: '#0066B3', category_priority: false },
    { id: 'b2', code: 'B-002', status: 'Disponible', batch_name: 'Lot A', reserved_station: 'Accueil', reserved_group: null, category_name: 'Visiteur', category_color: '#0066B3', category_priority: false },
    { id: 'b3', code: 'B-003', status: 'Associé', batch_name: 'Lot A', reserved_station: null, reserved_group: null, category_name: 'Visiteur', category_color: '#0066B3', category_priority: false },
  ], total: 3, prev_page_url: null, next_page_url: null },
  batchCounts: { l1: [{ status: 'Disponible', total: 8, reserved: 3 }, { status: 'Associé', total: 2, reserved: 0 }] },
  batches: [{ id: 'l1', name: 'Lot A', event_id: 'e1', event_name: 'Événement fictif', quantity: 10, margin: 1, category_name: 'Visiteur', first_number: 1, last_number: 10 }],
  statuses: ['Disponible', 'Associé'], filters: {}, events: [{ id: 'e1', name: 'Événement fictif' }], categories: [], devices: [], groups: [], settings: { margin: 10, reusable: false },
};

describe('page Badges visiteurs en trois temps', () => {
  it('annonce les trois étapes et le nombre de badges disponibles non affectés', () => {
    const wrapper = mount(Badges, { props: base, global: { stubs: { BadgeLotForm: true, BadgeReservationForm: true, BadgeSettingsForm: true } } });
    expect(wrapper.get('[data-testid=etapes-badges]').text()).toContain('Fabriquer');
    expect(wrapper.get('[data-testid=nb-a-affecter]').text()).toContain('5 sans poste');
    expect(wrapper.get('[data-testid=alerte-sans-poste]').text()).toContain('5 badge(s)');
    expect(wrapper.get('[data-testid=ouvrir-atelier]').attributes('href')).toBe('/administration/atelier?vue=atelier');
    expect(wrapper.find('[data-testid=lien-cartes-permanentes]').exists()).toBe(false);
  });

  it('range les réglages dans une zone repliée et liste les badges dans un tableau', () => {
    const wrapper = mount(Badges, { props: base, global: { stubs: { BadgeLotForm: true, BadgeReservationForm: true, BadgeSettingsForm: true } } });
    expect(wrapper.get('[data-testid=reglages-badges]').element.tagName).toBe('DETAILS');
    expect(wrapper.get('[data-testid=reglages-badges]').attributes('open')).toBeUndefined();
    const rows = wrapper.findAll('[data-testid^="badge-B-"]');
    expect(rows).toHaveLength(3);
    expect(rows[0].text()).toContain('Sans réservation');
    expect(rows[1].text()).toContain('Poste réservé : Accueil');
    expect(rows[2].text()).not.toContain('inutilisable');
  });

  it('n’alerte pas quand tous les badges disponibles sont affectés', () => {
    const wrapper = mount(Badges, { props: { ...base, batchCounts: { l1: [{ status: 'Disponible', total: 4, reserved: 4 }] } }, global: { stubs: { BadgeLotForm: true, BadgeReservationForm: true, BadgeSettingsForm: true } } });
    expect(wrapper.find('[data-testid=alerte-sans-poste]').exists()).toBe(false);
    expect(wrapper.get('[data-testid=nb-a-affecter]').text()).toContain('0 sans poste');
  });
});
