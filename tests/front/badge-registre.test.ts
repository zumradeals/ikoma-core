import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['badges.gerer'] } } }),
  router: { get, post },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => { const form: Record<string, unknown> = reactive({ ...data, errors: {}, processing: false, post, reset: vi.fn() }); form.transform = () => form; return form; },
}));

import BadgeRegistre from '../../resources/js/pages/Admin/BadgeRegistre.vue';
import BadgeDetails from '../../resources/js/pages/Admin/BadgeDetails.vue';

const events = [{ id: 'e1', name: 'Stage A', kind: 'stage' }, { id: 'e2', name: 'Visite B', kind: 'visite' }];
const badges = [{ id: 'b1', code: 'B-001', event_id: 'e1', event_name: 'Stage A' }, { id: 'b2', code: 'B-002', event_id: 'e2', event_name: 'Visite B' }];

describe('registre commun des badges', () => {
  it('ne propose à l’activité cible que les badges disponibles d’une autre activité, et demande confirmation', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const wrapper = mount(BadgeRegistre, { props: { target: 'e1', badges, events, devices: [{ id: 'd1', event_id: 'e1', name: 'T1', station: 'Poste 1' }], categories: [{ id: 'c1', event_id: 'e1', name: 'Stagiaire', color: '#1E9E5A' }], transfers: [] } });
    expect(wrapper.find('[data-testid="choisir-B-001"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="choisir-B-002"]').exists()).toBe(true);
    await wrapper.get('[data-testid="choisir-B-002"]').setValue(true);
    await wrapper.get('[data-testid="categorie-cible"]').setValue('c1');
    await wrapper.get('[data-testid="mettre-a-disposition"]').trigger('click');
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith('/administration/badges/mise-a-disposition', expect.anything());
    confirm.mockRestore();
  });

  it('ne fait rien si la confirmation est refusée', async () => {
    post.mockClear();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const wrapper = mount(BadgeRegistre, { props: { target: 'e1', badges, events, devices: [], categories: [{ id: 'c1', event_id: 'e1', name: 'Stagiaire', color: '#1E9E5A' }], transfers: [] } });
    await wrapper.get('[data-testid="choisir-B-002"]').setValue(true);
    await wrapper.get('[data-testid="categorie-cible"]').setValue('c1');
    await wrapper.get('[data-testid="mettre-a-disposition"]').trigger('click');
    expect(post).not.toHaveBeenCalled();
    confirm.mockRestore();
  });

  it('affiche l’historique d’un badge à travers plusieurs activités', () => {
    const wrapper = mount(BadgeDetails, { props: {
      badge: { id: 'b1', event_id: 'e2', code: 'B-001', status: 'Disponible', lot: 'Lot', category: null, reserved_device_id: null, reserved_group_id: null, printed_for_visitor_id: null },
      history: [
        { id: 'a2', visitor_id: 'v2', event_name: 'Visite B', person_id: 'p2', person_name: 'Diallo Awa', actor_id: null, assigned_at: '2027-02-01', returned_at: '2027-02-01', cancelled_at: null },
        { id: 'a1', visitor_id: 'v1', event_name: 'Stage A', person_id: 'p1', person_name: 'Koné Mariam', actor_id: null, assigned_at: '2027-01-10', returned_at: '2027-01-12', cancelled_at: null },
      ],
      transfers: [{ id: 't1', from_name: 'Stage A', to_name: 'Visite B', reservation_released: true, created_at: '2027-01-20' }],
    } });
    const rows = wrapper.findAll('[data-testid="attribution"]');
    expect(rows).toHaveLength(2);
    expect(rows[0].text()).toContain('Visite B');
    expect(rows[1].text()).toContain('Stage A');
    expect(wrapper.get('[data-testid="mise-a-disposition"]').text()).toContain('de « Stage A » vers « Visite B »'.replace('de', 'De'));
  });
});
