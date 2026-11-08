import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'Superviseur fictif', permissions: ['pilotage.consulter', 'presences.consulter', 'alertes.consulter'] }, pilotageContext: null } }),
  router: { get, post: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import Mouvements from '../../resources/js/pages/Pilotage/Mouvements.vue';
import Acces from '../../resources/js/pages/Pilotage/Acces.vue';

const ctx = { event: { id: 'e1', name: 'Stage A' }, events: [{ id: 'e1', name: 'Stage A' }], openAlerts: 0 };

describe('centre de supervision', () => {
  it('affiche les mouvements avec leur résultat et un lien vers la fiche', () => {
    const row = (over: object) => ({ id: 'm', occurred_at: '2027-01-10T10:00:00Z', type: 'controle', checkpoint: 'Espace VIP', visitor_id: 'v1', full_name: 'Koné Mariam', badge_code: 'B-001', verdict: 'valide', zone_ok: 'false', corrected: false, corrige_id: null, ...over });
    const wrapper = mount(Mouvements, { props: { ...ctx, filters: { q: '', type: '', point: '', outcome: '', from: '', until: '' }, rows: [row({}), row({ id: 'n', type: 'sortie', verdict: null, zone_ok: null, corrected: true })], total: 2, page: 1, perPage: 30, points: ['Espace VIP'], types: ['entree', 'sortie', 'controle', 'correction'] } });
    const items = wrapper.findAll('[data-testid=mouvement]');
    expect(items).toHaveLength(2);
    expect(items[0].text()).toContain('Zone non autorisée');
    expect(items[0].text()).toContain('Badge B-001');
    expect(items[1].text()).toContain('Sortie');
    expect(items[1].text()).toContain('Corrigé par un événement ultérieur');
    expect(wrapper.get('[data-testid=total]').text()).toBe('2 mouvements');
    expect(wrapper.find('a[href*="/pilotage/visiteurs/v1"]').exists()).toBe(true);
  });

  it('montre les badges à récupérer et les habilitations en lecture seule (aucune action de modification)', () => {
    const wrapper = mount(Acces, { props: { ...ctx,
      badgesToReturn: [{ id: 'a1', badge_id: 'b1', code: 'B-001', visitor_id: 'v1', full_name: 'Koné Mariam', status: 'sorti', closed_at: null, assigned_at: '2027-01-10T08:00:00Z' }],
      entitlements: [{ id: 'h1', visitor_id: 'v1', full_name: 'Koné Mariam', zone: 'Espace VIP', kind: 'exceptionnelle' as const, reason: 'Visite officielle', valid_from: '2027-01-10T09:00:00Z', valid_until: '2027-01-10T18:00:00Z', suspension_reason: null, state: 'active' as const }] } });
    expect(wrapper.get('[data-testid=badge-a-recuperer]').text()).toContain('B-001');
    expect(wrapper.get('[data-testid=habilitation]').text()).toContain('Exceptionnelle · Active');
    expect(wrapper.get('[data-testid=habilitations]').text()).toContain('Consultation seule');
    expect(wrapper.findAll('[data-testid=habilitations] button')).toHaveLength(0);
  });
});
