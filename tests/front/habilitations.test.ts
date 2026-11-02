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
  holders: [{ id: 't1', identifier: 'ID-000001-8', full_name: 'Personne Fictive' }],
  decisions: [{ id: 'd1', type: 'controle', point: 'Hall', at: '2027-01-10T10:00:00Z', identifier: 'ID-000001-8', verdict: 'refuse', reason: 'habilitation_expiree', deferred: true }],
  devices: [{ id: 'p1', name: 'Entrée', last_synced_at: null, age_minutes: null, state: 'confiance_expiree' as const }, { id: 'p2', name: 'Sortie', last_synced_at: '2027-01-10T10:00:00Z', age_minutes: 3, state: 'confiance_valide' as const }],
  trust: { configured: 0, approved: false, effective: 0, max: 1440 }, can: { grant: true, policies: true, trust: true },
};

describe('habilitations des titulaires permanents', () => {
  it('affiche les zones sans politique comme refusées par défaut et rappelle qu’aucune catégorie ne donne d’accès', () => {
    const wrapper = mount(Habilitations, { props: base });
    expect(wrapper.get('[data-testid=page-habilitations]').text()).toContain('ne donnent aucun accès');
    expect(wrapper.get('[data-testid=zone-z1]').text()).toContain('Sans politique : refus');
    expect(wrapper.get('[data-testid=zone-z2]').text()).not.toContain('Sans politique');
    expect(wrapper.get('[data-testid=horsligne-z1]').attributes('disabled')).toBeDefined();
  });

  it('montre la confiance de chaque terminal, les motifs de décision et l’absence de confiance approuvée', () => {
    const wrapper = mount(Habilitations, { props: base });
    expect(wrapper.get('[data-testid=etat-p1]').text()).toContain('Confiance expirée');
    expect(wrapper.get('[data-testid=etat-p2]').text()).toContain('Confiance valide');
    expect(wrapper.get('[data-testid=confiance-resume]').text()).toContain('aucune');
    expect(wrapper.get('[data-testid=decision-d1]').text()).toContain('Habilitation expirée');
    expect(wrapper.get('[data-testid=decision-d1]').text()).toContain('hors ligne');
  });

  it('sans le droit, les commandes d’écriture n’apparaissent pas ; sans événement, un message clair est affiché', () => {
    const ro = mount(Habilitations, { props: { ...base, can: { grant: false, policies: false, trust: false } } });
    expect(ro.find('[data-testid=hab-accorder]').exists()).toBe(false);
    expect(ro.find('[data-testid=confiance-enregistrer]').exists()).toBe(false);
    expect(ro.find('[data-testid=retirer-h1]').exists()).toBe(false);
    const none = mount(Habilitations, { props: { ...base, events: [], eventId: null, zones: [], entitlements: [], decisions: [], devices: [] } });
    expect(none.find('[data-testid=aucun-evenement]').exists()).toBe(true);
  });

  it('envoie les dates d’habilitation en UTC explicite, quel que soit le fuseau du navigateur', async () => {
    process.env.TZ = 'Europe/Paris';
    expect(toUtcIso('2027-01-10T10:00')).toBe('2027-01-10T09:00:00.000Z');
    process.env.TZ = 'America/Abidjan';
    expect(toUtcIso('2027-01-10T10:00')).toBe('2027-01-10T10:00:00.000Z');
    expect(toUtcIso('')).toBe('');
    expect(toUtcIso('pas une date')).toBe('pas une date');
    process.env.TZ = 'Europe/Paris';
    post.mockClear();
    const wrapper = mount(Habilitations, { props: base });
    await wrapper.get('[data-testid=hab-titulaire]').setValue('t1');
    await wrapper.get('[data-testid=hab-zone]').setValue('z2');
    await wrapper.get('[data-testid=hab-debut]').setValue('2027-01-10T10:00');
    await wrapper.get('[data-testid=hab-fin]').setValue('2027-01-10T18:30');
    await wrapper.get('form').trigger('submit');
    expect(post).toHaveBeenCalledWith('/administration/habilitations', expect.objectContaining({ valid_from: '2027-01-10T09:00:00.000Z', valid_until: '2027-01-10T17:30:00.000Z', zone_id: 'z2', holder_id: 't1' }), expect.anything());
  });
});
