import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['evenements.gerer', 'listes.importer', 'badges.gerer', 'pilotage.consulter', 'journal.consulter'] as string[] } } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, router: { get: vi.fn() }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import AdminGeneral from '../../resources/js/components/admin/AdminGeneral.vue';

const general = {
  as_of: '2027-01-10T10:00:00Z', perimetre: 'Tout le site (toutes activités et contexte permanent)', donnees: { sauvegarde: 'ancienne', actualise: '2027-01-10T10:00:00Z' },
  indicateurs: { personnes: { enregistrees: 1254 }, habilitations: { actives: 320, a_echeance: 12, jours: 30 }, activites: { en_cours: 3, a_venir: 2, participants_actifs: 142 }, supports: { cartes_remises: 300, badges_associes: 38 },
    presents: { total: 312, permanents: 41, activites: 271 }, alertes: { a_traiter: 3, litiges: 1 } },
  activites: [{ id: 'e1', name: 'Stage de sécurité', kind: 'stage', site: 'Salle 1', starts_at: '2027-01-01T08:00:00Z', ends_at: '2027-03-01T18:00:00Z', phase: 'en_cours', participants: 40, attendus: 40, arrivees: 38, presents: 36 }],
  personnes: { enregistrees: 1254, actives: 1240, a_echeance: 12, jours: 30, retirees_ou_expirees: 9, verifications: { homonymes: 2, titulaires_sans_categorie: 1, titulaires_sans_photo: 3 } },
  badges: { cartes: { actives: 312, remises: 300, non_remises: 12, imprimees_non_remises: 4, retirees: 6 }, temporaires: { associes: 38, non_restitues: 2 }, impression: { emis_non_imprimes: 5 } },
  operations: { mouvements: { entrees: 624, sorties: 607, controles: 44, refus: 12 }, presents: { total: 312, permanents: 41, activites: 271, multi_contextes: 3 }, postes: { total: 8, actifs: 8, appareils_enroles: 7, appareils_synchronises: 6, appareils_silencieux: 1 },
    visites_annoncees: { total: 4, en_retard: 1 }, derniers: [{ at: '2027-01-10T09:55:00Z', type: 'entree', checkpoint: 'accueil', context: 'Contexte permanent', name: 'Aminata Fictive' }] },
  priorites: [
    { key: 'litiges', severity: 'haute', title: 'Cas à décider (accès refusés)', text: 'La décision appartient au superviseur.', count: 1, status: 'En attente de décision', href: null },
    { key: 'badges_non_restitues', severity: 'normale', title: 'Badges temporaires non restitués', text: 'Une sortie ne vaut jamais restitution.', count: 2, status: 'À récupérer', href: '/administration/badges/registre' },
  ],
};
const mountGeneral = (extra: object = {}) => mount(AdminGeneral, { props: { general: { ...general, ...extra } as never } });

describe('Tableau de bord général de l’administrateur', () => {
  beforeEach(() => { page.props.auth.permissions = ['evenements.gerer', 'listes.importer', 'badges.gerer', 'pilotage.consulter', 'journal.consulter']; });

  it('précise le périmètre et la fraîcheur, et signale une sauvegarde ancienne', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=titre-general]').text()).toBe('Tableau de bord');
    expect(w.get('[data-testid=perimetre]').text()).toContain('Tout le site');
    expect(w.get('[data-testid=fraicheur]').text()).toContain('Sauvegarde ancienne');
  });

  it('présente les six indicateurs sans les confondre', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=ind-personnes]').text()).toBe('1254');
    expect(w.get('[data-testid=ind-habilitations]').text()).toBe('320');
    expect(w.get('[data-testid=indicateurs-globaux]').text()).toContain('dont 12 à échéance (30 j)');
    expect(w.get('[data-testid=ind-supports]').text()).toBe('338');
    expect(w.get('[data-testid=indicateurs-globaux]').text()).toContain('300 cartes remises · 38 badges associés');
    expect(w.get('[data-testid=ind-presents]').text()).toBe('312');
    expect(w.get('[data-testid=ind-alertes]').text()).toBe('4');
  });

  it('distingue personne enregistrée et habilitation, et support émis, imprimé, remis, actif', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=bloc-personnes]').text()).toContain('n’a pas pour autant d’habilitation active');
    expect(w.get('[data-testid=cartes-emises]').text()).toBe('312');
    expect(w.get('[data-testid=cartes-remises]').text()).toBe('300');
    expect(w.get('[data-testid=cartes-imprimees-non-remises]').text()).toBe('4');
    expect(w.get('[data-testid=bloc-badges]').text()).toContain('n’est pas imprimé, remis ni actif');
    expect(w.get('[data-testid=emis-non-imprimes]').text()).toContain('5');
    expect(w.get('[data-testid=badges-non-restitues]').text()).toBe('2');
  });

  it('donne à chaque priorité son statut, un lien vers la fonction d’origine ou la mention d’un autre rôle', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=priorite-litiges]').text()).toContain('En attente de décision');
    expect(w.find('[data-testid=voir-litiges]').exists()).toBe(false);
    expect(w.get('[data-testid=sans-lien-litiges]').text()).toContain('autre rôle');
    expect(w.get('[data-testid=voir-badges_non_restitues]').attributes('href')).toBe('/administration/badges/registre');
    expect(mountGeneral({ priorites: [] }).find('[data-testid=aucune-priorite]').exists()).toBe(true);
  });

  it('affiche les activités avec effectifs, attendus et arrivées confirmées, et un état vide explicite', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=effectifs-e1]').text()).toBe('40 participants · attendus 40 · arrivées confirmées 38 · présents 36');
    expect(w.get('[data-testid=activite-e1] a').attributes('href')).toBe('/administration/evenements?activite=e1');
    expect(w.get('[data-testid=vers-registre-activites]').attributes('href')).toBe('/administration/evenements');
    expect(mountGeneral({ activites: [] }).find('[data-testid=aucune-activite-ouverte]').exists()).toBe(true);
  });

  it('résume la situation du site sans dupliquer la supervision, avec renvoi vers Opérations', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=site-synthese]').text()).toContain('312 personnes présentes (identités distinctes)');
    expect(w.get('[data-testid=site-postes]').text()).toContain('Postes administrativement actifs : 8 sur 8');
    expect(w.get('[data-testid=site-postes]').text()).toContain('appareils synchronisés récemment : 6');
    expect(w.get('[data-testid=site-visites]').text()).toContain('4');
    expect(w.get('[data-testid=derniers-mouvements]').text()).toContain('Aminata Fictive');
    expect(w.get('[data-testid=vers-operations]').attributes('href')).toBe('/administration/operations');
    expect(w.text()).not.toMatch(/clôturer|autoriser un accès|valider/i);
    expect(mountGeneral({ operations: { ...general.operations, derniers: [] } }).find('[data-testid=aucun-mouvement]').exists()).toBe(true);
  });

  it('ne propose que les accès rapides permis au compte', () => {
    expect(mountGeneral().findAll('[data-testid^=rapide-]').map(a => a.attributes('data-testid'))).toEqual(['rapide-personne', 'rapide-habilitations', 'rapide-activite', 'rapide-badges', 'rapide-operations', 'rapide-journal']);
    page.props.auth.permissions = ['evenements.gerer'];
    expect(mountGeneral().findAll('[data-testid^=rapide-]').map(a => a.attributes('data-testid'))).toEqual(['rapide-activite']);
    expect(mountGeneral().find('[data-testid=vers-operations]').exists()).toBe(false);
  });
});
