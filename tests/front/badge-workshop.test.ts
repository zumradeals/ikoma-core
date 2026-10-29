import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import BadgeWorkshop from '../../resources/js/pages/Admin/BadgeWorkshop.vue';

vi.mock('@inertiajs/vue3', () => ({
  Link: { template: '<a><slot /></a>' },
  useForm: (data: Record<string, unknown>) => {
    const keys = Object.keys(data);
    const form = reactive({ ...data, errors: {}, processing: false, put: vi.fn(), data: () => Object.fromEntries(keys.map(key => [key, form[key as keyof typeof form]])) });
    return form;
  },
}));
const props = {
  events: [{ id: 'event-a', name: 'Événement A' }, { id: 'event-b', name: 'Événement B' }],
  batches: [{ id: 'lot-a', event_id: 'event-a', name: 'Lot Visiteur', category: 'Visiteur', first_number: 401, last_number: 600 }, { id: 'lot-b', event_id: 'event-b', name: 'Lot Stagiaire', category: 'Stagiaire', first_number: 1, last_number: 20 }],
  preferences: {},
};
const preview = { html: '<article class="access-card">B-401</article>', count: 200, first: 'B-401', last: 'B-600', inactive: 0 };
const render = () => mount(BadgeWorkshop, { props, global: { stubs: { AdminLayout: { template: '<main><slot /></main>' } } } });
beforeEach(() => { vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => preview })); });
afterEach(() => { vi.unstubAllGlobals(); });

describe('atelier de badges', () => {
  it('prépare 200 badges réels, présente trois modèles et ne propose aucun champ personnel', async () => {
    const wrapper = render(); await flushPromises();
    expect(wrapper.text()).toContain('200 badge(s)');
    expect(wrapper.findAll('input[name="design"]')).toHaveLength(3);
    expect(wrapper.find('input[type="file"]').exists()).toBe(false);
    expect(wrapper.get('[data-testid="preparer-impression"]').attributes('href')).toContain('batch=lot-a');
    expect(wrapper.text()).toContain('ne donne aucun droit d’accès supplémentaire');
    expect(JSON.parse(vi.mocked(fetch).mock.calls[0][1]!.body as string)).toMatchObject({ from: '401', to: '600', mode: 'reutilisable' });
  });

  it('exige un aperçu à jour après modification et permet le tirage d’un seul badge', async () => {
    const wrapper = render(); await flushPromises();
    await wrapper.get('input[type="number"]').setValue('500');
    await wrapper.findAll('input[type="number"]')[1].setValue('500');
    expect(wrapper.find('[data-testid="preparer-impression"]').exists()).toBe(false);
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[data-testid="preparer-impression"]').attributes('href')).toContain('from=500&to=500');
  });

  it('change d’événement sans conserver une plage ou un lot de l’autre événement', async () => {
    const wrapper = render(); await flushPromises();
    await wrapper.findAll('select')[0].setValue('event-b'); await flushPromises();
    expect((wrapper.findAll('select')[1].element as HTMLSelectElement).value).toBe('lot-b');
    expect((wrapper.get('input[type="number"]').element as HTMLInputElement).value).toBe('1');
    expect(wrapper.find('[data-testid="preparer-impression"]').exists()).toBe(false);
    expect(wrapper.findAll('select')[1].text()).not.toContain('Visiteur');
  });

  it('refuse d’afficher un aperçu reçu pour des options déjà modifiées', async () => {
    let resolve: (value: Response) => void = () => undefined;
    vi.mocked(fetch).mockReturnValue(new Promise(r => { resolve = r; }));
    const wrapper = render();
    await wrapper.get('input[value="vip"]').setValue();
    resolve({ ok: true, status: 200, json: async () => preview } as Response); await flushPromises();
    expect(wrapper.find('.access-card').exists()).toBe(false);
    expect(wrapper.find('[data-testid="preparer-impression"]').exists()).toBe(false);
  });

  it('explique la coupure réseau et les erreurs serveur sans proposer d’impression', async () => {
    vi.mocked(fetch).mockRejectedValueOnce(new TypeError('Failed to fetch'));
    const wrapper = render(); await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('connexion est interrompue');
    vi.mocked(fetch).mockResolvedValueOnce({ ok: false, status: 422, json: async () => ({ errors: { from: ['Aucun badge dans cette plage.'] } }) } as Response);
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Aucun badge');
    expect(wrapper.find('[data-testid="preparer-impression"]').exists()).toBe(false);
  });
});
