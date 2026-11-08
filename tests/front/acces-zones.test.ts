import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const { post, get, put } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), put: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['zones.gerer'] } } }),
  router: { get, post },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => { const form: Record<string, unknown> = reactive({ ...data, errors: {}, processing: false, post, put, reset: vi.fn() }); form.transform = () => form; return form; },
}));

import AccesZones from '../../resources/js/pages/Admin/AccesZones.vue';

const base = {
  events: [{ id: 'e1', name: 'Stage A' }], eventId: 'e1', q: '', zones: [{ id: 'z1', name: 'Hall' }, { id: 'z2', name: 'Espace VIP' }], points: [{ id: 'p1', name: 'Poste 2' }],
  mappings: [{ id: 'm1', device_id: 'p1', point: 'Poste 2', zone_id: 'z1', zone: 'Hall' }],
  participants: [{ id: 'v1', full_name: 'Koné Mariam', organisation: null }], maxHours: 24, now: '2027-01-10T10:00:00Z',
  entitlements: [
    { id: 'h1', visitor_id: 'v1', person_id: 'p', full_name: 'Koné Mariam', zone_id: 'z2', zone: 'Espace VIP', kind: 'exceptionnelle' as const, reason: 'Visite officielle', valid_from: '2027-01-10T09:00:00Z', valid_until: '2027-01-10T18:00:00Z', suspended_at: null, suspension_reason: null, revoked_at: null, revocation_reason: null },
    { id: 'h2', visitor_id: 'v1', person_id: 'p', full_name: 'Koné Mariam', zone_id: 'z1', zone: 'Hall', kind: 'standard' as const, reason: null, valid_from: '2027-01-01T09:00:00Z', valid_until: '2027-01-05T18:00:00Z', suspended_at: null, suspension_reason: null, revoked_at: null, revocation_reason: null },
    { id: 'h3', visitor_id: 'v1', person_id: 'p', full_name: 'Koné Mariam', zone_id: 'z1', zone: 'Hall', kind: 'standard' as const, reason: null, valid_from: '2027-01-10T09:00:00Z', valid_until: '2027-01-12T18:00:00Z', suspended_at: '2027-01-10T09:30:00Z', suspension_reason: 'Contrôle', revoked_at: null, revocation_reason: null },
  ],
};

describe('accès aux zones', () => {
  it('affiche les correspondances et l’état de chaque habilitation (active, expirée, suspendue)', () => {
    const wrapper = mount(AccesZones, { props: base });
    expect(wrapper.get('[data-testid=correspondance]').text()).toContain('Poste 2 → Hall');
    const states = wrapper.findAll('[data-testid=etat]').map(e => e.text());
    expect(states).toEqual(['Active', 'Expirée', 'Suspendue']);
    expect(wrapper.text()).toContain('Visite officielle');
    expect(wrapper.text()).toContain('au plus 24 heure(s)');
  });

  it('demande un motif avant de suspendre et ne fait rien si l’administrateur annule', async () => {
    post.mockClear();
    const prompt = vi.spyOn(window, 'prompt').mockReturnValueOnce(null).mockReturnValueOnce('Contrôle de sécurité');
    const wrapper = mount(AccesZones, { props: base });
    const suspend = wrapper.findAll('button').find(b => b.text() === 'Suspendre')!;
    await suspend.trigger('click');
    expect(post).not.toHaveBeenCalled();
    await suspend.trigger('click');
    expect(post).toHaveBeenCalledWith('/administration/acces/habilitations/h1/suspendre', { reason: 'Contrôle de sécurité' }, expect.anything());
    prompt.mockRestore();
  });
});
