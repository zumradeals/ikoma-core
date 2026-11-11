import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, get, put } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn(), put: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['evenements.gerer', 'listes.importer', 'journal.consulter', 'groupes.gerer', 'badges.gerer'] } } }),
  router: { get, post },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post: vi.fn(), put, reset: vi.fn() }),
}));

import Evenements from '../../resources/js/pages/Admin/Evenements.vue';

const events = [
  { id: 'e1', name: 'Stage de sécurité opérationnelle', site: 'Salle 1', kind: 'stage', starts_at: '2027-01-01T08:00:00Z', ends_at: '2027-03-01T18:00:00Z', status: null, phase: 'en_cours', participants: 3, participants_actifs: 2 },
  { id: 'e2', name: 'Séminaire régional', site: null, kind: 'evenement', starts_at: '2027-06-01T08:00:00Z', ends_at: '2027-06-02T18:00:00Z', status: null, phase: 'a_venir', participants: 0, participants_actifs: 0 },
  { id: 'e3', name: 'Stage clos fictif', site: null, kind: 'stage', starts_at: '2026-01-01T08:00:00Z', ends_at: '2026-02-01T18:00:00Z', status: 'clos', phase: 'cloturee', participants: 5, participants_actifs: 0 },
];
const kpis = { en_cours: 1, participants_actifs: 2, a_venir: 1, cloturees: 1 };
const profile = (closed = false) => ({
  event: { id: 'e1', name: 'Stage de sécurité opérationnelle', site: 'Salle 1', kind: 'stage', starts_at: '2027-01-01T08:00:00Z', ends_at: '2027-03-01T18:00:00Z', status: closed ? 'clos' : null, closed, anonymized: false },
  counts: { participants: 3, actifs: 2, presents: 1, clotures: 1, groupes: 0, vehicules: 0, badges_attribues: 1, badges_restitues: 0, habilitations: 2 },
  categories: [{ id: 'c1', name: 'Stagiaire', participants: 3 }], groups: [], zones: [{ id: 'z1', name: 'Salle fictive', habilitations: 2 }], journal: [{ id: 1, action: 'participation.creee', at: '2027-01-02T08:00:00Z' }],
  enrol: closed ? null : { categories: [{ id: 'c1', name: 'Stagiaire' }], hosts: [] },
});
const participants = {
  data: [
    { id: 'p1', person_id: 'x1', full_name: 'Aminata Fictive', organisation: null, category: 'Stagiaire', group: null, status: 'present', habilitations: 1, badge: 'B-001', can_close: true },
    { id: 'p2', person_id: 'x2', full_name: 'Ibrahim Fictif', organisation: 'Org', category: 'Stagiaire', group: null, status: 'cloturee', habilitations: 0, badge: null, can_close: false },
  ],
  meta: { current_page: 1, last_page: 1, total: 2 },
};
const can = { participants: true, journal: true, groupes: true, badges: true, site: true, zones: true };
const mountPage = (extra: object = {}) => mount(Evenements, { props: { events, kpis, selected: null, participants: null, can, ...extra } });

