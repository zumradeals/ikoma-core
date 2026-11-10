import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['evenements.gerer', 'listes.importer', 'badges.gerer', 'pilotage.consulter', 'journal.consulter'] as string[] } } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, router: { get: vi.fn() }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import AdminGeneral from '../../resources/js/components/admin/AdminGeneral.vue';

const general = {
  as_of: '2027-01-10T10:00:00Z', perimetre: 'Tout le site (toutes activités et contexte permanent)', donnees: { sauvegarde: 'ancienne', actualise: '2027-01-10T10:00:00Z' },
  indicateurs: { personnes: { enregistrees: 1254 }, titulaires: { actifs: 312 }, habilitations: { actives: 320, a_echeance: 12, jours: 30 }, activites: { en_cours: 3, a_venir: 2, participants_actifs: 142 }, supports: { cartes_remises: 300, badges_associes: 38 },
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
const hours = Array.from({ length: 24 }, (_, n) => ({ heure: String(n).padStart(2, '0'), entrees: n === 12 ? 170 : n, sorties: n === 12 ? 150 : 1 }));
const mountGeneral = (extra: object = {}) => mount(AdminGeneral, { props: { general: { ...general, operations: { ...general.operations, par_heure: hours, vehicules_presents: 2, derniere_synchro: '2027-01-10T09:41:00Z' }, ...extra } as never } });

describe('Tableau de bord de l’administrateur : vue d’ensemble', () => {
  beforeEach(() => { page.props.auth.permissions = ['evenements.gerer', 'listes.importer', 'badges.gerer', 'pilotage.consulter', 'journal.consulter', 'alertes.consulter']; });

  it('présente le titre, la date du jour et le périmètre « Tout le site »', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=titre-general]').text()).toBe('Tableau de bord – Administrateur');
    expect(w.get('[data-testid=perimetre]').text()).toContain('Tout le site');
    expect(w.get('[data-testid=date-jour]').text()).toContain('Aujourd’hui');
  });

  it('affiche les sept indicateurs, chacun avec son périmètre (24 h pour les mouvements)', () => {
    const w = mountGeneral();
    expect(w.findAll('[data-testid^=ind-]').map(e => e.attributes('data-testid'))).toEqual(['ind-personnes', 'ind-titulaires', 'ind-activites', 'ind-entrees', 'ind-sorties', 'ind-refus', 'ind-alertes']);
    expect(w.get('[data-testid=ind-personnes]').text()).toContain('1');
    expect(w.get('[data-testid=ind-titulaires]').text()).toBe('312');
    expect(w.get('[data-testid=ind-entrees]').text()).toBe('624');
    expect(w.get('[data-testid=ind-refus]').text()).toBe('12');
    expect(w.get('[data-testid=indicateurs-globaux]').text()).toMatch(/Entrées \(24 h\)/);
  });

  it('trace le graphique des accès sur 24 tranches horaires avec les totaux réels en légende', () => {
    const w = mountGeneral();
    expect(w.findAll('[data-testid=graphique-acces] rect')).toHaveLength(48);
    const total = hours.reduce((s, h) => s + h.entrees, 0);
    expect(w.get('[data-testid=legende-acces]').text()).toContain(`Entrées (${total})`);
    expect(mountGeneral({ operations: { ...general.operations, par_heure: [] } }).findAll('[data-testid=graphique-acces] rect')).toHaveLength(0);
  });

  it('liste les mouvements récents avec le résultat, sans photo, et un état vide explicite', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=derniers-mouvements]').text()).toContain('Aminata Fictive');
    expect(w.get('[data-testid=derniers-mouvements]').text()).toContain('Autorisé');
    expect(w.find('[data-testid=derniers-mouvements] img').exists()).toBe(false);
    expect(w.get('[data-testid=vers-operations]').attributes('href')).toBe('/administration/operations');
    const refus = mountGeneral({ operations: { ...general.operations, par_heure: hours, derniers: [{ at: '2027-01-10T09:00:00Z', type: 'refus', checkpoint: null, context: 'Stage', name: 'Ibrahim Fictif', resultat: 'refuse' }] } });
    expect(refus.get('[data-testid=derniers-mouvements]').text()).toContain('Refusé');
    expect(mountGeneral({ operations: { ...general.operations, par_heure: hours, derniers: [] } }).find('[data-testid=aucun-mouvement]').exists()).toBe(true);
  });

  it('résume l’état du site sans prétendre à une connexion : postes actifs, appareils, dernière synchronisation', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=site-postes]').text()).toContain('Tous actifs');
    expect(w.get('[data-testid=derniere-synchro]').text()).toContain('Dernière');
    expect(w.get('[data-testid=site-synthese]').text()).toContain('identités distinctes');
    expect(w.text()).not.toMatch(/En ligne/);
  });

  it('montre activités, visites et véhicules, badges et cartes avec les distinctions émis, imprimé, remis, actif', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=effectifs-e1]').text()).toContain('Participants : 40');
    expect(w.get('[data-testid=activite-e1]').text()).toContain('95 %');
    expect(w.get('[data-testid=vehicules-presents]').text()).toBe('2');
    expect(w.get('[data-testid=visites-retard]').text()).toBe('1');
    expect(w.get('[data-testid=cartes-remises]').text()).toBe('300');
    expect(w.get('[data-testid=cartes-non-remises]').text()).toBe('12');
    expect(w.get('[data-testid=badges-non-restitues]').text()).toBe('2');
    expect(w.get('[data-testid=emis-non-imprimes]').text()).toContain('n’est pas imprimé, remis ni actif');
    expect(mountGeneral({ activites: [] }).find('[data-testid=aucune-activite-ouverte]').exists()).toBe(true);
  });

  it('donne à chaque alerte son statut, un niveau, un lien vers la fonction d’origine ou « autre rôle »', () => {
    const w = mountGeneral();
    expect(w.get('[data-testid=priorite-litiges]').text()).toContain('Statut : En attente de décision');
    expect(w.get('[data-testid=priorite-litiges]').text()).toContain('Haute');
    expect(w.find('[data-testid=voir-litiges]').exists()).toBe(false);
    expect(w.get('[data-testid=sans-lien-litiges]').text()).toContain('autre rôle');
    expect(w.get('[data-testid=priorite-badges_non_restitues]').text()).toContain('Moyenne');
    expect(w.get('[data-testid=voir-badges_non_restitues]').attributes('href')).toBe('/administration/badges/registre');
    expect(w.get('[data-testid=vers-alertes]').attributes('href')).toBe('/pilotage/alertes');
    expect(mountGeneral({ priorites: [] }).find('[data-testid=aucune-priorite]').exists()).toBe(true);
  });

  it('ne propose que les accès rapides permis au compte', () => {
    expect(mountGeneral().findAll('[data-testid^=rapide-]').map(a => a.attributes('data-testid'))).toEqual(['rapide-personne', 'rapide-habilitations', 'rapide-activite', 'rapide-badges', 'rapide-operations', 'rapide-journal']);
    expect(mountGeneral().get('[data-testid=rapide-badges]').attributes('href')).toBe('/administration/badges');
    page.props.auth.permissions = ['evenements.gerer'];
    expect(mountGeneral().findAll('[data-testid^=rapide-]').map(a => a.attributes('data-testid'))).toEqual(['rapide-activite']);
    expect(mountGeneral().find('[data-testid=vers-operations]').exists()).toBe(false);
  });
});
