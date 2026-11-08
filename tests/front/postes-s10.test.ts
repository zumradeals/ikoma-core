import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../resources/js/poste/media', () => ({
  PHOTO_MAX_SIDE: 640, openCamera: vi.fn(), closeCamera: vi.fn(), captureJpeg: vi.fn(),
  startScan: vi.fn(async () => ({ stop: vi.fn() })),
}));

import AccueilOperations from '../../resources/js/components/poste/AccueilOperations.vue';
import ControleApp from '../../resources/js/components/poste/ControleApp.vue';
import ExpectedVisits from '../../resources/js/components/poste/ExpectedVisits.vue';
import IncidentsPanel from '../../resources/js/components/poste/IncidentsPanel.vue';
import AgentLayout from '../../resources/js/layouts/AgentLayout.vue';
import type { PosteRepository, SitePosteRepository } from '../../resources/js/poste/repository';
import { ApiError, type ExpectedVisit, type IncidentsView } from '../../resources/js/poste/types';

const visit = (over: Partial<ExpectedVisit> = {}): ExpectedVisit => ({ id: 'v1', full_name: 'Koné Mariam', organisation: 'Org fictive', expected_at: '2027-01-10T10:30:00Z', state: 'attendu', category: { id: 'c', name: 'VIP', color: '#C9962B', priority_circuit: true }, host: 'Hôte fictif', plates: null, ...over });
const view = (over: Partial<IncidentsView> = {}): IncidentsView => ({ controls: [{ id: 'c1', occurred_at: '2027-01-10T10:00:00Z', checkpoint: 'Soute', full_name: 'Bamba Second', badge_code: 'B-009', reason: 'zone_non_autorisee' }], refusals: [{ id: 'r1', reason: 'Badge annulé', refused_at: '2027-01-10T09:00:00Z', decision: null, visitor_id: 'v9', full_name: 'Yéo Refus' }], can_see_refusals: true, ...over });
const fake = (over: Record<string, unknown> = {}) => ({ expectedVisits: vi.fn(async () => [visit(), visit({ id: 'v2', full_name: 'Traoré Awa', state: 'en_retard', plates: 'AB123CD' })]), incidents: vi.fn(async () => view()), capabilities: () => ['accueil', 'scan', 'sortie'], searchVehicles: vi.fn(async () => []), ...over }) as unknown as PosteRepository;

beforeEach(() => { window.localStorage.clear(); window.history.replaceState({}, '', '/'); });

describe('visites attendues au poste d’accueil', () => {
  it('liste les visites avec leur retard et ouvre la fiche choisie', async () => {
    const wrapper = mount(ExpectedVisits, { props: { repository: fake() } });
    expect(wrapper.find('[data-testid=attendus-chargement]').exists()).toBe(true);
    await flushPromises();
    expect(wrapper.find('[data-testid=attendue-attendu]').exists()).toBe(true);
    expect(wrapper.get('[data-testid=attendue-en_retard]').text()).toContain('En retard');
    expect(wrapper.get('[data-testid=attendue-en_retard]').text()).toContain('AB123CD');
    expect(wrapper.text()).toContain('Une annonce n’est pas une présence');
    await wrapper.get('[data-testid=ouvrir-attendue-v2]').trigger('click');
    expect(wrapper.emitted('open')).toEqual([['v2']]);
  });

  it('dit explicitement quand rien n’est attendu, puis affiche l’erreur et permet de réessayer', async () => {
    const empty = mount(ExpectedVisits, { props: { repository: fake({ expectedVisits: vi.fn(async () => []) }) } });
    await flushPromises();
    expect(empty.get('[data-testid=aucune-attendue]').text()).toContain('Aucune visite');
    const expectedVisits = vi.fn().mockRejectedValueOnce(new ApiError('hors_ligne', 'Cette opération nécessite le réseau.')).mockResolvedValueOnce([visit()]);
    const failing = mount(ExpectedVisits, { props: { repository: fake({ expectedVisits }) } });
    await flushPromises();
    expect(failing.get('[data-testid=attendus-erreur]').text()).toContain('nécessite le réseau');
    await failing.get('[data-testid=attendus-reessayer]').trigger('click');
    await flushPromises();
    expect(failing.find('[data-testid=attendus-erreur]').exists()).toBe(false);
    expect(failing.find('[data-testid=attendue-attendu]').exists()).toBe(true);
  });
});

