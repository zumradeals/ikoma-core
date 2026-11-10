import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const { post, get, put } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), put: vi.fn() }));
let formErrors: Record<string, string> = {};
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['listes.importer'] } } }),
  router: { get, post },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => { const form: Record<string, unknown> = reactive({ ...data, errors: formErrors, processing: false, post, put, reset: vi.fn() }); form.transform = () => form; return form; },
}));

import Personnes from '../../resources/js/pages/Admin/Personnes.vue';
import Personne from '../../resources/js/pages/Admin/Personne.vue';

const person = { id: 'p1', full_name: 'Koné Mariam', organisation: 'Atelier fictif', status: 'actif' };
const participation = (over: object = {}) => ({ id: 'v1', event_id: 'e1', event_name: 'Stage de sécurité', event_kind: 'stage', category: 'Stagiaire', status: 'sorti', valid_from: '2027-01-10T08:00:00Z', valid_until: '2027-01-14T18:00:00Z', withdrawn_at: null, closed_at: null, closure_reason: null, holder_id: null, ...over });

const row = (over: object = {}) => ({ id: 'p1', full_name: 'Koné Mariam', organisation: null, status: 'actif', holder_id: null, identifier: null, holder_status: null, institutional_category: null, category_label: null, category_color: null, has_photo: false,
  participations_actives: 2, participations_total: 2, habilitations_actives: 1, ...over });
const kpis = { personnes: 3, habilitations_actives: 4, a_echeance: 1, retirees_ou_expirees: 2, jours_echeance: 30 };
const meta = (over: object = {}) => ({ current_page: 1, last_page: 1, total: 2, from: 1, to: 2, ...over });
const right = (over: object = {}) => ({ id: 'r1', origin: 'activite' as 'activite' | 'permanente', zone: 'Zone pédagogique', event: 'Stage fictif', kind: 'standard', reason: null, valid_from: '2027-01-01T00:00:00Z', valid_until: '2027-02-01T00:00:00Z', state: 'active', ...over });
const profile = (over: object = {}) => ({
  person: { id: 'p2', first_name: 'Awa', last_name: 'Fictive', full_name: 'Awa Fictive', organisation: 'Organisation fictive', status: 'actif', created_at: '2027-01-02T10:00:00Z', updated_at: '2027-01-05T10:00:00Z' },
  holder: { id: 'h1', identifier: 'AILCT-0001', position: 'Formatrice', quality: null, status: 'actif', institutional_category: 'AILCT-01', category_label: 'Personnel AILCT', engagement_starts_on: '2027-01-01', engagement_ends_on: '2027-12-31', has_photo: false },
  participations: [{ id: 'v1', event_name: 'Stage fictif', event_kind: 'stage', category: 'Stagiaire', status: 'attendu', valid_from: '2027-01-01T00:00:00Z', valid_until: '2027-02-01T00:00:00Z', withdrawn_at: null, closed_at: null, holder_id: null }],
  assignments: [{ id: 'a1', badge_id: 'b1', badge_code: 'B-007', event_name: 'Stage fictif', assigned_at: '2027-01-03T10:00:00Z', returned_at: null, cancelled_at: null }],
  entitlements: { permanentes: [right({ id: 'r0', origin: 'permanente', kind: null, zone: 'Zone permanente', state: 'active' })], activites: [right(), right({ id: 'r2', zone: 'Zone retirée', state: 'retiree' })] },
  access: { zones: ['Zone pédagogique · Stage fictif'], posts: [{ id: 'c2', code: 'poste_2', name: 'Poste 2' }], next_expiry: '2027-02-01T00:00:00Z', effective: 2 },
  timeline: [{ at: '2027-01-03T10:00:00Z', label: 'Badge B-007 attribué' }, { at: '2027-01-02T10:00:00Z', label: 'Identité enregistrée' }],
  ...over,
});
const base = { query: '', filters: { statut: '', institutionnel: '', habilitations: '' }, persons: [row(), row({ id: 'p2', full_name: 'Awa Fictive', identifier: 'AILCT-0001', institutional_category: 'AILCT-01', category_label: 'Personnel AILCT', participations_actives: 0, habilitations_actives: 3, holder_id: 'h1' })],
  meta: meta(), kpis, institutionalOptions: [{ code: 'AILCT-01', label: 'Personnel AILCT' }], selected: null, can: { habilitations: true } };

