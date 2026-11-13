import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const institution = vi.hoisted(() => ({ value: { custom: true, name: 'AILCT', fullName: 'AILCT fictive' } as Record<string, unknown> }));
vi.mock('@inertiajs/vue3', () => ({
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  usePage: () => ({ props: { institution: institution.value } }),
}));
import TableauSuperviseur, { type Tableau } from '../../resources/js/components/pilotage/TableauSuperviseur.vue';

const tableau: Tableau = {
  postes: [
    { id: 'p1', code: 'P1', name: 'Entrée principale', function_label: 'Contrôle des entrées', state: 'actif', passages: 12 },
    { id: 'p2', code: 'P2', name: 'Parking', function_label: null, state: 'silencieux', passages: 1 },
  ],
  activites: { en_cours: 2, stages: 1, evenements: 1, rows: [{ id: 'a1', name: 'Stage fictif', kind: 'stage', phase: 'en_cours', starts_at: '2027-01-10T08:00:00Z', ends_at: '2027-01-12T17:00:00Z', participants: 1 }] },
};
const site = { presents: { total: 7 }, mouvements: { entrees: 20, sorties: 13, controles: 0, refus: 1 }, postes: { total: 2, actifs: 1, silencieux: 1 } };
const centre = { vehicles_count: 3, visits: { counts: { attendu: 4, en_retard: 2 } }, movements: [{ id: 'm1', occurred_at: '2027-01-10T09:42:18Z', type: 'entree', checkpoint: 'Poste 2', full_name: 'Personne fictive', verdict: null, zone_ok: null }] };
const mountIt = (over: Record<string, unknown> = {}) => mount(TableauSuperviseur, { props: { tableau, site, centre, openAlerts: 0, href: (p: string) => p, canAlerts: true, ...over } as never });

describe('tableau du superviseur', () => {
  it('affiche les chiffres clés, les postes et les activités', () => {
    const w = mountIt();
    expect(w.get('[data-testid=tdb-kpi-presents] [data-testid=valeur]').text()).toBe('7');
    expect(w.get('[data-testid=tdb-kpi-vehicules] [data-testid=valeur]').text()).toBe('3');
    expect(w.get('[data-testid=tdb-kpi-attendus] [data-testid=valeur]').text()).toBe('6');
    expect(w.get('[data-testid=tdb-kpi-attendus] [data-testid=indice]').text()).toBe('2 en retard');
    expect(w.get('[data-testid=tdb-kpi-postes] [data-testid=valeur]').text()).toBe('1');
    expect(w.get('[data-testid=tdb-kpi-activites] [data-testid=valeur]').text()).toBe('2');
    expect(w.get('[data-testid=tdb-mouvements]').text()).toContain('Entrée confirmée');
    expect(w.get('[data-testid=tdb-poste-P1]').text()).toContain('12 passages aujourd’hui');
    expect(w.get('[data-testid=tdb-poste-P2]').attributes('data-state')).toBe('silencieux');
    expect(w.get('[data-testid=tdb-activites-resume]').text()).toBe('2 en cours · 1 stage · 1 événement');
    expect(w.text()).toContain('Stage fictif');
  });
  it('dit en mots si le système est opérationnel', () => {
    expect(mountIt({ site: { ...site, postes: { total: 2, actifs: 2, silencieux: 0 } } }).get('[data-testid=etat-systeme]').text()).toContain('Système opérationnel');
    expect(mountIt({ openAlerts: 2 }).get('[data-testid=etat-systeme]').text()).toContain('Points à surveiller');
  });
  it('fonctionne sans activité choisie', () => {
    const w = mountIt({ centre: null });
    expect(w.get('[data-testid=tdb-kpi-vehicules] [data-testid=valeur]').text()).toBe('0');
    expect(w.get('[data-testid=tdb-mouvements]').text()).toContain('Aucun mouvement récent');
  });
  it('montre la photo du bâtiment seulement pour l’institution personnalisée', () => {
    expect(mountIt().find('[data-testid=tdb-photo] img').attributes('src')).toBe('/brand/ailct-batiment.jpg');
    institution.value = { custom: false, name: 'IKOMA ACCESS', fullName: 'IKOMA ACCESS' };
    expect(mountIt().find('[data-testid=tdb-photo]').exists()).toBe(false);
  });
});
