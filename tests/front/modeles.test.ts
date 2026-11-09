import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

const page = vi.hoisted(() => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['badges.gerer', 'listes.importer', 'categories.gerer'] as string[] }, badgesCartes: null as unknown } }));
const put = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => page,
  router: { get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: Record<string, unknown>) => {
    const keys = Object.keys(data);
    const form = reactive({ ...data, errors: {}, processing: false, put, defaults: () => form, reset: vi.fn(), clearErrors: vi.fn(), data: () => Object.fromEntries(keys.map(key => [key, form[key as keyof typeof form]])) });
    return form;
  },
}));

import BadgeModelSection from '../../resources/js/components/admin/BadgeModelSection.vue';
import Modeles from '../../resources/js/pages/Admin/Modeles.vue';

const specimen = { recto: '<article class="access-card">EXEMPLE recto</article>', verso: '<article class="access-card">EXEMPLE verso</article>', orientation: 'paysage' };
const visual = { design: 'visiteur', title: 'IKOMA ACCESS', primary: '#0066B3', accent: '#F75C03', orientation: 'paysage' };
const props = { events: [{ id: 'event-a', name: 'Événement A' }, { id: 'event-b', name: 'Événement B' }], preferences: { 'event-a': visual, 'event-b': { ...visual, design: 'vip', title: 'B' } }, initialEvent: null };
const calls = () => vi.mocked(fetch).mock.calls.map(([url, init]) => ({ url: String(url), method: (init as RequestInit | undefined)?.method ?? 'GET', body: (init as RequestInit | undefined)?.body ? JSON.parse(String((init as RequestInit).body)) : undefined }));
const answer = (reply: Record<string, unknown>) => vi.mocked(fetch).mockImplementation(async (url: unknown) => ({ ok: true, status: 200, json: async () => (String(url).includes('apercu-fictif') ? specimen : reply) }) as Response);

beforeEach(() => {
  put.mockReset();
  vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => specimen }) as Response));
});
afterEach(() => { vi.unstubAllGlobals(); });

describe('Modèle de badge visiteur (page Modèles)', () => {
  it('affiche un aperçu d’exemple fictif et ne lit aucun badge réel', async () => {
    const wrapper = mount(BadgeModelSection, { props });
    await flushPromises();
    expect(wrapper.get('[data-testid="badge-exemple-recto"]').text()).toContain('EXEMPLE recto');
    expect(calls().every(c => c.url.endsWith('/administration/atelier/apercu-fictif'))).toBe(true);
    expect(calls()[0].body).toMatchObject({ event: 'event-a', design: 'visiteur', title: 'IKOMA ACCESS', orientation: 'paysage' });
  });

  it('enregistre le modèle de l’activité par la route existante', async () => {
    const wrapper = mount(BadgeModelSection, { props });
    await flushPromises();
    await wrapper.get('[data-testid="badge-enregistrer-modele"]').trigger('click');
    expect(put).toHaveBeenCalledWith('/administration/atelier/modeles', expect.objectContaining({ preserveScroll: true }));
  });

  it('rétablit le modèle par défaut de l’activité et le dit', async () => {
    const wrapper = mount(BadgeModelSection, { props });
    await flushPromises();
    answer({ model: visual });
    await wrapper.get('[data-testid="badge-reinitialiser-modele"]').trigger('click'); await flushPromises();
    const call = calls().find(c => c.url.endsWith('/administration/atelier/modeles/reinitialiser'))!;
    expect(call.method).toBe('POST');
    expect(call.body).toEqual({ event: 'event-a' });
    expect(wrapper.get('[data-testid="badge-message-modele"]').text()).toContain('Modèle par défaut rétabli');
  });

  it('reprend le modèle d’une autre activité, applique ses valeurs et nomme la source', async () => {
    const wrapper = mount(BadgeModelSection, { props });
    await flushPromises();
    expect(wrapper.get('[data-testid="badge-reprendre-modele"]').attributes('disabled')).toBeDefined();
    expect(wrapper.findAll('[data-testid="badge-source-modele"] option').map(o => o.text())).toEqual(['Choisir…', 'Événement B']);
    answer({ model: { design: 'vip', title: 'MODÈLE REPRIS', primary: '#0B3C6B', accent: '#C9962B', orientation: 'portrait' } });
    await wrapper.get('[data-testid="badge-source-modele"]').setValue('event-b');
    await wrapper.get('[data-testid="badge-reprendre-modele"]').trigger('click'); await flushPromises();
    const call = calls().find(c => c.url.endsWith('/administration/atelier/modeles/reprendre'))!;
    expect(call.body).toEqual({ event: 'event-a', source: 'event-b' });
    expect(wrapper.get('[data-testid="badge-message-modele"]').text()).toContain('Événement B');
  });

  it('affiche l’erreur serveur sans prétendre avoir changé le modèle', async () => {
    const wrapper = mount(BadgeModelSection, { props });
    await flushPromises();
    vi.mocked(fetch).mockImplementation(async (url: unknown) => (String(url).includes('apercu-fictif') ? { ok: true, status: 200, json: async () => specimen } : { ok: false, status: 403, json: async () => ({}) }) as Response);
    await wrapper.get('[data-testid="badge-reinitialiser-modele"]').trigger('click'); await flushPromises();
    expect(wrapper.find('[data-testid="badge-message-modele"]').exists()).toBe(false);
    expect(wrapper.get('[data-testid="badge-erreurs-modele"]').text()).toContain('Opération refusée');
  });

  it('dit explicitement quand aucune activité n’existe', () => {
    const wrapper = mount(BadgeModelSection, { props: { events: [], preferences: {}, initialEvent: null } });
    expect(wrapper.get('[data-testid="aucun-evenement"]').text()).toContain('créez d’abord une activité');
  });
});