describe('registre des personnes', () => {
  it('présente le registre avec des rattachements séparés : statut institutionnel, participations actives, habilitations actives', () => {
    formErrors = {};
    const wrapper = mount(Personnes, { props: base });
    expect(wrapper.get('[data-testid=institutionnel-p2]').text()).toBe('Personnel AILCT');
    expect(wrapper.get('[data-testid=institutionnel-p1]').text()).toBe('—');
    expect(wrapper.get('[data-testid=synthese-p1]').text()).toBe('2 participations · 1 habilitation');
    expect(wrapper.get('[data-testid=synthese-p2]').text()).toBe('0 participation · 3 habilitations');
    expect(wrapper.find('[data-testid=fiche-vide]').exists()).toBe(true);
    // aucune catégorie de participation n'est attribuée à la personne dans le registre
    expect(wrapper.get('[data-testid=liste-personnes]').text()).not.toContain('Stagiaire');
  });

  it('affiche des indicateurs tirés des données réelles', () => {
    const wrapper = mount(Personnes, { props: base });
    expect([...['personnes', 'actives', 'echeance', 'terminees']].map(k => wrapper.get(`[data-testid=kpi-${k}]`).text())).toEqual(['3', '4', '1', '2']);
    expect(wrapper.text()).toContain('À échéance (≤ 30 j)');
  });

  it('garde un chemin vers l’import de liste et les fiches d’invités, retirés du menu', () => {
    const wrapper = mount(Personnes, { props: base });
    expect(wrapper.get('[data-testid=importer-liste]').attributes('href')).toBe('/administration/imports');
    expect(wrapper.find('[data-testid=fiches-invites]').exists()).toBe(false); // les fiches d’invités et le personnel permanent ont leurs onglets
  });

  it('cherche et filtre par le serveur, sans valeur vide, et pagine', async () => {
    get.mockReset();
    const wrapper = mount(Personnes, { props: { ...base, meta: meta({ last_page: 3, total: 60, to: 25 }) } });
    await wrapper.get('[data-testid=recherche-personne]').setValue('awa');
    await wrapper.get('[data-testid=filtre-institutionnel]').setValue('aucun');
    expect(get).toHaveBeenLastCalledWith('/administration/personnes', { q: 'awa', institutionnel: 'aucun' }, expect.anything());
    await wrapper.get('[data-testid=page-suivante]').trigger('click');
    expect(get).toHaveBeenLastCalledWith('/administration/personnes', { q: 'awa', institutionnel: 'aucun', page: 2 }, expect.anything());
    expect((wrapper.get('[data-testid=page-precedente]').element as HTMLButtonElement).disabled).toBe(true);
  });

  it('ouvre la fiche par un rechargement partiel de la seule propriété du panneau', async () => {
    get.mockReset();
    const wrapper = mount(Personnes, { props: base });
    await wrapper.get('[data-testid=selectionner-personne-p2]').trigger('click');
    expect(get).toHaveBeenCalledWith('/administration/personnes', { personne: 'p2', page: 1 }, expect.objectContaining({ only: ['selected', 'flash'], preserveState: true }));
  });

  it('demande une confirmation explicite quand une personne de même nom existe, depuis le panneau de création', async () => {
    formErrors = { full_name: 'Une personne de même nom existe déjà (1). Reprenez-la depuis la recherche, ou confirmez qu’il s’agit d’une autre personne.' };
    const wrapper = mount(Personnes, { props: { ...base, persons: [] } });
    expect(wrapper.find('[data-testid=form-personne]').exists()).toBe(false);
    await wrapper.get('[data-testid=ajouter-personne]').trigger('click');
    expect(wrapper.find('[data-testid=form-personne]').exists()).toBe(true);
    expect(wrapper.get('[data-testid=confirmer-homonyme]').text()).toContain('autre personne');
    expect(wrapper.find('[data-testid=aucune-personne]').exists()).toBe(true);
    formErrors = {};
  });
});

