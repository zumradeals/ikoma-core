import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'Superviseur fictif', permissions: ['pilotage.consulter', 'cloture.superviseur'] }, errors: {} } }),
  router: { get: vi.fn(), post, reload: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import CentreSupervision, { type Centre } from '../../resources/js/components/pilotage/CentreSupervision.vue';

const centre = (over: Partial<Centre> = {}): Centre => ({
  now: '2027-01-10T10:00:00.000Z',
  postes: [
    { id: 'd1', name: 'Poste 2 fictif', point_code: 'poste_2', state: 'actif', last_synced_at: '2027-01-10T09:59:00Z', last_operation: { type: 'entree', at: '2027-01-10T09:58:30Z', accepted: true } },
    { id: 'd2', name: 'Soute fictive', point_code: 'soute', state: 'silencieux', last_synced_at: '2027-01-10T08:00:00Z', last_operation: { type: 'controle', at: '2027-01-10T08:01:00Z', accepted: false } },
  ],
  operations: [{ id: 'o1', type: 'entree', at: '2027-01-10T09:58:30Z', accepted: true, poste: 'Poste 2 fictif' }],
  presents: { count: 3, rows: [{ id: 'p1', full_name: 'Koné Mariam', zone: null, entered_at: '2027-01-10T09:00:00Z', category: { name: 'VIP', color: '#C9962B', priority_circuit: true } }] },
  movements: [{ id: 'm1', occurred_at: '2027-01-10T09:58:30Z', type: 'entree', checkpoint: 'Poste 2', visitor_id: 'p1', full_name: 'Koné Mariam', badge_code: 'B-001', verdict: null, zone_ok: null }],
  visits: { counts: { attendu: 2, en_retard: 1, absent: 0 }, late_after_minutes: 15, rows: [{ id: 'v1', full_name: 'Traoré Awa', expected_at: '2027-01-10T09:00:00Z', state: 'en_retard', plates: 'AB123', host: null }] },
  vehicles: [{ id: 'x1', plate: 'AB123CD', kind: 'voiture', entered_at: '2027-01-10T09:30:00Z', visitor_id: 'v1', full_name: 'Traoré Awa' }], vehicles_count: 1,
  badges_to_return: { count: 1, rows: [{ id: 'a1', code: 'B-007', visitor_id: 'v2', full_name: 'Diallo Sékou', status: 'sorti', closed_at: null, assigned_at: '2027-01-10T08:00:00Z' }] },
  refusals: { pending: 2, rows: [{ id: 'r1', reason: 'Badge annulé', refused_at: '2027-01-10T09:50:00Z', decision: null, visitor_id: 'v3', full_name: 'Yéo Refus' }] },
  incidents: [{ id: 'i1', occurred_at: '2027-01-10T09:55:00Z', checkpoint: 'Soute', visitor_id: 'v4', full_name: 'Bamba Second', badge_code: 'B-009', zone_ok: 'false' }],
  closable: [{ id: 'c1', full_name: 'Gnagne Honneur', status: 'present', category: 'Personnalité', color: '#C9962B', badge_code: 'B-100', vehicles_present: 0 }],
  ...over,
});
const mountCentre = (c: Centre = centre(), canClose = true) => mount(CentreSupervision, { props: { centre: c, eventId: 'e1', eventName: 'Stage fictif', canClose } });

beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(new Date('2027-01-10T10:00:00.000Z')); post.mockReset(); });
afterEach(() => { vi.useRealTimers(); });