describe('refus et incidents du poste', () => {
  it('montre les contrôles refusés avec leur motif et, à l’accueil, les accès refusés', async () => {
    const wrapper = mount(IncidentsPanel, { props: { repository: fake(), reasons: { zone_non_autorisee: 'Zone non autorisée' } } });
    await flushPromises();
    expect(wrapper.get('[data-testid=controle-refuse]').text()).toContain('Zone non autorisée');
    expect(wrapper.get('[data-testid=controle-refuse]').text()).toContain('badge B-009');
    expect(wrapper.get('[data-testid=refus-accueil]').text()).toContain('en attente de décision');
  });

  it('ne montre pas les accès refusés au poste qui n’en a pas la compétence, et dit quand il n’y a rien', async () => {
    const soute = mount(IncidentsPanel, { props: { repository: fake({ incidents: vi.fn(async () => view({ refusals: [], can_see_refusals: false, controls: [] })) }) } });
    await flushPromises();
    expect(soute.find('[data-testid=aucun-controle-refuse]').exists()).toBe(true);
    expect(soute.find('[data-testid=refus-accueil]').exists()).toBe(false);
    expect(soute.text()).not.toContain('Accès refusés à l’accueil');
  });

  it('affiche l’erreur de chargement sans bloquer le poste', async () => {
    const wrapper = mount(IncidentsPanel, { props: { repository: fake({ incidents: vi.fn().mockRejectedValue(new ApiError('erreur', 'Réseau indisponible.')) }) } });
    await flushPromises();
    expect(wrapper.get('[data-testid=incidents-erreur]').text()).toContain('Réseau indisponible');
  });
});

describe('autres opérations de l’accueil', () => {
  it('propose scan, sortie et restitution vers l’écran de contrôle, avec le bon mode', () => {
    const wrapper = mount(AccueilOperations, { props: { repository: fake() } });
    expect(wrapper.get('[data-testid=operation-controle]').attributes('href')).toBe('/controle?mode=controle');
    expect(wrapper.get('[data-testid=operation-sortie]').attributes('href')).toBe('/controle?mode=sortie');
    expect(wrapper.get('[data-testid=operation-restitution]').text()).toContain('distinct de la sortie');
    expect(wrapper.find('[data-testid=ouvrir-attendus]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=ouvrir-incidents]').exists()).toBe(true);
  });

  it('n’offre que ce que la capacité du poste permet', () => {
    const scanOnly = mount(AccueilOperations, { props: { repository: fake({ capabilities: () => ['scan'] }) } });
    expect(scanOnly.find('[data-testid=operation-controle]').exists()).toBe(true);
    expect(scanOnly.find('[data-testid=operation-sortie]').exists()).toBe(false);
    expect(scanOnly.find('[data-testid=ouvrir-attendus]').exists()).toBe(false);
    const none = mount(AccueilOperations, { props: { repository: fake({ capabilities: () => [], searchVehicles: undefined, expectedVisits: undefined }) } });
    expect(none.find('[data-testid=operation-controle]').exists()).toBe(false);
  });

  it('ouvre et ferme les panneaux, et signale le mode hors ligne', async () => {
    const wrapper = mount(AccueilOperations, { props: { repository: fake() } });
    await wrapper.get('[data-testid=ouvrir-attendus]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid=attendus-poste]').exists()).toBe(true);
    await wrapper.get('[data-testid=ouvrir-attendus]').trigger('click');
    expect(wrapper.find('[data-testid=attendus-poste]').exists()).toBe(false);
    const offline = vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false);
    const cut = mount(AccueilOperations, { props: { repository: fake() } });
    expect(cut.get('[data-testid=operations-hors-ligne]').text()).toContain('nécessite le réseau');
    expect(cut.get('[data-testid=operation-sortie]').attributes('aria-disabled')).toBe('true');
    expect(cut.get('[data-testid=ouvrir-attendus]').attributes('disabled')).toBeDefined();
    offline.mockRestore();
  });
});

