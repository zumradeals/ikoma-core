import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
  router: { get: vi.fn(), post: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  usePage: () => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['evenements.gerer', 'listes.importer', 'badges.gerer', 'pilotage.consulter', 'journal.consulter'] } } }),
}));
import Tableau from '../../resources/js/pages/Admin/Tableau.vue';

const general = {
  as_of: '2027-01-10T10:00:00Z', perimetre: 'Tout le site', donnees: { sauvegarde: 'ok', actualise: '2027-01-10T10:00:00Z' },
  indicateurs: { personnes: { enregistrees: 40 }, titulaires: { actifs: 3 }, habilitations: { actives: 5, a_echeance: 0, jours: 30 }, activites: { en_cours: 1, a_venir: 0, participants_actifs: 20 }, supports: { cartes_remises: 1, badges_associes: 0 }, presents: { total: 1, permanents: 0, activites: 1 }, alertes: { a_traiter: 0, litiges: 0 } },
  activites: [], personnes: { enregistrees: 40, actives: 40, a_echeance: 0, jours: 30, retirees_ou_expirees: 0, verifications: { homonymes: 0, titulaires_sans_categorie: 0, titulaires_sans_photo: 0 } },
  badges: { cartes: { actives: 1, remises: 1, non_remises: 0, imprimees_non_remises: 0, retirees: 0 }, temporaires: { associes: 0, non_restitues: 0 }, impression: { emis_non_imprimes: 0 } },
  operations: { mouvements: { entrees: 0, sorties: 0, controles: 0, refus: 0 }, presents: { total: 1, permanents: 0, activites: 1, multi_contextes: 0 }, postes: { total: 5, actifs: 5, appareils_enroles: 1, appareils_synchronises: 1, appareils_silencieux: 0 },
    visites_annoncees: { total: 0, en_retard: 0 }, par_heure: [], vehicules_presents: 0, derniere_synchro: null, derniers: [] },
  priorites: [],
};

describe('Tableau de bord de l’administrateur', () => {
  it('affiche uniquement la vue d’ensemble : plus d’ancienne préparation pas à pas en dessous', () => {
    const w = mount(Tableau, { props: { general, event: { id: 'e1', name: 'Activité fictive' }, events: [{ id: 'e1', name: 'Activité fictive' }] } as never });
    expect(w.find('[data-testid=tableau-general]').exists()).toBe(true);
    expect(w.get('[data-testid=titre-general]').text()).toContain('Tableau de bord');
    for (const old of ['titre-etat', 'avancement', 'prochaine-etape', 'tableau-admin', 'aucun-evenement']) expect(w.find(`[data-testid=${old}]`).exists()).toBe(false);
    expect(w.text()).not.toMatch(/Préparer l’événement|Votre événement/);
  });

  it('sans indicateurs disponibles, renvoie vers la gestion des activités', () => {
    const w = mount(Tableau, { props: { general: null, event: null, events: [] } as never });
    expect(w.get('[data-testid=aucun-evenement]').text()).toContain('pas disponibles');
    expect(w.get('[data-testid=creer-premier-evenement]').attributes('href')).toBe('/administration/evenements');
  });
});
