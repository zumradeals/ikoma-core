import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StatusBar from '../../resources/js/components/poste/StatusBar.vue';
import StepFiche from '../../resources/js/components/poste/StepFiche.vue';
import StepDecision from '../../resources/js/components/poste/StepDecision.vue';
import StepDone from '../../resources/js/components/poste/StepDone.vue';
import StepSearch from '../../resources/js/components/poste/StepSearch.vue';
import PosteFooter from '../../resources/js/components/poste/PosteFooter.vue';
import StepIdentity from '../../resources/js/components/poste/StepIdentity.vue';
import UnlistedForm from '../../resources/js/components/poste/UnlistedForm.vue';
import PosteApp from '../../resources/js/components/poste/PosteApp.vue';
import { OperationIds, type PosteRepository } from '../../resources/js/poste/repository';
import { ApiError, type Fiche, type SearchResult } from '../../resources/js/poste/types';
import { closeCamera, openCamera } from '../../resources/js/poste/media';

vi.mock('../../resources/js/poste/media', () => ({
  DOCUMENT_MAX_SIDE: 1280, PHOTO_MAX_SIDE: 640, openCamera: vi.fn(async () => ({ getTracks: () => [] })), closeCamera: vi.fn(), captureJpeg: vi.fn(async () => new Blob(['x'], { type: 'image/jpeg' })), startScan: vi.fn(async () => ({ stop: vi.fn() })),
}));

const category = { id: 'c1', name: 'Visiteur', color: '#0F5AA6', priority_circuit: false };
const vip = { id: 'c0', name: 'VIP', color: '#C9962B', priority_circuit: true };
const fiche = (over: Partial<Fiche> = {}): Fiche => ({ id: 'v1', full_name: 'Koné Mariam', organisation: 'Organisation fictive 1', host: 'Hôte fictif 1', category, zones: ['Accueil', 'Cour'], accueil_limite: false, source: 'listé', status: 'attendu', host_validation: null, valid_from: '', valid_until: '', hors_periode: false, identity_done: false, photo_done: false, validated: false, badge_code: null, ...over });
const result = (over: Partial<SearchResult> = {}): SearchResult => ({ id: 'v1', full_name: 'Koné Mariam', organisation: 'Organisation fictive 1', host: 'Hôte fictif 1', homonyms: 1, category, ...over });

function fakeRepo(over: Partial<PosteRepository> = {}): PosteRepository & { calls: string[] } {
  const calls: string[] = [];
  const repo = {
    calls,
    listPostes: async () => [{ id: 'p1', name: 'Tablette A', station: 'Poste fictif A', event_id: 'e1', event_name: 'Événement fictif' }],
    context: async () => ({ poste: { id: 'p1', name: 'Tablette A', station: 'Poste fictif A', event_id: 'e1' }, event: { id: 'e1', name: 'Événement fictif' }, hosts: [{ id: 'h1', name: 'Hôte fictif 1', service: null }], categories: [vip, category] }),
    search: async () => [result()],
    visitor: async () => fiche({ identity_done: calls.includes('identite'), photo_done: calls.includes('identite'), validated: calls.includes('valider'), badge_code: calls.includes('badge') ? 'B-014' : null }),
    saveIdentity: async () => { calls.push('identite'); },
    validate: async () => { calls.push('valider'); },
    refuse: async () => { calls.push('refuser'); },
    assignBadge: async () => { calls.push('badge'); return { badge_code: 'B-014' }; },
    enter: async () => { calls.push('entree'); return { zones: ['Accueil', 'Cour'] }; },
    createUnlisted: async () => { calls.push('non_liste'); return { visitor_id: 'n1' }; },
    pendingCount: async () => 0,
    ...over,
  };
  return repo as PosteRepository & { calls: string[] };
}