describe('centre de supervision', () => {
  it('affiche l’heure à la seconde, calée sur le serveur, et la fait avancer', async () => {
    const wrapper = mountCentre(centre({ now: '2027-01-10T10:00:30.000Z' })); // l'appareil retarde de 30 s sur le serveur
    const first = wrapper.get('[data-testid=horloge]').text();
    expect(first).toMatch(/^\d{2}:\d{2}:\d{2}$/);
    expect(first.endsWith(':30')).toBe(true);
    await vi.advanceTimersByTimeAsync(3000);
    expect(wrapper.get('[data-testid=horloge]').text().endsWith(':33')).toBe(true);
    expect(wrapper.get('[data-testid=date-heure]').text()).toContain('Stage fictif');
    wrapper.unmount();
  });

  it('montre simultanément postes, présents, visites, véhicules, badges, refus et clôture', () => {
    const wrapper = mountCentre();
    for (const id of ['postes', 'presents', 'visites', 'vehicules', 'badges', 'refus', 'cloture']) expect(wrapper.find(`[data-testid=centre-${id}]`).exists(), id).toBe(true);
    expect(wrapper.get('[data-testid=presents-total]').text()).toContain('3');
    expect(wrapper.get('[data-testid=poste-d2]').text()).toContain('Sans nouvelles');
    expect(wrapper.get('[data-testid=poste-d2]').text()).toContain('refusée');
    expect(wrapper.get('[data-testid=dernieres-operations]').text()).toContain('Entrée');
    expect(wrapper.get('[data-testid=visites-retard]').text()).toContain('1 en retard');
    expect(wrapper.get('[data-testid=vehicules-total]').text()).toBe('1');
    expect(wrapper.get('[data-testid=badges-total]').text()).toBe('1');
    expect(wrapper.get('[data-testid=refus-compteur]').text()).toContain('2 refus à décider');
    expect(wrapper.get('[data-testid=centre-badges]').text()).toContain('la sortie ne vaut pas restitution');
    wrapper.unmount();
  });

  it('ouvre un panneau de détail sans retirer le tableau de bord, puis le ferme', async () => {
    const wrapper = mountCentre();
    expect(wrapper.find('[data-testid=panneau-detail]').exists()).toBe(false);
    await wrapper.get('[data-testid=detail-vehicules]').trigger('click');
    expect(wrapper.get('[data-testid=panneau-detail]').text()).toContain('AB123CD');
    expect(wrapper.find('[data-testid=centre-presents]').exists()).toBe(true);
    await wrapper.get('[data-testid=detail-refus]').trigger('click');
    expect(wrapper.get('[data-testid=detail-liste-refus]').text()).toContain('Yéo Refus');
    expect(wrapper.get('[data-testid=panneau-detail]').text()).toContain('Zone non autorisée');
    await wrapper.get('[data-testid=fermer-detail]').trigger('click');
    expect(wrapper.find('[data-testid=panneau-detail]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('propose la clôture réservée dans le panneau, avec motif puis confirmation, vers la route du superviseur', async () => {
    const wrapper = mountCentre();
    await wrapper.get('[data-testid=detail-cloture]').trigger('click');
    await wrapper.get('[data-testid=choisir-cloture-c1]').trigger('click');
    expect(wrapper.get('[data-testid=cloture-cible]').text()).toBe('Gnagne Honneur');
    await wrapper.get('[data-testid=cloture-debut]').trigger('click');
    await wrapper.get('[data-testid=cloture-motif]').setValue('Fin de la visite officielle');
    await wrapper.get('[data-testid=cloture-suite]').trigger('click');
    expect(post).not.toHaveBeenCalled();
    await wrapper.get('[data-testid=cloture-confirmer]').trigger('click');
    expect(post).toHaveBeenCalledWith('/pilotage/visiteurs/c1/cloture-superviseur', { reason: 'Fin de la visite officielle', confirmation: true }, expect.any(Object));
    wrapper.unmount();
  });

  it('n’offre aucune action de clôture sans le droit dédié', () => {
    const wrapper = mountCentre(centre({ closable: [] }), false);
    expect(wrapper.find('[data-testid=centre-cloture]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('indique explicitement l’absence de poste, de véhicule et de visite', async () => {
    const wrapper = mountCentre(centre({ postes: [], operations: [], vehicles: [], vehicles_count: 0, visits: { counts: {}, late_after_minutes: 15, rows: [] }, movements: [], incidents: [], refusals: { pending: 0, rows: [] }, badges_to_return: { count: 0, rows: [] } }));
    expect(wrapper.get('[data-testid=aucun-poste]').text()).toContain('Aucun poste');
    expect(wrapper.get('[data-testid=aucun-vehicule-centre]').text()).toContain('Aucun véhicule');
    expect(wrapper.get('[data-testid=centre-visites]').text()).toContain('Aucune visite annoncée');
    wrapper.unmount();
  });
});