describe('écran de contrôle selon les capacités du poste (soute)', () => {
  const siteRepo = (caps?: string[]) => ({
    checkpoints: async () => [{ type: 'zone' as const, id: 'z1', name: 'Soute' }], control: vi.fn(), exit: vi.fn(), giveBackCheck: vi.fn(), giveBack: vi.fn(),
    presentCount: vi.fn(async () => 4), pendingCount: async () => 0, incidents: vi.fn(async () => view({ refusals: [], can_see_refusals: false })),
    ...(caps ? { capabilities: () => caps } : {}),
  }) as unknown as SitePosteRepository;
  const open = async (r: SitePosteRepository) => {
    window.localStorage.setItem('ikoma.controle.point', JSON.stringify({ type: 'zone', id: 'z1' }));
    const wrapper = mount(ControleApp, { props: { agent: 'Agent fictif', repository: r } });
    await flushPromises();
    return wrapper;
  };

  it('à la soute, n’offre que le contrôle individuel : ni sortie, ni restitution, ni accueil', async () => {
    const wrapper = await open(siteRepo(['scan']));
    expect(wrapper.find('[data-testid=mode-sortie]').exists()).toBe(false);
    expect(wrapper.find('[data-testid=mode-restitution]').exists()).toBe(false);
    expect(wrapper.get('[data-testid=operations-autorisees]').text()).toContain('Contrôle d’un badge');
    expect(wrapper.get('[data-testid=valider-code]').text()).toBe('Contrôler ce badge');
    // la navigation ne propose pas l'accueil à un poste qui n'en a pas la capacité
    expect(wrapper.find('a[href="/poste"]').exists()).toBe(false);
  });

  it('au poste d’accueil, garde les trois modes et présélectionne celui de l’adresse', async () => {
    window.history.replaceState({}, '', '/controle?mode=restitution');
    const wrapper = await open(siteRepo(['accueil', 'scan', 'sortie']));
    expect(wrapper.find('[data-testid=mode-controle]').exists()).toBe(true);
    expect(wrapper.get('[data-testid=mode-restitution]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.get('[data-testid=valider-code]').text()).toBe('Vérifier le badge à restituer');
  });

  it('ignore un mode demandé que le poste n’a pas le droit d’utiliser', async () => {
    window.history.replaceState({}, '', '/controle?mode=sortie');
    const wrapper = await open(siteRepo(['scan']));
    expect(wrapper.find('[data-testid=operations-autorisees]').exists()).toBe(true);
    expect(wrapper.get('[data-testid=valider-code]').text()).toBe('Contrôler ce badge');
  });

  it('refuse clairement un poste sans aucune capacité', async () => {
    const wrapper = await open(siteRepo([]));
    expect(wrapper.get('[data-testid=aucune-operation]').text()).toContain('n’est habilité à aucune opération');
  });

  it('sans capacités connues, ne restreint rien (comportement existant)', async () => {
    const wrapper = await open(siteRepo());
    expect(wrapper.find('[data-testid=mode-sortie]').exists()).toBe(true);
  });

  it('consulte les refus du poste sans quitter l’écran', async () => {
    const wrapper = await open(siteRepo(['scan']));
    await wrapper.get('[data-testid=ouvrir-incidents]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid=incidents-poste]').text()).toContain('Contrôles refusés à ce poste');
    expect(wrapper.get('[data-testid=controle-refuse]').text()).toContain('Zone non autorisée');
  });
});

describe('navigation de l’agent selon le poste', () => {
  it('masque l’accueil sans la capacité « accueil » et le contrôle sans « scan » ni « sortie »', () => {
    const items = (caps: string[] | null) => mount(AgentLayout, { props: { active: 'home', permissions: ['visiteurs.rechercher', 'badges.controler', 'groupes.appel_nominal'], capabilities: caps } }).findAll('a').map(a => a.attributes('href'));
    expect(items(null)).toEqual(expect.arrayContaining(['/poste', '/controle']));
    expect(items(['scan'])).toContain('/controle');
    expect(items(['scan'])).not.toContain('/poste');
    expect(items(['accueil'])).not.toContain('/controle');
  });
});