describe('composants du poste', () => {
  beforeEach(() => { window.localStorage.clear(); });
  it('affiche le réseau en mots et symbole, les opérations en attente, le poste et l’agent', () => {
    const online = mount(StatusBar, { props: { poste: 'Poste fictif A', agent: 'Agent fictif', online: true, pending: 0 } });
    expect(online.text()).toContain('En ligne'); expect(online.text()).toContain('Opérations en attente : 0'); expect(online.text()).toContain('Poste fictif A'); expect(online.text()).toContain('Agent fictif');
    const offline = mount(StatusBar, { props: { poste: null, agent: 'Agent fictif', online: false, pending: 3 } });
    expect(offline.text()).toContain('Hors ligne'); expect(offline.text()).toContain('✕'); expect(offline.text()).toContain('non choisi');
  });
  it('exige un second critère confirmé pour un homonyme avant de confirmer la fiche', async () => {
    const wrapper = mount(StepFiche, { props: { fiche: fiche(), homonyms: 3 } });
    const confirm = wrapper.findAll('button').find(b => b.text().includes('C’est bien cette personne'))!;
    expect(confirm.attributes('disabled')).toBeDefined(); expect(wrapper.text()).toContain('Homonyme'); expect(wrapper.text()).toContain('confirmez le second critère');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    expect(confirm.attributes('disabled')).toBeUndefined(); await confirm.trigger('click'); expect(wrapper.emitted('confirm')).toHaveLength(1);
    const simple = mount(StepFiche, { props: { fiche: fiche({ hors_periode: true }), homonyms: 1 } });
    expect(simple.text()).toContain('Hors de sa période'); expect(simple.findAll('button').find(b => b.text().includes('bien cette personne'))!.attributes('disabled')).toBeUndefined();
  });
  it('grise Valider avec la raison tant que l’identité et la photo manquent', () => {
    const wrapper = mount(StepDecision, { props: { name: 'Koné Mariam', horsPeriode: false, blockedReason: 'La photo n’est pas encore prise.', busy: false } });
    const validate = wrapper.findAll('button').find(b => b.text().includes('Valider'))!;
    expect(validate.attributes('disabled')).toBeDefined(); expect(wrapper.text()).toContain('La photo n’est pas encore prise.');
  });
  it('demande l’avis de l’hôte ou du superviseur hors période et le transmet', async () => {
    const wrapper = mount(StepDecision, { props: { name: 'Koné Mariam', horsPeriode: true, blockedReason: null, busy: false } });
    const validate = wrapper.findAll('button').find(b => b.text().includes('Valider'))!;
    expect(validate.attributes('disabled')).toBeDefined(); expect(wrapper.text()).toContain('indiquez l’avis obtenu');
    await wrapper.get('input[value="superviseur"]').setValue(true);
    expect(validate.attributes('disabled')).toBeUndefined(); await validate.trigger('click');
    expect(wrapper.emitted('validate')![0]).toEqual(['superviseur']);
  });
  it('exige un motif pour refuser', async () => {
    const wrapper = mount(StepDecision, { props: { name: 'Koné Mariam', horsPeriode: false, blockedReason: null, busy: false } });
    await wrapper.findAll('button').find(b => b.text().includes('Refuser'))!.trigger('click');
    const confirm = wrapper.findAll('button').find(b => b.text().includes('Confirmer le refus'))!;
    expect(confirm.attributes('disabled')).toBeDefined();
    await wrapper.get('textarea').setValue('  '); expect(confirm.attributes('disabled')).toBeDefined();
    await wrapper.get('textarea').setValue('Pièce illisible'); expect(confirm.attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit'); expect(wrapper.emitted('refuse')![0]).toEqual(['Pièce illisible']);
  });
  it('affiche le chrono final et l’objectif de 60 secondes', () => {
    const fast = mount(StepDone, { props: { name: 'Koné Mariam', badgeCode: 'B-014', zones: ['Accueil'], durationMs: 41000 } });
    expect(fast.get('[data-testid="chrono"]').text()).toContain('0 min 41 s'); expect(fast.text()).toContain('Objectif de 60 secondes tenu');
    const slow = mount(StepDone, { props: { name: 'Koné Mariam', badgeCode: 'B-014', zones: [], durationMs: 75000 } });
    expect(slow.text()).toContain('1 min 15 s'); expect(slow.text()).toContain('dépassé');
  });
  it('privilégie la caméra arrière puis permet à l’agent de passer à l’avant', async () => {
    vi.mocked(openCamera).mockClear(); vi.mocked(closeCamera).mockClear();
    const wrapper = mount(StepIdentity, { props: { name: 'Visiteur fictif', busy: false } });
    await flushPromises();
    expect(vi.mocked(openCamera)).toHaveBeenCalledWith(expect.anything(), 'environment');
    await wrapper.get('[data-testid="changer-camera"]').trigger('click');
    await flushPromises();
    expect(vi.mocked(closeCamera)).toHaveBeenCalled();
    expect(vi.mocked(openCamera)).toHaveBeenLastCalledWith(expect.anything(), 'user');
    wrapper.unmount();
  });

  it('cherche dès deux caractères, signale VIP et homonymes, et propose le non listé', async () => {
    vi.useFakeTimers();
    const search = vi.fn(async () => [result({ homonyms: 2 }), result({ id: 'v2', full_name: 'Yao Brou', category: vip })]);
    const wrapper = mount(StepSearch, { props: { repository: fakeRepo({ search }) } });
    await flushPromises();
    expect(search).toHaveBeenCalledWith('', null);
    expect(wrapper.get('[data-testid="apercu-liste"]').text()).toContain('Dix premières personnes');
    await wrapper.get('input').setValue('k'); await vi.advanceTimersByTimeAsync(400);
    expect(search).not.toHaveBeenCalledWith('k', null);
    await wrapper.get('input').setValue('ko'); await vi.advanceTimersByTimeAsync(400); await flushPromises();
    expect(search).toHaveBeenCalledWith('ko', null); expect(wrapper.emitted('started')).toBeTruthy();
    expect(wrapper.text()).toContain('Homonyme : 2'); expect(wrapper.text()).toContain('Personnalité VIP'); expect(wrapper.text()).toContain('Prioritaire');
    await wrapper.findAll('ul[aria-label="Résultats de la recherche"] button')[0].trigger('click'); expect(wrapper.emitted('select')![0][0]).toMatchObject({ id: 'v1' });
    await wrapper.findAll('button').find(b => b.text().includes('Visiteur non listé'))!.trigger('click'); expect(wrapper.emitted('unlisted')).toHaveLength(1);
    vi.useRealTimers();
  });
  it('affiche immédiatement les dix premiers, puis revient à cet aperçu si la recherche est effacée', async () => {
    const ten = Array.from({ length: 10 }, (_, i) => result({ id: String(i), full_name: 'Personne fictive ' + (i + 1) }));
    const search = vi.fn(async (query: string) => query === '' ? ten : [result({ full_name: 'Koné Mariam' })]);
    const wrapper = mount(StepSearch, { props: { repository: fakeRepo({ search }) } });
    await flushPromises();
    expect(wrapper.findAll('ul[aria-label="Résultats de la recherche"] button')).toHaveLength(10);
    expect(wrapper.text()).toContain('Personne fictive 1');
    await wrapper.get('input').setValue('ko');
    await new Promise(r => setTimeout(r, 200));
    await flushPromises();
    expect(wrapper.findAll('ul[aria-label="Résultats de la recherche"] button')).toHaveLength(1);
    expect(wrapper.text()).toContain('Koné Mariam');
    await wrapper.get('input').setValue('');
    await flushPromises();
    expect(wrapper.findAll('ul[aria-label="Résultats de la recherche"] button')).toHaveLength(10);
    expect(search).toHaveBeenCalledWith('', null);
  });
  it('prépare la fiche rapide d’un non listé avec la catégorie « Visiteur » par défaut', async () => {
    const context = await fakeRepo().context();
    const wrapper = mount(UnlistedForm, { props: { context, busy: false } });
    const submit = wrapper.get('button[type="submit"]');
    expect(submit.attributes('disabled')).toBeDefined(); expect(wrapper.text()).toContain('Indiquez le nom');
    await wrapper.findAll('input')[0].setValue('Personne fictive'); expect(wrapper.text()).toContain('personne à voir');
    await wrapper.findAll('select')[0].setValue('h1'); expect(submit.attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit');
    expect(wrapper.emitted('submit')![0][0]).toEqual({ name: 'Personne fictive', organisation: '', host_id: 'h1', category_id: 'c1' });
  });
  it('conserve l’identifiant d’une opération jusqu’à sa réponse définitive', () => {
    const ids = new OperationIds();
    const first = ids.get('badge:v1:B-1'); expect(ids.get('badge:v1:B-1')).toBe(first); expect(ids.get('badge:v1:B-2')).not.toBe(first);
    ids.done('badge:v1:B-1'); expect(ids.get('badge:v1:B-1')).not.toBe(first); expect(first).toMatch(/^[0-9a-f-]{36}$/);
  });
});

describe('application du poste', () => {
  beforeEach(() => { window.localStorage.clear(); });
  it('déroule les 7 étapes dans une seule application, dans l’ordre, sans rechargement', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'setTimeout', 'Date'] });
    const repo = fakeRepo();
    const wrapper = mount(PosteApp, { props: { agent: 'Agent fictif', repository: repo } });
    await flushPromises();
    expect(wrapper.text()).toContain('Choisissez votre poste'); await wrapper.get('ul button').trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Étape 1 sur 7'); expect(wrapper.text()).toContain('Poste fictif A');
    await wrapper.get('input[type="search"]').setValue('kone'); await vi.advanceTimersByTimeAsync(400); await flushPromises();
    await wrapper.get('ul[aria-label="Résultats de la recherche"] button').trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Étape 2 sur 7');
    await vi.advanceTimersByTimeAsync(10000);
    await wrapper.findAll('button').find(b => b.text().includes('bien cette personne'))!.trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Étape 3 sur 7');
    const save = () => wrapper.findAll('button').find(b => b.text().includes('Enregistrer et passer'))!;
    expect(save().attributes('disabled')).toBeDefined();
    await wrapper.get('input[type="checkbox"]').setValue(true); await wrapper.findAll('button').find(b => b.text().includes('Prendre la photo'))!.trigger('click'); await flushPromises();
    expect(save().attributes('disabled')).toBeUndefined(); await save().trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Étape 4 sur 7'); expect(repo.calls).toEqual(['identite']);
    await wrapper.findAll('button').find(b => b.text().includes('Valider'))!.trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Étape 5 sur 7');
    await wrapper.get('input.field').setValue('b-014'); await wrapper.get('form[aria-label="Saisir le code du badge"]').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('Étape 6 sur 7'); expect(wrapper.text()).toContain('Remettez le badge B-014');
    await vi.advanceTimersByTimeAsync(20000);
    await wrapper.findAll('button').find(b => b.text().includes('Autoriser l’accès'))!.trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Entrée enregistrée'); expect(wrapper.get('[data-testid="chrono"]').text()).toContain('0 min 30 s');
    expect(repo.calls).toEqual(['identite', 'valider', 'badge', 'entree']);
    await wrapper.findAll('button').find(b => b.text().includes('Visiteur suivant'))!.trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Étape 1 sur 7'); expect(wrapper.text()).not.toContain('Entrée enregistrée');
    vi.useRealTimers();
  });
  it('affiche le refus du badge en français avec l’action suivante et laisse réessayer', async () => {
    const refused = vi.fn().mockRejectedValueOnce(new ApiError('deja_associe', 'Badge déjà associé à Personne fictive. Prenez un autre badge.', 'Prenez un autre badge.', 422)).mockResolvedValueOnce({ badge_code: 'B-015' });
    const repo = fakeRepo({ assignBadge: refused, visitor: async () => fiche({ identity_done: true, photo_done: true, validated: true }) });
    const wrapper = mount(PosteApp, { props: { agent: 'Agent fictif', repository: repo } });
    await flushPromises(); await wrapper.get('ul button').trigger('click'); await flushPromises();
    await wrapper.get('input[type="search"]').setValue('kone'); await new Promise(r => setTimeout(r, 300)); await flushPromises();
    await wrapper.get('ul[aria-label="Résultats de la recherche"] button').trigger('click'); await flushPromises();
    await wrapper.findAll('button').find(b => b.text().includes('bien cette personne'))!.trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Étape 5 sur 7');
    await wrapper.get('input.field').setValue('B-014'); await wrapper.get('form[aria-label="Saisir le code du badge"]').trigger('submit'); await flushPromises();
    expect(wrapper.get('[data-testid="erreur"]').text()).toContain('Badge déjà associé'); expect(wrapper.get('[data-testid="erreur"]').text()).toContain('Prenez un autre badge.');
    await wrapper.get('input.field').setValue('B-015'); await wrapper.get('form[aria-label="Saisir le code du badge"]').trigger('submit'); await flushPromises();
    expect(refused).toHaveBeenCalledTimes(2); expect(wrapper.text()).toContain('Étape 6 sur 7');
    expect(refused.mock.calls[0][0]).not.toBe(refused.mock.calls[1][0]); // un autre code est une autre opération
  });
});