describe('fiche en panneau', () => {
  const open = (over: object = {}) => mount(Personnes, { props: { ...base, selected: profile(over) } });

  it('présente l’identité, le statut institutionnel et le profil permanent, sans champ inventé', () => {
    const wrapper = open();
    expect(wrapper.get('[data-testid=nom-personne]').text()).toContain('Awa Fictive');
    expect(wrapper.get('[data-testid=fiche-identifiant]').text()).toBe('AILCT-0001');
    expect(wrapper.get('[data-testid=fiche-institutionnel]').text()).toBe('Personnel AILCT');
    expect(wrapper.get('[data-testid=profil-permanent]').text()).toContain('Formatrice');
    expect(wrapper.text()).not.toMatch(/Téléphone|Contact|Matricule/);
    expect(wrapper.get('[data-testid=ouvrir-personne-p2]').attributes('href')).toBe('/administration/personnes/p2');
  });

  it('indique l’absence de profil permanent plutôt que de supposer un statut', () => {
    const wrapper = open({ holder: null });
    expect(wrapper.find('[data-testid=fiche-institutionnel]').exists()).toBe(false);
    expect(wrapper.get('[data-testid=sans-profil-permanent]').text()).toContain('pas de profil permanent');
  });

  it('sépare les habilitations permanentes et celles liées aux activités, avec origine, période et état', async () => {
    const wrapper = open();
    await wrapper.get('[data-testid=ptab-habilitations]').trigger('click');
    expect(wrapper.get('[data-testid=habilitations-permanentes]').text()).toContain('Origine : permanente');
    expect(wrapper.get('[data-testid=habilitations-activites]').text()).toContain('Origine : activité « Stage fictif »');
    expect(wrapper.get('[data-testid=habilitation-r2]').text()).toContain('Retirée');
    expect(wrapper.get('[data-testid=habilitation-r1]').text()).toContain('Active');
    expect(wrapper.get('[data-testid=resume-postes-personne]').text()).toBe('Poste 2');
    expect(wrapper.get('[data-testid=resume-acces]').text()).toContain('Prochaine échéance');
  });

  it('n’offre que des liens vers les écrans de gestion existants, et seulement avec la permission', async () => {
    const withRight = open();
    await withRight.get('[data-testid=ptab-habilitations]').trigger('click');
    expect(withRight.get('[data-testid=gerer-permanentes]').attributes('href')).toBe('/administration/habilitations');
    expect(withRight.get('[data-testid=gerer-participants]').attributes('href')).toBe('/administration/acces');
    expect(withRight.findAll('button').filter(b => /accorder|retirer|suspendre/i.test(b.text()))).toHaveLength(0);
    const without = mount(Personnes, { props: { ...base, can: { habilitations: false }, selected: profile() } });
    await without.get('[data-testid=ptab-habilitations]').trigger('click');
    expect(without.find('[data-testid=gerer-permanentes]').exists()).toBe(false);
  });

  it('présente badges et activités en consultation, avec la catégorie propre à chaque activité', async () => {
    const wrapper = open();
    await wrapper.get('[data-testid=ptab-badges]').trigger('click');
    expect(wrapper.get('[data-testid=liste-attributions]').text()).toContain('B-007');
    expect(wrapper.get('[data-testid=onglet-badges]').text()).toContain('pas une habilitation');
    await wrapper.get('[data-testid=ptab-activites]').trigger('click');
    expect(wrapper.get('[data-testid=participation-v1]').text()).toContain('Catégorie de l’activité : Stagiaire');
    await wrapper.get('[data-testid=ptab-historique]').trigger('click');
    expect(wrapper.get('[data-testid=onglet-historique]').text()).toContain('Badge B-007 attribué');
  });

  it('se referme par le bouton ou Échap', async () => {
    get.mockReset();
    const wrapper = open();
    await wrapper.get('[data-testid=fermer-fiche]').trigger('click');
    expect(get).toHaveBeenLastCalledWith('/administration/personnes', { page: 1 }, expect.objectContaining({ only: ['selected', 'flash'] }));
    await wrapper.get('[data-testid=fiche-personne]').trigger('keydown', { key: 'Escape' });
    expect(get).toHaveBeenCalledTimes(2);
  });
});

