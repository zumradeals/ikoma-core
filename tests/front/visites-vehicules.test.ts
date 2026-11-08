import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
const { formPost, formDelete } = vi.hoisted(() => ({ formPost: vi.fn(), formDelete: vi.fn() }));
vi.mock('@inertiajs/vue3', async () => {
  const { reactive } = await import('vue');
  return {
    usePage: () => ({ props: { auth: { name: 'Superviseur fictif', permissions: ['pilotage.consulter', 'presences.consulter', 'alertes.consulter'] }, pilotageContext: null, errors: {} } }),
    router: { get: vi.fn(), post, reload: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post: formPost, delete: formDelete, reset: vi.fn() }),
  };
});

import Index from '../../resources/js/pages/Pilotage/Index.vue';
import ParticipationVisit from '../../resources/js/components/admin/ParticipationVisit.vue';
import Attendus from '../../resources/js/pages/Pilotage/Attendus.vue';
import SupervisorClosure from '../../resources/js/components/pilotage/SupervisorClosure.vue';
import VehicleSearch from '../../resources/js/components/poste/VehicleSearch.vue';
import type { PosteRepository } from '../../resources/js/poste/repository';
import type { VehicleResult } from '../../resources/js/poste/types';

const ctx = { event: { id: 'e1', name: 'Stage A' }, events: [{ id: 'e1', name: 'Stage A' }], openAlerts: 0 };
const row = (over: Record<string, unknown> = {}) => ({ id: 'v1', full_name: 'Koné Mariam', organisation: 'Org fictive', expected_at: '2027-01-10T10:30:00Z', announced_at: '2027-01-10T08:00:00Z', state: 'attendu', no_show_reason: null, category: { name: 'VIP', color: '#C9962B', priority_circuit: true }, host: 'Hôte fictif 1', plates: null, ...over });

beforeEach(() => { post.mockReset(); formPost.mockReset(); formDelete.mockReset(); });

