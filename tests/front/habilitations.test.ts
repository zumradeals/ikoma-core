import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const { put, post } = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['listes.importer', 'zones.gerer', 'postes.gerer'] } } }),
  router: { get: vi.fn(), put, post: vi.fn(), delete: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => {
    const form = reactive({
      ...data, errors: {}, processing: false, put, reset: vi.fn(), clearErrors: vi.fn(), _transform: null as null | ((d: Record<string, unknown>) => Record<string, unknown>),
      transform(fn: (d: Record<string, unknown>) => Record<string, unknown>) { form._transform = fn; return form; },
      post(url: string, options?: object) { const { _transform, errors, processing, ...fields } = form as Record<string, unknown>; void errors; void processing; return post(url, (_transform as typeof form._transform)?.(fields) ?? fields, options); },
    });
    return form;
  },
}));

import Habilitations from '../../resources/js/pages/Admin/Habilitations.vue';
import { toUtcIso } from '../../resources/js/utils/localDateTime';

const base = {
  events: [{ id: 'e1', name: 'Événement fictif' }], eventId: 'e1',
  zones: [{ id: 'z1', name: 'Zone P', classification: null, permanent_allowed: false, offline_entry_allowed: false }, { id: 'z2', name: 'Zone Q', classification: 'NEUTRE-A', permanent_allowed: true, offline_entry_allowed: false }],
  entitlements: [{ id: 'h1', holder_id: 't1', full_name: 'Personne Fictive', identifier: 'ID-000001-8', zone_id: 'z2', zone: 'Zone Q', valid_from: '2020-01-01T00:00:00Z', valid_until: '2099-01-01T00:00:00Z', revoked_at: null, revocation_reason: null }],
  holders: [{ id: 't1', identifier: 'ID-000001-8', full_name: 'Personne Fictive', category_code: 'AILCT-01', category_label: 'Personnel' }, { id: 't2', identifier: 'ID-000002-6', full_name: 'Autre Personne', category_code: 'AILCT-02', category_label: 'Partenaires' }],
  categories: [{ code: 'AILCT-01', label: 'Personnel', color: '#2E7D32' }, { code: 'AILCT-02', label: 'Partenaires', color: '#EF6C00' }], eventDates: { starts_at: '2027-01-01T00:00:00Z', ends_at: '2027-01-31T00:00:00Z' },
  decisions: [{ id: 'd1', type: 'controle', point: 'Hall', at: '2027-01-10T10:00:00Z', identifier: 'ID-000001-8', verdict: 'refuse', reason: 'habilitation_expiree', deferred: true }],
  devices: [{ id: 'p1', name: 'Entrée', last_synced_at: null, age_minutes: null, state: 'confiance_expiree' as const }, { id: 'p2', name: 'Sortie', last_synced_at: '2027-01-10T10:00:00Z', age_minutes: 3, state: 'confiance_valide' as const }],
  trust: { configured: 0, approved: false, effective: 0, max: 1440 }, can: { grant: true, policies: true, trust: true },
};

