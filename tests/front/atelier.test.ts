import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Atelier from '../../resources/js/pages/Admin/Atelier.vue';

vi.mock('@inertiajs/vue3', () => ({
  Link: { template: '<a><slot /></a>' },
  useForm: (data: Record<string, unknown>) => {
    const keys = Object.keys(data);
    const form = reactive({ ...data, errors: {}, processing: false, put: vi.fn(), data: () => Object.fromEntries(keys.map(key => [key, form[key as keyof typeof form]])) });
    return form;
  },
}));

const run = (over: Record<string, unknown> = {}) => ({ id: 'r1', event: 'Événement A', batch: 'Lot Visiteur', format: 'pvc', side: 'recto', design: 'visiteur', mode: 'reutilisable', nature: 'officiel', count: 3, first: 401, last: 403, reason: null, created_at: '2026-11-02T10:00:00Z', by: 'Admin fictif', state: 'generated', requests: 0, printed: null, spoiled: null, note: null, ...over });
const props = {
  events: [{ id: 'event-a', name: 'Événement A' }, { id: 'event-b', name: 'Événement B' }],
  batches: [{ id: 'lot-a', event_id: 'event-a', name: 'Lot Visiteur', category: 'Visiteur', first_number: 401, last_number: 600 }, { id: 'lot-b', event_id: 'event-b', name: 'Lot Stagiaire', category: 'Stagiaire', first_number: 1, last_number: 50 }],
  preferences: {}, runs: [run(), run({ id: 'r2', state: 'requested' }), run({ id: 'r3', state: 'operator_confirmed', printed: 3, spoiled: 0, nature: 'essai', batch: null, first: null, last: null })],
  initial: { event: null, batch: null, format: null, from: null, to: null, cols: null, rows: null }, canPrepare: true,
};
const specimen = { recto: '<article class="access-card">EXEMPLE recto</article>', verso: '<article class="access-card">EXEMPLE verso</article>', orientation: 'paysage' };
const real = { html: '<article class="access-card">B-401</article>', count: 200, first: 'B-401', last: 'B-600', inactive: 0 };
function render() { return mount(Atelier, { props: props as never, global: { stubs: { AdminLayout: { template: '<main><slot /></main>' }, BadgesCartesShell: true } } }); }
const calls = () => vi.mocked(fetch).mock.calls.map(([url, init]) => ({ url: String(url), method: (init as RequestInit | undefined)?.method ?? 'GET', body: (init as RequestInit | undefined)?.body ? JSON.parse((init as RequestInit).body as string) : null }));

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(async (url: string) => {
    const path = String(url);
    const body = path.includes('apercu-fictif') ? specimen : path.endsWith('/apercu') ? real : path.includes('/tirages') && !path.includes('confirmation') && !path.includes('?') && !path.includes('demande') ? { run: 'r9', state: 'generated', document_url: '/administration/atelier/tirages/r9/document' } : { runs: props.runs, state: 'operator_confirmed' };
    return { ok: true, status: 200, json: async () => body } as Response;
  }));
});
afterEach(() => { vi.unstubAllGlobals(); });