describe('écran de recherche conforme à la maquette', () => {
  beforeEach(() => { window.localStorage.clear(); });
  const people = [result({ id: 'a', full_name: 'Koné Mariam', job_title: 'Directrice fictive', access: 'autorise' }), result({ id: 'b', full_name: 'Yao Brou', category: vip, access: 'hors_periode' }), result({ id: 'c', full_name: 'Traoré Awa', access: 'present' })];
  const categories = [{ ...category, visitor_count: 38 }, { ...vip, visitor_count: 1 }, { id: 'c3', name: 'Expert', color: '#2E7D32', priority_circuit: false, visitor_count: 0 }];
  const mountSearch = async (search = vi.fn(async () => people)) => {
    const wrapper = mount(StepSearch, { props: { repository: fakeRepo({ search, visitor: async () => fiche({ id: 'a', visit_purpose: 'Visite de site fictive' }) }), eventName: 'Événement fictif', categories, total: 40, period: '02 – 14 nov. 2026' } });
    await flushPromises(); return { wrapper, search };
  };
  it('affiche les onglets de toutes les catégories avec le nombre de la liste et filtre par la recherche elle-même', async () => {
    const { wrapper, search } = await mountSearch();
    const tabs = wrapper.get('[data-testid="onglets-categories"]');
    expect(tabs.text()).toContain('Tous (40)'); expect(tabs.text()).toContain('Visiteur (38)'); expect(tabs.text()).toContain('VIP (1)'); expect(tabs.text()).toContain('Expert (0)');
    await wrapper.get('[data-testid="onglet-categorie-c0"]').trigger('click'); await flushPromises();
    expect(search).toHaveBeenLastCalledWith('', 'c0');
    await wrapper.get('[data-testid="onglet-tous"]').trigger('click'); await flushPromises();
    expect(search).toHaveBeenLastCalledWith('', null);
  });
  it('affiche le statut, la fonction et l’aperçu complet (type, fonction, motif, période, événement) d’une ligne touchée', async () => {
    const { wrapper } = await mountSearch();
    expect(wrapper.get('[data-testid="statut-a"]').text()).toBe('Autorisé'); expect(wrapper.get('[data-testid="statut-b"]').text()).toBe('Hors période'); expect(wrapper.get('[data-testid="statut-c"]').text()).toBe('Déjà entré');
    expect(wrapper.find('[data-testid="apercu-vide"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="continuer-enregistrement"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid="ligne-a"]').trigger('click'); await flushPromises();
    expect(wrapper.emitted('select')).toBeUndefined();
    expect(wrapper.get('[data-testid="apercu-nom"]').text()).toBe('Koné Mariam'); expect(wrapper.get('[data-testid="apercu-statut"]').text()).toContain('Autorisé');
    expect(wrapper.get('[data-testid="apercu-fonction"]').text()).toBe('Directrice fictive'); expect(wrapper.get('[data-testid="apercu-motif"]').text()).toBe('Visite de site fictive');
    expect(wrapper.get('[data-testid="apercu-personne"]').text()).toContain('Événement fictif');
    await wrapper.get('[data-testid="continuer-enregistrement"]').trigger('click');
    expect(wrapper.emitted('select')![0][0]).toMatchObject({ id: 'a' });
  });
  it('le bouton « Sélectionner » d’une ligne enregistre directement, sans passer par l’aperçu', async () => {
    const { wrapper } = await mountSearch();
    await wrapper.get('[data-testid="ligne-c"] button').trigger('click');
    expect(wrapper.emitted('select')![0][0]).toMatchObject({ id: 'c' });
  });
  it('la barre basse dit l’état réel : appareil, poste, dernière synchronisation, verrouillage', async () => {
    const sync = { online: true, syncing: false, pending: 0, conflicts: 0, lastSyncAt: '2027-01-10T10:42:00Z', lastError: null, needsLogin: false, revoked: false, stale: false, clockWarning: false };
    const wrapper = mount(PosteFooter, { props: { poste: 'Poste fictif A', device: 'Tablette fictive A', agent: 'Agent fictif', online: true, sync } });
    expect(wrapper.text()).toContain('Enrôlé'); expect(wrapper.text()).toContain('Poste fictif A'); expect(wrapper.text()).toContain('Appareil : Tablette fictive A'); expect(wrapper.text()).toContain('Poste déverrouillé');
    expect(wrapper.get('[data-testid="bloc-synchronisation"]').text()).toContain('À jour');
    await wrapper.get('[data-testid="verrouiller"]').trigger('click'); expect(wrapper.emitted('lock')).toHaveLength(1);
    await wrapper.get('[data-testid="synchroniser"]').trigger('click'); expect(wrapper.emitted('sync')).toHaveLength(1);
    const never = mount(PosteFooter, { props: { poste: null, agent: 'A', online: false, sync: { ...sync, lastSyncAt: null } } });
    expect(never.get('[data-testid="derniere-sync"]').text()).toBe('jamais'); expect(never.text()).not.toContain('À jour');
  });
  it('le bandeau d’événement et l’en-tête reprennent les données réelles du poste', async () => {
    const context = { poste: { id: 'p1', name: 'Tablette fictive A', station: 'Poste fictif A', event_id: 'e1' }, event: { id: 'e1', name: 'Événement fictif', starts_at: '2020-11-02T08:00:00Z', ends_at: '2099-11-14T18:00:00Z', site: 'Site fictif' }, visitor_count: 40, present_count: 38, absent_count: 2, hosts: [], categories };
    const repo = fakeRepo({ context: async () => context, search: async () => people });
    const wrapper = mount(PosteApp, { props: { agent: 'Agent fictif', repository: repo, sync: { online: true, syncing: false, pending: 3, conflicts: 2, lastSyncAt: new Date().toISOString(), lastError: null, needsLogin: false, revoked: false, stale: false, clockWarning: false } } });
    await flushPromises(); await wrapper.get('ul button').trigger('click'); await flushPromises();
    expect(wrapper.get('[data-testid="evt-participants"]').text()).toBe('40'); expect(wrapper.get('[data-testid="evt-presents"]').text()).toBe('38'); expect(wrapper.get('[data-testid="evt-absents"]').text()).toBe('2');
    expect(wrapper.get('[data-testid="evt-site"]').text()).toContain('Site fictif'); expect(wrapper.get('[data-testid="evt-etat"]').text()).toBe('En cours');
    expect(wrapper.get('[data-testid="en-attente"]').text()).toBe('3'); expect(wrapper.get('[data-testid="cloche"]').text()).toContain('2');
    expect(wrapper.get('[data-testid="entete-synchro"]').text()).toContain('Aujourd’hui à'); expect(wrapper.get('[data-testid="poste-en-cours"]').text()).toContain('Poste fictif A');
  });
});