describe('Activités : registre', () => {
  beforeEach(() => { post.mockClear(); get.mockClear(); });

  it('affiche les indicateurs calculés et le registre avec nature, effectifs et état', () => {
    const w = mountPage();
    expect(w.get('[data-testid=kpi-en-cours]').text()).toBe('1');
    expect(w.get('[data-testid=kpi-participants]').text()).toBe('2');
    expect(w.get('[data-testid=kpi-a-venir]').text()).toBe('1');
    expect(w.get('[data-testid=kpi-cloturees]').text()).toBe('1');
    expect(w.get('[data-testid=effectif-e1]').text()).toContain('2 actifs');
    expect(w.get('[data-testid=etat-e1]').text()).toBe('En cours');
    expect(w.get('[data-testid=etat-e3]').text()).toBe('Clôturée');
    expect(w.find('[data-testid=fiche-activite-vide]').exists()).toBe(true);
  });

  it('n’affiche aucun chiffre sans données : pas d’indicateurs fictifs quand ils sont absents', () => {
    const w = mount(Evenements, { props: { events: [] } });
    expect(w.find('[data-testid=indicateurs-activites]').exists()).toBe(false);
    expect(w.find('[data-testid=liste-evenements-vide]').exists()).toBe(true);
  });

  it('filtre par recherche, nature et état', async () => {
    const w = mountPage();
    await w.get('[data-testid=recherche-activite]').setValue('séminaire');
    expect(w.findAll('[data-testid^=activite-]').map(r => r.attributes('data-testid'))).toEqual(['activite-e2']);
    await w.get('[data-testid=recherche-activite]').setValue('');
    await w.get('[data-testid=filtre-nature]').setValue('stage');
    expect(w.findAll('[data-testid^=activite-]')).toHaveLength(2);
    await w.get('[data-testid=filtre-etat]').setValue('cloturee');
    expect(w.findAll('[data-testid^=activite-]').map(r => r.attributes('data-testid'))).toEqual(['activite-e3']);
    await w.get('[data-testid=filtre-etat]').setValue('a_venir');
    expect(w.find('[data-testid=aucune-activite]').exists()).toBe(true);
  });

  it('ouvre la fiche par rechargement partiel, sans quitter la page', async () => {
    const w = mountPage();
    await w.get('[data-testid=selectionner-activite-e1]').trigger('click');
    expect(get).toHaveBeenCalledWith('/administration/evenements', { activite: 'e1' }, expect.objectContaining({ only: expect.arrayContaining(['selected', 'participants']), preserveState: true, replace: true }));
  });

  it('ouvre le formulaire de création sans le retirer du DOM (route existante)', async () => {
    const w = mountPage();
    await w.get('[data-testid=nouvelle-activite]').trigger('click');
    expect(w.get('[data-testid=nouvelle-activite]').attributes('aria-expanded')).toBe('true');
    expect(w.find('[data-testid=form-creer-evenement]').exists()).toBe(true);
  });
});