describe('fiche d’une personne', () => {
  const base = { person, participations: [participation(), participation({ id: 'v2', event_id: 'e2', event_name: 'Visite fictive', event_kind: 'visite', closed_at: '2027-02-01T10:00:00Z', closure_reason: 'Visite terminée' })], assignments: [{ id: 'a1', badge_code: 'B-007', badge_id: 'b7', event_name: 'Stage de sécurité', assigned_at: '2027-01-10T09:00:00Z', returned_at: '2027-01-14T17:00:00Z', cancelled_at: null, cancellation_reason: null }], holder: null,
    events: [{ id: 'e3', name: 'Stage suivant', kind: 'stage' }, { id: 'e4', name: 'Autre activité', kind: 'evenement' }], categories: [{ id: 'c3', event_id: 'e3', name: 'Stagiaire' }, { id: 'c4', event_id: 'e4', name: 'Visiteur' }], hosts: [] };

  it('affiche l’historique des participations, la nature des activités et les badges restitués', () => {
    formErrors = {};
    const wrapper = mount(Personne, { props: base });
    expect(wrapper.get('[data-testid=nom-personne]').text()).toBe('Koné Mariam');
    expect(wrapper.get('[data-testid=participation-v1]').text()).toContain('Stage');
    expect(wrapper.get('[data-testid=participation-v2]').text()).toContain('Clôturée');
    expect(wrapper.get('[data-testid=participation-v2]').text()).toContain('Visite terminée');
    expect(wrapper.get('[data-testid=liste-attributions]').text()).toContain('B-007');
    expect(wrapper.get('[data-testid=liste-attributions]').text()).toContain('Restitué');
  });

  it('n’offre la clôture que pour une participation ouverte, jamais pour un titulaire permanent', () => {
    const wrapper = mount(Personne, { props: { ...base, participations: [participation(), participation({ id: 'v2', closed_at: '2027-02-01T10:00:00Z', closure_reason: 'x' }), participation({ id: 'v3', holder_id: 'h1' })] } });
    expect(wrapper.find('[data-testid=cloturer-v1]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=cloturer-v2]').exists()).toBe(false);
    expect(wrapper.find('[data-testid=cloturer-v3]').exists()).toBe(false);
  });

  it('ne propose à l’inscription que les catégories de l’activité choisie, et envoie à la bonne route', async () => {
    post.mockClear();
    const wrapper = mount(Personne, { props: base });
    const options = () => wrapper.findAll('[data-testid=form-inscription] select')[1].findAll('option').map(o => o.text());
    expect(options()).toContain('Stagiaire');
    expect(options()).not.toContain('Visiteur');
    await wrapper.findAll('[data-testid=form-inscription] select')[0].setValue('e4');
    expect(options()).toContain('Visiteur');
    await wrapper.get('[data-testid=form-inscription]').trigger('submit');
    expect(post).toHaveBeenCalledWith('/administration/personnes/p1/participations');
  });

  it('indique clairement l’absence de participation, de badge et d’activité ouverte', () => {
    const wrapper = mount(Personne, { props: { ...base, participations: [], assignments: [], events: [], categories: [] } });
    expect(wrapper.find('[data-testid=aucune-participation]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=aucun-badge]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=aucune-activite]').exists()).toBe(true);
  });
});