describe('qui peut entrer où ?', () => {
  it('présente les zones comme ouvertes ou fermées et rappelle qu’aucune catégorie ne donne d’accès', () => {
    const wrapper = mount(Habilitations, { props: base });
    expect(wrapper.get('[data-testid=page-habilitations]').text()).toContain('Sa carte et sa catégorie, seules, n’ouvrent rien');
    expect(wrapper.get('[data-testid=etat-zone-z1]').text()).toBe('Fermée');
    expect(wrapper.get('[data-testid=etat-zone-z2]').text()).toBe('Ouverte');
    expect(wrapper.find('[data-testid=horsligne-z1]').exists()).toBe(false);
    expect(wrapper.find('[data-testid=horsligne-z2]').exists()).toBe(true);
  });

  it('ouvre et ferme une zone sans demander de classification (valeur existante conservée, sinon « Standard »)', async () => {
    put.mockClear();
    const wrapper = mount(Habilitations, { props: base });
    await wrapper.get('[data-testid=bascule-zone-z1]').trigger('click');
    expect(put).toHaveBeenCalledWith('/administration/habilitations/zones/z1', { event_id: 'e1', classification: 'Standard', permanent_allowed: true, offline_entry_allowed: false }, expect.anything());
    await wrapper.get('[data-testid=bascule-zone-z2]').trigger('click');
    expect(put).toHaveBeenLastCalledWith('/administration/habilitations/zones/z2', { event_id: 'e1', classification: 'NEUTRE-A', permanent_allowed: false, offline_entry_allowed: false }, expect.anything());
  });

  it('filtre les personnes par catégorie et par recherche, et ne propose que les zones ouvertes', async () => {
    const wrapper = mount(Habilitations, { props: base });
    expect(wrapper.findAll('[data-testid^=personne-]')).toHaveLength(2);
    await wrapper.get('[data-testid=filtre-AILCT-02]').trigger('click');
    expect(wrapper.findAll('[data-testid^=personne-]')).toHaveLength(1);
    await wrapper.get('[data-testid=filtre-toutes]').trigger('click');
    await wrapper.get('[data-testid=chercher-personne]').setValue('personne fictive');
    expect(wrapper.findAll('[data-testid^=personne-]')).toHaveLength(1);
    expect(wrapper.find('[data-testid=choisir-zone-z2]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=choisir-zone-z1]').exists()).toBe(false);
  });

  it('donne l’accès à plusieurs personnes pour tout l’événement ou pour des dates précises, en UTC explicite', async () => {
    process.env.TZ = 'Europe/Paris';
    expect(toUtcIso('2027-01-10T10:00')).toBe('2027-01-10T09:00:00.000Z');
    post.mockClear();
    const wrapper = mount(Habilitations, { props: base });
    expect(wrapper.get('[data-testid=hab-accorder]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid=tout-selectionner]').setValue(true);
    await wrapper.get('[data-testid=choisir-zone-z2]').setValue(true);
    expect(wrapper.get('[data-testid=hab-accorder]').text()).toContain('2 personne(s)');
    await wrapper.get('[data-testid=hab-accorder]').trigger('click');
    expect(post).toHaveBeenLastCalledWith('/administration/habilitations/groupe', expect.objectContaining({ event_id: 'e1', holders: ['t1', 't2'], zones: ['z2'], whole_event: true, valid_from: null, valid_until: null }), expect.anything());
    await wrapper.get('[data-testid=periode-dates]').setValue(true);
    await wrapper.get('[data-testid=hab-debut]').setValue('2027-01-10T10:00');
    await wrapper.get('[data-testid=hab-fin]').setValue('2027-01-10T18:30');
    await wrapper.get('[data-testid=hab-accorder]').trigger('click');
    expect(post).toHaveBeenLastCalledWith('/administration/habilitations/groupe', expect.objectContaining({ whole_event: false, valid_from: '2027-01-10T09:00:00.000Z', valid_until: '2027-01-10T17:30:00.000Z' }), expect.anything());
  });

  it('regroupe les accès par personne et montre la confiance de chaque tablette dans les réglages avancés', () => {
    const wrapper = mount(Habilitations, { props: base });
    expect(wrapper.get('[data-testid=acces-ID-000001-8]').text()).toContain('Zone Q');
    expect(wrapper.get('[data-testid=hab-h1]').text()).toContain('En cours');
    expect(wrapper.get('[data-testid=etat-p1]').text()).toContain('Confiance expirée');
    expect(wrapper.get('[data-testid=etat-p2]').text()).toContain('Confiance valide');
    expect(wrapper.get('[data-testid=confiance-resume]').text()).toContain('aucune');
    expect(wrapper.get('[data-testid=decision-d1]').text()).toContain('Habilitation expirée');
  });

  it('sans le droit, les commandes d’écriture n’apparaissent pas ; sans événement, un message clair est affiché', () => {
    const ro = mount(Habilitations, { props: { ...base, can: { grant: false, policies: false, trust: false } } });
    expect(ro.find('[data-testid=hab-accorder]').exists()).toBe(false);
    expect(ro.find('[data-testid=bascule-zone-z1]').exists()).toBe(false);
    expect(ro.find('[data-testid=confiance-enregistrer]').exists()).toBe(false);
    expect(ro.find('[data-testid=retirer-h1]').exists()).toBe(false);
    const none = mount(Habilitations, { props: { ...base, events: [], eventId: null, zones: [], entitlements: [], decisions: [], devices: [] } });
    expect(none.find('[data-testid=aucun-evenement]').exists()).toBe(true);
  });
});