describe('Activités : fiche', () => {
  beforeEach(() => { post.mockClear(); get.mockClear(); vi.unstubAllGlobals(); });

  it('présente la vue d’ensemble puis les participants avec leur statut, habilitations et badge', async () => {
    const w = mountPage({ selected: profile(), participants });
    expect(w.get('[data-testid=fiche-titre]').text()).toContain('Stage de sécurité');
    expect(w.get('[data-testid=eff-participants]').text()).toContain('3 inscrits');
    expect(w.find('[data-testid=onglet-groupes]').exists()).toBe(false); // aucun groupe ni véhicule : pas d'onglet artificiel
    await w.get('[data-testid=onglet-participants]').trigger('click');
    expect(w.get('[data-testid=statut-p1]').text()).toBe('Présente');
    expect(w.get('[data-testid=statut-p2]').text()).toBe('Clôturée');
    expect(w.get('[data-testid=habilitations-p1]').text()).toContain('1 habilitation d’activité effective');
    expect(w.get('[data-testid=badge-p1]').text()).toContain('B-001');
    expect(w.get('[data-testid=badge-p2]').text()).toContain('Aucun badge');
    expect(w.get('[data-testid=fiche-personne-p1]').attributes('href')).toBe('/administration/personnes?personne=x1');
  });

  it('ne propose la clôture qu’aux participations clôturables et exige un motif', async () => {
    const w = mountPage({ selected: profile(), participants });
    await w.get('[data-testid=onglet-participants]').trigger('click');
    expect(w.find('[data-testid=cloturer-participation-p2]').exists()).toBe(false);
    await w.get('[data-testid=cloturer-participation-p1]').trigger('click');
    expect(w.get('[data-testid=confirmer-cloture-p1]').attributes('disabled')).toBeDefined();
    await w.get('[data-testid=motif-cloture-p1]').setValue('Fin du stage');
    expect(w.get('[data-testid=confirmer-cloture-p1]').attributes('disabled')).toBeUndefined();
    await w.get('[data-testid=confirmer-cloture-p1]').trigger('submit');
    expect(post).toHaveBeenCalledWith('/administration/participations/p1/cloturer', { reason: 'Fin du stage', retour: 'activites' }, expect.any(Object));
  });

  it('inscrit une personne EXISTANTE : recherche, choix, catégorie, puis la route d’inscription existante', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ results: [{ id: 'pers1', full_name: 'Fatou Fictive', organisation: null, status: 'actif', participations: 1, permanent: false }] }) })));
    const w = mountPage({ selected: profile(), participants });
    await w.get('[data-testid=onglet-participants]').trigger('click');
    await w.get('[data-testid=ajouter-participant]').trigger('click');
    expect(w.find('[data-testid=choix-categorie]').exists()).toBe(false);
    await w.get('[data-testid=recherche-personne-inscription]').setValue('Fatou');
    await w.get('[data-testid=lancer-recherche-personne]').trigger('click');
    await w.get('form[aria-label="Rechercher une personne à inscrire"]').trigger('submit');
    await flushPromises();
    await w.get('[data-testid=choisir-personne-pers1]').trigger('click');
    await w.get('[data-testid=choix-categorie]').trigger('submit');
    expect(post).toHaveBeenCalledWith('/administration/personnes/pers1/participations', { event_id: 'e1', category_id: 'c1', host_id: null, retour: 'activites' }, expect.any(Object));
    expect(w.get('[data-testid=creer-personne]').attributes('href')).toBe('/administration/personnes');
  });

  it('ferme les inscriptions et la clôture d’une activité clôturée', async () => {
    const w = mountPage({ selected: profile(true), participants });
    await w.get('[data-testid=onglet-participants]').trigger('click');
    expect(w.find('[data-testid=ajouter-participant]').exists()).toBe(false);
    expect(w.find('[data-testid=inscription-fermee]').exists()).toBe(true);
    expect(w.find('[data-testid=cloturer-participation-p1]').exists()).toBe(false);
    await w.get('[data-testid=onglet-historique]').trigger('click');
    expect(w.find('[data-testid=cloturer-activite]').exists()).toBe(false);
    expect(w.find('[data-testid=archiver-activite]').exists()).toBe(true);
  });

  it('sépare les habilitations d’activité du permanent et renvoie vers les écrans propriétaires des badges et des accès', async () => {
    const w = mountPage({ selected: profile(), participants });
    await w.get('[data-testid=onglet-acces]').trigger('click');
    expect(w.get('[data-testid=panneau-acces]').text()).toContain('habilitations permanentes appartiennent au profil permanent');
    expect(w.get('[data-testid=eff-habilitations]').text()).toBe('2');
    expect(w.get('[data-testid=vers-badges]').attributes('href')).toBe('/administration/badges');
    expect(w.get('[data-testid=vers-acces]').attributes('href')).toBe('/administration/acces');
  });

  it('masque les sections interdites : sans droit de liste, ni onglet participants ni journal', async () => {
    const w = mountPage({ selected: profile(), participants: null, can: { participants: false, journal: false, groupes: false, badges: false, site: false, zones: false } });
    expect(w.find('[data-testid=onglet-participants]').exists()).toBe(false);
    expect(w.find('[data-testid=importer-participants]').exists()).toBe(false);
    await w.get('[data-testid=onglet-historique]').trigger('click');
    expect(w.find('[data-testid=journal-activite]').exists()).toBe(false);
  });
});

describe('Activités : préparer les badges', () => {
  it('propose le parcours unique avec la sélection, seulement avec les droits et pour une activité ouverte', async () => {
    const w = mountPage({ selected: profile(), participants });
    await w.get('[data-testid=onglet-participants]').trigger('click');
    expect(w.get('[data-testid=preparer-badges]').attributes('href')).toBe('/administration/badges?activite=e1');
    await w.get('[data-testid=choisir-badge-p1]').setValue(true);
    expect(w.get('[data-testid=nb-choisis]').text()).toBe('1');
    expect(w.get('[data-testid=preparer-badges]').attributes('href')).toBe('/administration/badges?activite=e1&ids=p1');
    expect(w.find('[data-testid=choisir-badge-p2]').exists()).toBe(false); // participation clôturée : non sélectionnable
    await w.get('[data-testid=choisir-tous]').trigger('click');
    expect(w.get('[data-testid=nb-choisis]').text()).toBe('1');
    const closed = mountPage({ selected: profile(true), participants });
    await closed.get('[data-testid=onglet-participants]').trigger('click');
    expect(closed.find('[data-testid=preparer-badges]').exists()).toBe(false);
    const noRight = mountPage({ selected: profile(), participants, can: { ...can, badges: false } });
    await noRight.get('[data-testid=onglet-participants]').trigger('click');
    expect(noRight.find('[data-testid=preparer-badges]').exists()).toBe(false);
  });
});