describe('lot P2 : réutilisation d’une identité et correction', () => {
  const open = (over: object = {}) => mount(Personnes, { props: { ...base, selected: profile({ holder: null, ...over }) } });
  const lastForm = (mock: { mock: { contexts: unknown[] } }) => mock.mock.contexts.at(-1) as Record<string, unknown>;

  it('ajoute un profil permanent à une personne existante, rattaché à son identité (aucune création de personne)', async () => {
    post.mockReset();
    const wrapper = open();
    await wrapper.get('[data-testid=ajouter-profil-permanent]').trigger('click');
    expect(wrapper.get('[data-testid=form-profil-permanent]').text()).toContain('aucune nouvelle personne');
    await wrapper.get('[data-testid=form-profil-permanent]').trigger('submit');
    expect(post).toHaveBeenCalledWith('/administration/titulaires', expect.objectContaining({ preserveScroll: true }));
    expect(lastForm(post).person_id).toBe('p2');
  });

  it('n’offre pas d’ajouter un second profil permanent à une personne qui en a déjà un', () => {
    const wrapper = mount(Personnes, { props: { ...base, selected: profile() } });
    expect(wrapper.find('[data-testid=ajouter-profil-permanent]').exists()).toBe(false);
    expect(wrapper.find('[data-testid=form-profil-permanent]').exists()).toBe(false);
  });

  it('corrige l’identité par la route de la personne, avec confirmation d’homonyme si le serveur la demande', async () => {
    put.mockReset();
    formErrors = {};
    const wrapper = open({ holder: null });
    await wrapper.get('[data-testid=corriger-identite]').trigger('click');
    expect((wrapper.get('[data-testid=form-correction]').find('input').element as HTMLInputElement).value).toBe('Awa');
    await wrapper.get('[data-testid=form-correction]').trigger('submit');
    expect(put).toHaveBeenCalledWith('/administration/personnes/p2', expect.objectContaining({ preserveScroll: true }));
    expect(wrapper.find('[data-testid=confirmer-homonyme-correction]').exists()).toBe(false);
  });

  it('montre les personnes proches pendant la saisie et permet de les utiliser plutôt que d’en créer une autre', async () => {
    get.mockReset();
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ results: [{ id: 'p9', full_name: 'Awa Fictive', organisation: 'Autre', participations: 1, permanent: false }] }) }));
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(Personnes, { props: base });
    await wrapper.get('[data-testid=ajouter-personne]').trigger('click');
    const inputs = wrapper.get('[data-testid=form-personne]').findAll('input');
    await inputs[0].setValue('Awa');
    await inputs[1].setValue('Fictive');
    await new Promise(resolve => setTimeout(resolve, 450));
    await flushPromises();
    expect(fetchMock).toHaveBeenLastCalledWith(expect.stringContaining('/administration/personnes/recherche?q=Awa%20Fictive'), expect.anything());
    expect(wrapper.get('[data-testid=personnes-proches]').text()).toContain('aucune nouvelle identité');
    await wrapper.get('[data-testid=utiliser-personne-p9]').trigger('click');
    expect(get).toHaveBeenCalledWith('/administration/personnes', expect.objectContaining({ personne: 'p9' }), expect.objectContaining({ only: ['selected', 'flash'] }));
    expect(wrapper.find('[data-testid=form-personne]').exists()).toBe(false);
    vi.unstubAllGlobals();
  });

  it('crée une nouvelle personne permanente en une seule opération vers le registre des titulaires, avec la confirmation d’homonyme', async () => {
    post.mockReset();
    const wrapper = mount(Personnes, { props: base });
    await wrapper.get('[data-testid=ajouter-personne]').trigger('click');
    expect(wrapper.find('[data-testid=profil-nouvelle-personne]').exists()).toBe(false);
    await wrapper.get('[data-testid=creer-permanent] input').setValue(true);
    expect(wrapper.find('[data-testid=profil-nouvelle-personne]').exists()).toBe(true);
    await wrapper.get('[data-testid=form-personne]').trigger('submit');
    expect(post).toHaveBeenCalledWith('/administration/titulaires', expect.anything());
  });
});