describe('Page Modèles', () => {
  const cards = { categories: [{ code: 'AILCT-01', label: 'Catégorie un' }], selected: 'AILCT-01', model: { category_code: 'AILCT-01', label: 'Catégorie un', color: '#2E7D32', mention: 'M', show_validity: true, qr_side: 'verso', version: 1, status: 'brouillon', validated: false, validated_at: null, validated_by: null, saved: false }, preview: { recto: '<i>r</i>', verso: '<i>v</i>' }, settings: { ligne1: '', ligne2: '', contact: '', avis: '' }, limits: { ligne1: 80, ligne2: 80, contact: 80, avis: 160 }, initialized: true, canConfigure: true };
  const mountPage = (section: 'badges' | 'cartes') => mount(Modeles, { props: { ...props, section, cards } as never, global: { stubs: { AdminLayout: { template: '<main><slot /></main>' } } } });

  it('montre le cadre à quatre onglets et la section badge visiteur par défaut', async () => {
    const wrapper = mountPage('badges'); await flushPromises();
    expect(wrapper.get('[data-testid="onglet-modeles"]').attributes('aria-current')).toBe('page');
    expect(wrapper.findAll('[data-testid^="onglet-"]').map(a => a.attributes('data-testid'))).toEqual(['onglet-visiteurs', 'onglet-cartes', 'onglet-modeles', 'onglet-impression']);
    expect(wrapper.find('[data-testid="bloc-modele-badge"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="page-modeles-cartes"]').exists()).toBe(false);
  });

  it('passe à la section carte permanente sans quitter la page, avec les mêmes contrôles qu’auparavant', async () => {
    const wrapper = mountPage('badges'); await flushPromises();
    await wrapper.get('[data-testid="modele-type-cartes"]').trigger('click');
    expect(wrapper.find('[data-testid="page-modeles-cartes"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="choix-categorie"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="enregistrer-modele"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="badge-enregistrer-modele"]').exists()).toBe(false);
  });

  it('s’ouvre directement sur la carte quand la section est demandée', async () => {
    const wrapper = mountPage('cartes'); await flushPromises();
    expect(wrapper.get('[data-testid="modele-type-cartes"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.find('[data-testid="choix-categorie"]').exists()).toBe(true);
  });
});
