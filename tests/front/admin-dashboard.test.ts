import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
  router: { get: vi.fn(), post: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  usePage: () => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['evenements.gerer', 'zones.gerer', 'badges.gerer', 'journal.consulter'] } } }),
}));
import Tableau from '../../resources/js/pages/Admin/Tableau.vue';

const steps = [
  { key: 'zones', title: 'Zones et catégories', status: 'fait', detail: '6 zones · 4 catégories', href: '/administration/zones' },
  { key: 'liste', title: 'Liste d’invités', status: 'fait', detail: '300 invités dans la liste', href: '/administration/imports' },
  { key: 'badges', title: 'Badges', status: 'en_cours', detail: '400 fabriqués · 1 car n’a pas encore ses badges', href: '/administration/badges' },
  { key: 'tablettes', title: 'Tablettes', status: 'a_faire', detail: '0 branchée sur 1', href: '/administration/postes' },
] satisfies Array<{ key: string; title: string; status: "fait" | "en_cours" | "a_faire"; detail: string; href: string }>;
const base = {
  event: { id: 'e1', name: 'Événement fictif' }, events: [{ id: 'e1', name: 'Événement fictif' }], steps,
  summary: { done: 2, total: 4, next: { title: 'Badges', href: '/administration/badges', text: 'Réserver les badges' } },
  tiles: { invites: { value: 300, caption: 'dont 40 stagiaires en car' }, badges: { value: 340, total: 400, caption: '60 réservés à un poste · 0 à un car' }, tablettes: { value: 0, total: 1, caption: '1 en attente d’enrôlement' }, comptes: { value: 5, caption: 'tous les rôles sont couverts' } },
  watch: [{ severity: 'haute' as const, title: 'Une tablette est silencieuse', text: '« Poste cars » n’a pas synchronisé depuis 2 h.', href: '/administration/postes' }],
  recent: [{ action: 'groupe.cree', occurred_at: '2027-01-10T09:14:00Z' }, { action: 'action.inconnue', occurred_at: '2027-01-10T09:20:00Z' }],
};

describe('tableau de bord de l’administrateur', () => {
  it('dit où en est la préparation et propose la prochaine action', () => {
    const w = mount(Tableau, { props: base });
    expect(w.get('[data-testid=titre-etat]').text()).toBe('Votre événement est presque prêt');
    expect(w.get('[data-testid=avancement]').text()).toContain('2 étapes sur 4 sont terminées');
    expect(w.get('[data-testid=avancement]').text()).toContain('Prochaine étape : badges');
    expect(w.get('[role=progressbar]').attributes('aria-valuenow')).toBe('2');
    expect(w.get('[data-testid=prochaine-etape]').attributes('href')).toBe('/administration/badges');
    expect(w.get('[data-testid=decompte]').text()).toBe('2 faites · 1 en cours · 1 à faire');
  });

  it('double chaque statut d’une icône et d’un mot, jamais la couleur seule', () => {
    const w = mount(Tableau, { props: base });
    expect(w.get('[data-testid=statut-zones]').text()).toBe('✓ Fait');
    expect(w.get('[data-testid=statut-badges]').text()).toBe('● En cours');
    expect(w.get('[data-testid=statut-tablettes]').text()).toBe('○ À faire');
    expect(w.get('[data-testid=etape-badges]').attributes('data-status')).toBe('en_cours');
  });

  it('affiche les chiffres, les points à surveiller et les dernières actions en français', () => {
    const w = mount(Tableau, { props: base });
    expect(w.get('[data-testid=tuile-badges] [data-testid=valeur]').text()).toContain('340');
    expect(w.get('[data-testid=tuile-badges] [data-testid=valeur]').text()).toContain('sur 400');
    expect(w.get('[data-testid=a-surveiller]').text()).toContain('Important : Une tablette est silencieuse');
    expect(w.get('[data-testid=dernieres-actions]').text()).toContain('Groupe créé');
    expect(w.get('[data-testid=dernieres-actions]').text()).toContain('action.inconnue'); // un libellé inconnu reste lisible
  });

  it('félicite quand tout est prêt et rassure quand rien n’est à surveiller', () => {
    const w = mount(Tableau, { props: { ...base, steps: steps.map(s => ({ ...s, status: 'fait' as const })), summary: { done: 4, total: 4, next: null }, watch: [] } });
    expect(w.get('[data-testid=titre-etat]').text()).toBe('Votre événement est prêt');
    expect(w.find('[data-testid=prochaine-etape]').exists()).toBe(false);
    expect(w.get('[data-testid=a-surveiller]').text()).toContain('Rien à signaler pour le moment.');
  });

  it('explique quoi faire quand aucun événement n’existe', () => {
    const w = mount(Tableau, { props: { ...base, event: null, events: [], steps: [], tiles: null, watch: [], summary: { done: 0, total: 0, next: null }, recent: [] } });
    expect(w.get('[data-testid=aucun-evenement]').text()).toContain('Aucun événement');
    expect(w.get('[data-testid=creer-premier-evenement]').attributes('href')).toBe('/administration/evenements');
  });
});