describe('Atelier des badges (parcours visiteurs)', () => {
  it('affiche un aperçu d’exemple sans jamais appeler l’aperçu d’un badge réel', async () => {
    const wrapper = render(); await flushPromises();
    expect(wrapper.get('[data-testid="exemple-recto"]').text()).toContain('EXEMPLE recto');
    expect(wrapper.get('[data-testid="exemple-verso"]').text()).toContain('EXEMPLE verso');
    expect(calls().some(c => c.url.endsWith('/administration/atelier/apercu'))).toBe(false);
    expect(wrapper.find('[data-testid="badge-reel"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('vrai QR');
  });

  it('n’affiche un badge réel qu’après un clic explicite, et le retire dès que les choix changent', async () => {
    const wrapper = render(); await flushPromises();
    await wrapper.get('[data-testid="afficher-reel"]').trigger('click'); await flushPromises();
    expect(wrapper.get('[data-testid="badge-reel"]').text()).toContain('B-401');
    expect(calls().filter(c => c.url.endsWith('/administration/atelier/apercu'))).toHaveLength(1);
    await wrapper.get('[data-testid="plage-fin"]').setValue('500'); await flushPromises();
    expect(wrapper.find('[data-testid="badge-reel"]').exists()).toBe(false);
  });

  it('génère un document officiel avec le motif saisi, sans le présenter comme une impression', async () => {
    const wrapper = render(); await flushPromises();
    await wrapper.get('[data-testid="motif-generation"]').setValue('Réimpression fictive');
    await wrapper.get('[data-testid="generer-document"]').trigger('click'); await flushPromises();
    const body = calls().find(c => c.url.endsWith('/administration/atelier/tirages') && c.method === 'POST')!.body;
    expect(body).toMatchObject({ batch: 'lot-a', from: '401', to: '600', format: 'pvc', reason: 'Réimpression fictive' });
    expect(body.essai).toBeUndefined();
    const ready = wrapper.get('[data-testid="document-pret"]');
    expect(ready.text()).toContain('Rien n’est encore imprimé');
    expect(wrapper.get('[data-testid="ouvrir-document"]').attributes('href')).toBe('/administration/atelier/tirages/r9/document');
  });

  it('imprime un exemple comme un essai marqué, sans lot ni plage', async () => {
    const wrapper = render(); await flushPromises();
    await wrapper.get('[data-testid="generer-essai"]').trigger('click'); await flushPromises();
    const body = calls().find(c => c.url.endsWith('/administration/atelier/tirages') && c.method === 'POST')!.body;
    expect(body.essai).toBe(true);
    expect(body.batch).toBeUndefined();
    expect(wrapper.get('[data-testid="document-pret"]').text()).toContain('Essai généré');
  });

  it('propose les étiquettes A4 comme format, sans aperçu réel, et un lien d’alignement', async () => {
    const wrapper = render(); await flushPromises();
    await wrapper.get('[data-testid="choix-format"]').setValue('etiquettes'); await flushPromises();
    expect((wrapper.get('[data-testid="afficher-reel"]').element as HTMLButtonElement).disabled).toBe(true);
    expect((wrapper.get('[data-testid="generer-essai"]').element as HTMLButtonElement).disabled).toBe(true);
    expect(wrapper.get('[data-testid="lien-alignement-atelier"]').attributes('href')).toContain('/administration/atelier/alignement?format=pvc');
    await wrapper.get('[data-testid="generer-document"]').trigger('click'); await flushPromises();
    expect(calls().find(c => c.url.endsWith('/administration/atelier/tirages') && c.method === 'POST')!.body).toMatchObject({ format: 'etiquettes', cols: 3, rows: 5 });
  });

  it('distingue les trois états de traçabilité et ne confirme que depuis « demandée »', async () => {
    const wrapper = render(); await flushPromises();
    const states = wrapper.findAll('[data-testid="etat-tirage"]').map(w => w.text());
    expect(states[0]).toContain('Document généré (pas encore imprimé)');
    expect(states[1]).toContain('Impression demandée (non confirmée)');
    expect(states[2]).toContain('Impression confirmée par l’opérateur');
    expect(wrapper.find('[data-testid="confirmer-r1"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="confirmer-r3"]').exists()).toBe(false);
    await wrapper.get('[data-testid="confirmer-r2"]').trigger('click');
    await wrapper.get('[data-testid="valider-confirmation"]').trigger('submit'); await flushPromises();
    expect(calls().find(c => c.url.endsWith('/tirages/r2/confirmation'))!.body).toMatchObject({ printed: 3, spoiled: 0 });
    expect(wrapper.text()).toContain('ESSAI');
  });

  it('change d’événement sans conserver le lot ou la plage de l’autre événement', async () => {
    const wrapper = render(); await flushPromises();
    await wrapper.get('[data-testid="choix-evenement"]').setValue('event-b'); await flushPromises();
    expect((wrapper.get('[data-testid="choix-lot"]').element as HTMLSelectElement).value).toBe('lot-b');
    expect((wrapper.get('[data-testid="plage-debut"]').element as HTMLInputElement).value).toBe('1');
    expect(wrapper.get('[data-testid="choix-lot"]').text()).not.toContain('Visiteur');
  });

  it('propose les cartes permanentes dans un mode séparé du parcours visiteurs', async () => {
    const wrapper = render(); await flushPromises();
    expect(wrapper.find('[data-testid="cartes-permanentes"]').exists()).toBe(false);
    await wrapper.get('[data-testid="type-permanents"]').trigger('click');
    expect(wrapper.get('[data-testid="cartes-permanentes"]').text()).toContain('Aucune carte préparée');
    expect(wrapper.find('[data-testid="etape-modele"]').exists()).toBe(false);
    await wrapper.get('[data-testid="type-visiteurs"]').trigger('click');
    expect(wrapper.find('[data-testid="etape-modele"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="rappel-trace"]').text()).toContain('jamais la preuve');
  });
});

describe('Atelier des badges : la gestion des modèles est dans l’espace Modèles', () => {
  it('renvoie vers la page Modèles et ne propose plus d’enregistrer, réinitialiser ni reprendre un modèle', async () => {
    const wrapper = render(); await flushPromises();
    expect(wrapper.get('[data-testid="rappel-modele"]').text()).toContain('ce tirage seulement');
    expect(wrapper.get('[data-testid="lien-modeles-badges"]').text()).toContain('Gérer les modèles');
    for (const id of ['enregistrer-modele', 'reinitialiser-modele', 'reprendre-modele', 'source-modele', 'gestion-modeles']) expect(wrapper.find(`[data-testid="${id}"]`).exists()).toBe(false);
  });

  it('garde les réglages d’aspect propres au tirage sans jamais enregistrer de modèle', async () => {
    const wrapper = render(); await flushPromises();
    expect(wrapper.find('input[name="design"]').exists()).toBe(true);
    await wrapper.findAll('input[name="design"]')[1].setValue(true); await flushPromises();
    expect(calls().some(c => c.url.endsWith('/administration/atelier/modeles') || c.url.includes('/modeles/'))).toBe(false);
  });

  it('ouvre par défaut l’historique des tirages seul : ni modèle, ni lot, ni plage ; l’atelier par lots reste accessible', async () => {
    const wrapper = mount(Atelier, { props: { ...props, vue: 'historique' } as never, global: { stubs: { AdminLayout: { template: '<main><slot /></main>' }, BadgesCartesShell: true } } });
    await flushPromises();
    expect(wrapper.get('h1').text()).toBe('Impression et historique');
    expect(wrapper.find('[data-testid="suivi-tirages"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="rappel-trace"]').exists()).toBe(true);
    for (const id of ['etape-modele', 'etape-selection', 'etape-impression', 'apercu-fictif', 'apercu-reel', 'type-visiteurs']) expect(wrapper.find(`[data-testid="${id}"]`).exists()).toBe(false);
    expect(wrapper.find('[data-testid="ouvrir-atelier-lots"]').exists()).toBe(true);
  });

  it('garde l’atelier complet quand il est demandé (lien historique ou anciens paramètres)', async () => {
    const wrapper = mount(Atelier, { props: { ...props, vue: 'atelier' } as never, global: { stubs: { AdminLayout: { template: '<main><slot /></main>' }, BadgesCartesShell: true } } });
    await flushPromises();
    expect(wrapper.find('[data-testid="etape-modele"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="retour-historique"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="suivi-tirages"]').exists()).toBe(true);
  });
});