describe('visites annoncées (supervision)', () => {
  const props = (rows: ReturnType<typeof row>[]) => ({ ...ctx, expected: { rows, counts: { attendu: 1, en_retard: 1, absent: 0, present: 0, sorti: 0, refuse: 0 }, late_after_minutes: 15 }, vehicles: [{ id: 'x1', plate: 'AB123CD', kind: 'voiture', entered_at: '2027-01-10T09:00:00Z', visitor_id: 'v1', full_name: 'Koné Mariam' }], state: null, permissions: { absence: true } });

  it('distingue attendu et en retard, montre les plaques et rappelle qu’une annonce n’est pas une présence', () => {
    const wrapper = mount(Attendus, { props: props([row(), row({ id: 'v2', full_name: 'Traoré Awa', state: 'en_retard', plates: 'AB123CD' })]) });
    const states = wrapper.findAll('[data-testid=etat-attendu]').map(n => n.text());
    expect(states).toEqual(['Attendu', 'En retard']);
    expect(wrapper.get('[data-testid=plaques-attendu]').text()).toContain('AB123CD');
    expect(wrapper.text()).toContain('n’est pas une présence');
    expect(wrapper.get('[data-testid=liste-vehicules]').text()).toContain('AB123CD');
    expect(wrapper.text()).toContain('n’indique pas que la personne est présente');
  });

  it('exige un motif avant de constater une absence puis l’envoie au serveur', async () => {
    const wrapper = mount(Attendus, { props: props([row({ state: 'en_retard' })]) });
    await wrapper.get('[data-testid=absence-debut]').trigger('click');
    expect(wrapper.get('[data-testid=absence-confirmer]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid=absence-motif]').setValue('Prévenu : empêché');
    await wrapper.get('[data-testid=absence-confirmer]').trigger('click');
    expect(post).toHaveBeenCalledWith('/pilotage/visiteurs/v1/absence', { reason: 'Prévenu : empêché' }, expect.any(Object));
  });

  it('ne propose pas de constater l’absence d’une personne déjà présente ni sans droit', () => {
    const present = mount(Attendus, { props: props([row({ state: 'present' })]) });
    expect(present.find('[data-testid=absence-debut]').exists()).toBe(false);
    const readOnly = mount(Attendus, { props: { ...props([row()]), permissions: { absence: false } } });
    expect(readOnly.find('[data-testid=absence-debut]').exists()).toBe(false);
  });
});

describe('clôture réservée au superviseur', () => {
  it('passe par un motif puis une confirmation explicite avant d’envoyer', async () => {
    const wrapper = mount(SupervisorClosure, { props: { visitorId: 'v9', offer: { possible: true, vehicles_present: 0 } } });
    await wrapper.get('[data-testid=cloture-debut]').trigger('click');
    expect(wrapper.get('[data-testid=cloture-suite]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid=cloture-motif]').setValue('Fin de la visite officielle');
    await wrapper.get('[data-testid=cloture-suite]').trigger('click');
    expect(post).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('définitives');
    await wrapper.get('[data-testid=cloture-confirmer]').trigger('click');
    expect(post).toHaveBeenCalledWith('/pilotage/visiteurs/v9/cloture-superviseur', { reason: 'Fin de la visite officielle', confirmation: true }, expect.any(Object));
  });

  it('bloque la clôture tant qu’un véhicule est présent et signale une participation déjà clôturée', () => {
    const withVehicle = mount(SupervisorClosure, { props: { visitorId: 'v9', offer: { possible: true, vehicles_present: 1 } } });
    expect(withVehicle.find('[data-testid=cloture-debut]').exists()).toBe(false);
    expect(withVehicle.get('[data-testid=cloture-vehicule]').text()).toContain('1 véhicule encore présent');
    const closed = mount(SupervisorClosure, { props: { visitorId: 'v9', offer: { possible: false, vehicles_present: 0 } } });
    expect(closed.get('[data-testid=cloture-deja]').text()).toContain('déjà clôturée');
    expect(closed.find('[data-testid=cloture-debut]').exists()).toBe(false);
  });
});

describe('recherche d’un véhicule au poste', () => {
  const vehicle = (over: Partial<VehicleResult>): VehicleResult => ({ id: 'x1', plate: 'AB-123-CD', kind: 'voiture', status: 'annonce', visitor_id: 'v1', full_name: 'Koné Mariam', visitor_status: 'attendu', ...over });

  it('cherche à partir de 3 caractères et enregistre l’entrée d’un véhicule annoncé', async () => {
    vi.useFakeTimers();
    const searchVehicles = vi.fn().mockResolvedValue([vehicle({})]);
    const vehicleEnter = vi.fn().mockResolvedValue({ ok: true });
    const wrapper = mount(VehicleSearch, { props: { repository: { searchVehicles, vehicleEnter } as unknown as PosteRepository } });
    await wrapper.get('[data-testid=ouvrir-vehicules]').trigger('click');
    await wrapper.get('[data-testid=chercher-plaque]').setValue('AB');
    await vi.advanceTimersByTimeAsync(300);
    expect(searchVehicles).not.toHaveBeenCalled();
    await wrapper.get('[data-testid=chercher-plaque]').setValue('AB-1');
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();
    expect(searchVehicles).toHaveBeenCalledWith('AB-1');
    expect(wrapper.text()).toContain('Véhicule annoncé');
    expect(wrapper.text()).toContain('n’est pas la présence de la personne');
    await wrapper.get('[data-testid=entree-vehicule]').trigger('click');
    await flushPromises();
    expect(vehicleEnter).toHaveBeenCalledWith(expect.stringMatching(/^[0-9a-f-]{36}$/), 'x1');
    expect(wrapper.get('[data-testid=vehicule-ok]').text()).toContain('Entrée du véhicule AB-123-CD enregistrée');
    vi.useRealTimers();
  });

  it('propose la sortie d’un véhicule présent et affiche un refus du serveur', async () => {
    vi.useFakeTimers();
    const { ApiError } = await import('../../resources/js/poste/types');
    const searchVehicles = vi.fn().mockResolvedValue([vehicle({ status: 'present' })]);
    const vehicleExit = vi.fn().mockRejectedValue(new ApiError('hors_ligne', 'Cette opération nécessite le réseau.'));
    const wrapper = mount(VehicleSearch, { props: { repository: { searchVehicles, vehicleExit } as unknown as PosteRepository } });
    await wrapper.get('[data-testid=ouvrir-vehicules]').trigger('click');
    await wrapper.get('[data-testid=chercher-plaque]').setValue('AB-123');
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();
    expect(wrapper.text()).toContain('Véhicule présent sur le site');
    await wrapper.get('[data-testid=sortie-vehicule]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid=erreur-vehicule]').text()).toContain('nécessite le réseau');
    vi.useRealTimers();
  });
});

describe('retards dans le Pilotage existant', () => {
  const counters = { attendus: 10, arrives: 2, presents: 1, refuses: 0, as_of: '2027-01-10T12:00:00Z' };
  const overview = (retards: number | undefined) => ({ critiques: 0, hautes: 0, litiges: 0, cars: { total: 0, partis: 0, arrives: 0, ecarts: 0 }, postes_silencieux: 0, ...(retards === undefined ? {} : { retards }) });

  it('annonce les visites en retard dans la situation et propose de les voir', () => {
    const wrapper = mount(Index, { props: { ...ctx, counters, alerts: [], overview: overview(2) } });
    expect(wrapper.get('[data-testid=situation-titre]').text()).toBe('2 visites en retard');
    expect(wrapper.get('[data-testid=terrain-retards]').text()).toContain('2 personnes annoncées ne sont pas arrivées');
    expect(wrapper.get('[data-testid=voir-retards]').attributes('href')).toBe('/pilotage/attendus?etat=en_retard&event=e1');
  });

  it('reste calme sans retard et ne change rien quand le serveur n’envoie pas le compteur', () => {
    const calm = mount(Index, { props: { ...ctx, counters, alerts: [], overview: overview(0) } });
    expect(calm.get('[data-testid=situation-titre]').text()).toBe('Tout est calme');
    expect(calm.get('[data-testid=terrain-retards]').text()).toContain('Aucune visite en retard');
    const legacy = mount(Index, { props: { ...ctx, counters, alerts: [], overview: overview(undefined) } });
    expect(legacy.find('[data-testid=terrain-retards]').exists()).toBe(false);
  });

  it('donne la priorité aux alertes sur les retards', () => {
    const wrapper = mount(Index, { props: { ...ctx, openAlerts: 1, counters, alerts: [], overview: overview(3) } });
    expect(wrapper.get('[data-testid=situation-titre]').text()).toBe('1 alerte à traiter');
  });
});

describe('administration des visites et véhicules d’une participation', () => {
  const visit = (over: Record<string, unknown> = {}) => ({ expected_at: null, announced_at: null, no_show_reason: null, vehicles: [], ...over });
  const kinds = { voiture: 'Voiture', moto: 'Moto' };

  it('annonce la visite vers la route d’administration et rappelle qu’une annonce n’est pas une présence', async () => {
    const wrapper = mount(ParticipationVisit, { props: { participationId: 'v1', visit: visit({ expected_at: '2027-01-10 14:00:00+00', announced_at: '2027-01-10 08:00:00+00' }), kinds, canAnnounce: true } });
    expect(wrapper.get('[data-testid=heure-annoncee]').text()).toContain('n’est pas une présence');
    expect((wrapper.get('[data-testid=admin-heure-attendue]').element as HTMLInputElement).value).toBe('2027-01-10T14:00');
    await wrapper.get('form[aria-label="Annoncer la visite"]').trigger('submit');
    expect(formPost).toHaveBeenCalledWith('/administration/participations/v1/annonce', expect.any(Object));
    expect(wrapper.get('[data-testid=admin-annoncer]').text()).toBe('Modifier l’heure attendue');
  });

  it('n’offre plus l’annonce à une personne déjà arrivée mais garde la gestion des véhicules', () => {
    const wrapper = mount(ParticipationVisit, { props: { participationId: 'v1', visit: visit(), kinds, canAnnounce: false } });
    expect(wrapper.find('[data-testid=admin-annoncer]').exists()).toBe(false);
    expect(wrapper.get('[data-testid=annonce-impossible]').text()).toContain('déjà arrivée');
    expect(wrapper.find('[data-testid=admin-ajouter-vehicule]').exists()).toBe(true);
  });

  it('associe un véhicule et n’annule qu’un véhicule seulement annoncé (la sortie d’un véhicule présent se fait au poste)', async () => {
    const vehicles = [{ id: 'x1', plate: 'AB-123', kind: 'voiture', status: 'annonce' }, { id: 'x2', plate: 'CD-456', kind: 'moto', status: 'present' }];
    const wrapper = mount(ParticipationVisit, { props: { participationId: 'v1', visit: visit({ vehicles }), kinds, canAnnounce: true } });
    expect(wrapper.get('[data-testid=admin-ajouter-vehicule]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid=admin-plaque]').setValue('EF-789');
    await wrapper.get('form[aria-label="Associer un véhicule"]').trigger('submit');
    expect(formPost).toHaveBeenCalledWith('/administration/participations/v1/vehicules', expect.any(Object));
    expect(wrapper.find('[data-testid=admin-annuler-vehicule-x2]').exists()).toBe(false);
    expect(wrapper.get('[data-testid=admin-vehicules]').text()).toContain('Sa sortie se fait à un poste d’accueil');
    await wrapper.get('[data-testid=admin-annuler-vehicule-x1]').trigger('click');
    expect(formDelete).toHaveBeenCalledWith('/administration/vehicules/x1', expect.any(Object));
  });
});
