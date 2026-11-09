import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
let formErrors: Record<string, string> = {};
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['listes.importer'] } } }),
  router: { get, post },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => { const form: Record<string, unknown> = reactive({ ...data, errors: formErrors, processing: false, post, reset: vi.fn() }); form.transform = () => form; return form; },
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
    expect(wrapper.get('[data-testid=fiches-invites]').attributes('href')).toBe('/administration/invites');
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