describe('lot P3 : opérations d’habilitation dans la fiche', () => {
  const grant = {
    permanent: { holder_id: 'h1', events: [{ id: 'e1', name: 'Stage fictif', starts_at: '2027-01-01T08:00:00Z', ends_at: '2027-02-01T18:00:00Z', zones: [{ id: 'z1', name: 'Accueil' }, { id: 'z2', name: 'Salle' }] }] },
    activites: [{ participation_id: 'v1', event_id: 'e1', event: 'Stage fictif', valid_from: '2027-01-01T08:00:00Z', valid_until: '2027-02-01T18:00:00Z', zones: [{ id: 'z1', name: 'Accueil' }, { id: 'z2', name: 'Salle' }] }], max_exceptional_hours: 12,
  };
  const entitlements = {
    permanentes: [right({ id: 'r0', origin: 'permanente', kind: null, zone: 'Zone permanente', actions: ['retirer'] })],
    activites: [right({ id: 'r1', actions: ['suspendre', 'retirer'] }), right({ id: 'r2', zone: 'Zone suspendue', state: 'suspendue', actions: ['reprendre', 'retirer'] }), right({ id: 'r3', zone: 'Zone retirée', state: 'retiree', actions: [] })],
  };
  const open = (props: object = {}) => mount(Personnes, { props: { ...base, selected: profile({ entitlements, grant }), ...props } });
  const lastPost = () => post.mock.calls.at(-1) as [string, Record<string, unknown>, { onSuccess?: () => void; onError?: (e: Record<string, string>) => void }];
  const tab = async (wrapper: ReturnType<typeof open>) => { await wrapper.get('[data-testid=ptab-habilitations]').trigger('click'); return wrapper; };

  it('n’offre que les opérations permises par l’état et par le moteur d’origine : retrait seul pour une habilitation permanente', async () => {
    const wrapper = await tab(open());
    expect(wrapper.find('[data-testid=action-r0-retirer]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=action-r0-suspendre]').exists()).toBe(false);
    expect(wrapper.find('[data-testid=action-r0-reprendre]').exists()).toBe(false);
    expect(['suspendre', 'retirer'].map(a => wrapper.find(`[data-testid=action-r1-${a}]`).exists())).toEqual([true, true]);
    expect(['reprendre', 'retirer'].map(a => wrapper.find(`[data-testid=action-r2-${a}]`).exists())).toEqual([true, true]);
    expect(wrapper.find('[data-testid=action-r2-suspendre]').exists()).toBe(false);
    expect(wrapper.findAll('[data-testid^="action-r3-"]')).toHaveLength(0);
  });

  it('retire une habilitation permanente par la route du moteur permanent, avec un motif obligatoire', async () => {
    post.mockReset();
    const wrapper = await tab(open());
    await wrapper.get('[data-testid=action-r0-retirer]').trigger('click');
    await wrapper.get('[data-testid=motif-r0]').setValue('Fin de mission');
    await wrapper.get('[data-testid=confirmer-r0]').trigger('submit');
    expect(post).toHaveBeenCalledTimes(1);
    expect(lastPost()[0]).toBe('/administration/habilitations/r0/retirer');
    expect(lastPost()[1]).toEqual({ reason: 'Fin de mission' });
  });

  it('suspend, reprend et retire une habilitation d’activité par les routes du moteur de participation', async () => {
    post.mockReset();
    const wrapper = await tab(open());
    await wrapper.get('[data-testid=action-r1-suspendre]').trigger('click');
    await wrapper.get('[data-testid=motif-r1]').setValue('Contrôle en cours');
    await wrapper.get('[data-testid=confirmer-r1]').trigger('submit');
    expect(lastPost()[0]).toBe('/administration/acces/habilitations/r1/suspendre');
    expect(lastPost()[1]).toEqual({ reason: 'Contrôle en cours' });
    await wrapper.get('[data-testid=action-r2-reprendre]').trigger('click');
    expect(lastPost()[0]).toBe('/administration/acces/habilitations/r2/reprendre');
    expect(lastPost()[1]).toEqual({});
    await wrapper.get('[data-testid=action-r1-retirer]').trigger('click');
    await wrapper.get('[data-testid=motif-r1]').setValue('Fin de session');
    await wrapper.get('[data-testid=confirmer-r1]').trigger('submit');
    expect(lastPost()[0]).toBe('/administration/acces/habilitations/r1/retirer');
  });

  it('affiche le refus explicite du serveur sans masquer la fiche', async () => {
    post.mockReset();
    const wrapper = await tab(open());
    await wrapper.get('[data-testid=action-r2-reprendre]').trigger('click');
    lastPost()[2].onError?.({ id: 'Cette habilitation n’est pas suspendue.' });
    await wrapper.vm.$nextTick();
    expect(wrapper.get('[data-testid=erreurs-habilitation]').text()).toContain('n’est pas suspendue');
  });

  it('accorde une habilitation permanente (moteur permanent) avec les dates de l’activité par défaut', async () => {
    post.mockReset();
    const wrapper = await tab(open());
    await wrapper.get('[data-testid=accorder-permanente]').trigger('click');
    expect((wrapper.get('[data-testid=perm-zone]').element as HTMLSelectElement).value).toBe('z1');
    await wrapper.get('[data-testid=perm-zone]').setValue('z2');
    await wrapper.get('[data-testid=form-accorder-permanente]').trigger('submit');
    const [url, data] = lastPost();
    expect(url).toBe('/administration/habilitations');
    expect(data).toMatchObject({ holder_id: 'h1', event_id: 'e1', zone_id: 'z2' });
    expect(new Date(String(data.valid_from)).toISOString()).toBe('2027-01-01T08:00:00.000Z');
    expect(new Date(String(data.valid_until)).toISOString()).toBe('2027-02-01T18:00:00.000Z');
  });

  it('accorde une habilitation d’activité rattachée à sa participation ; le motif n’est demandé que pour une habilitation exceptionnelle', async () => {
    post.mockReset();
    const wrapper = await tab(open());
    await wrapper.get('[data-testid=accorder-activite]').trigger('click');
    expect(wrapper.find('[data-testid=act-motif]').exists()).toBe(false);
    await wrapper.get('[data-testid=form-accorder-activite]').trigger('submit');
    expect(lastPost()[0]).toBe('/administration/acces/habilitations');
    expect(lastPost()[1]).toMatchObject({ visitor_id: 'v1', zone_id: 'z1', kind: 'standard', reason: null });
    await wrapper.get('[data-testid=act-type]').setValue('exceptionnelle');
    expect(wrapper.find('[data-testid=act-motif]').exists()).toBe(true);
    expect(wrapper.get('[data-testid=form-accorder-activite]').text()).toContain('12 h');
    await wrapper.get('[data-testid=act-motif]').setValue('Visite autorisée');
    await wrapper.get('[data-testid=form-accorder-activite]').trigger('submit');
    expect(lastPost()[1]).toMatchObject({ kind: 'exceptionnelle', reason: 'Visite autorisée' });
  });

  it('n’affiche aucune action sans la permission, ni attribution permanente sans profil permanent actif', async () => {
    const without = await tab(open({ can: { habilitations: false } }));
    expect(without.findAll('[data-testid^="action-"]')).toHaveLength(0);
    expect(without.find('[data-testid=accorder-permanente]').exists()).toBe(false);
    expect(without.find('[data-testid=accorder-activite]').exists()).toBe(false);
    const noProfile = await tab(open({ selected: profile({ holder: null, entitlements, grant: { ...grant, permanent: null } }) }));
    expect(noProfile.find('[data-testid=accorder-permanente]').exists()).toBe(false);
    expect(noProfile.get('[data-testid=sans-profil-habilitation]').text()).toContain('Aucun profil permanent');
    const noParticipation = await tab(open({ selected: profile({ entitlements, grant: { ...grant, activites: [] } }) }));
    expect(noParticipation.find('[data-testid=sans-participation-ouverte]').exists()).toBe(true);
  });
});
