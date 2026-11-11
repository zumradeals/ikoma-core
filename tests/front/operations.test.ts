import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { get, reload } = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['pilotage.consulter', 'presences.consulter', 'journal.consulter', 'postes.gerer'] } } }),
  router: { get, reload },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import Operations from '../../resources/js/pages/Admin/Operations.vue';

const general = {
  as_of: '2027-01-10T10:00:00Z', mouvements: { entrees: 12, sorties: 5, controles: 3, refus: 2 }, presents: { total: 9, permanents: 4, activites: 5, multi_contextes: 2, contextes: [{ event_id: 'p0', name: 'Permanent', is_permanent: true, presents: 6 }, { event_id: 'e1', name: 'Stage fictif', is_permanent: false, presents: 5 }] }, alertes: { a_traiter: 1, critiques: 0 }, litiges: 2,
  postes: { total: 8, actifs: 7, appareils_enroles: 7, appareils_synchronises: 6, appareils_silencieux: 1 }, par_heure: Array.from({ length: 24 }, (_, i) => ({ heure: String(i).padStart(2, '0'), entrees: i % 5, sorties: i % 3 })),
};
const centre = {
  now: '2027-01-10T10:00:00Z', postes: [{ id: 'd1', name: 'Accueil (Poste 1)', point_code: 'accueil', state: 'actif', last_synced_at: '2027-01-10T09:58:00Z', last_operation: null }, { id: 'd2', name: 'Soute', point_code: 'soute', state: 'silencieux', last_synced_at: null, last_operation: null }],
  operations: [], presents: { count: 3, rows: [] }, movements: [{ id: 'm1', occurred_at: '2027-01-10T09:55:00Z', type: 'entree', checkpoint: 'accueil', visitor_id: 'v1', full_name: 'Aminata Fictive', badge_code: 'B-001', verdict: null, zone_ok: null, direction: null }],
  visits: { counts: {}, late_after_minutes: 15, rows: [] }, vehicles: [], vehicles_count: 2, badges_to_return: { count: 1, rows: [] }, refusals: { pending: 0, rows: [] }, incidents: [], closable: [],
};
const base = { events: [{ id: 'e1', name: 'Stage fictif', is_permanent: false }, { id: 'p0', name: 'Permanent', is_permanent: true }], event: { id: 'e1', name: 'Stage fictif', starts_at: '2027-01-01T08:00:00Z', ends_at: '2027-03-01T18:00:00Z', status: null },
  general, counters: { attendus: 40, arrives: 30, presents: 3, refuses: 2 }, overview: { critiques: 0, hautes: 0, litiges: 2, cars: { total: 0, partis: 0, arrives: 0, ecarts: 0 }, postes_silencieux: 1, retards: 1 }, centre,
  can: { presences: true, alertes: true, litiges: true, journal: true, postes: true, groupes: true } };
const mountPage = (extra: object = {}) => mount(Operations, { props: { ...base, ...extra } as never });

describe('Administration › Opérations', () => {
  it('affiche les indicateurs calculés, sans chiffre fictif', () => {
    const w = mountPage();
    expect(w.get('[data-testid=kpi-entrees]').text()).toBe('12');
    expect(w.get('[data-testid=kpi-refus]').text()).toBe('2');
    expect(w.get('[data-testid=kpi-incidents]').text()).toBe('3'); // 1 alerte + 2 cas à décider
    expect(w.get('[data-testid=kpi-postes]').text()).toContain('7');
    // « actif » est administratif ; la connexion se prouve par la synchronisation récente
    expect(w.get('[data-testid=kpi-appareils]').text()).toBe('6 appareils synchronisés récemment · 1 silencieux');
    expect(w.get('[data-testid=kpi-presents]').text()).toBe('9');
    expect(w.get('[data-testid=kpi-presents-detail]').text()).toContain('Identités distinctes');
    expect(w.get('[data-testid=kpi-presents-detail]').text()).toContain('4 sur le site (personnel permanent), 5 en activité');
    expect(w.get('[data-testid=signal]').text()).toContain('attention');
    expect(w.findAll('[data-testid=graphique-24h] rect')).toHaveLength(48);
  });

  it('sépare le site entier du détail par activité, et ne dit jamais « En ligne » sans preuve de connexion', () => {
    const w = mountPage();
    expect(w.get('[data-testid=perimetre-site-texte]').text()).toContain('ne changent jamais avec l’activité consultée');
    expect(w.get('[data-testid=titre-detail]').text()).toBe('Détail par activité');
    expect(w.get('[data-testid=perimetre-activite]').text()).toBe('Périmètre : activité « Stage fictif »');
    expect(w.get('[data-testid=postes-resume]').text()).toContain('Site entier : 7 postes administrativement actifs sur 8 · 6 appareils synchronisés récemment · 1 silencieux');
    expect(w.get('[data-testid=liste-postes]').text()).toContain('Synchronisé récemment');
    expect(w.get('[data-testid=liste-postes]').text()).toContain('Sans nouvelles');
    expect(w.text()).not.toMatch(/En ligne/);
  });

  it('précise le périmètre du site (personnel permanent) quand il est consulté', () => {
    const w = mountPage({ event: { ...base.event, id: 'p0', name: 'Permanent', is_permanent: true } });
    expect(w.get('[data-testid=perimetre-activite]').text()).toBe('Périmètre : site (personnel permanent)');
  });

  it('montre la supervision générale puis les cinq onglets, chacun renvoyant vers l’écran propriétaire', async () => {
    const w = mountPage();
    expect(w.get('[data-testid=activite-effectifs]').text()).toContain('Attendus : 40');
    expect(w.findAll('[data-testid=liste-postes] li')).toHaveLength(2);
    expect(w.get('[data-testid=mouvements-recents]').text()).toContain('Aminata Fictive');
    await w.get('[data-testid=onglet-mouvements]').trigger('click');
    expect(w.get('[data-testid=vers-mouvements]').attributes('href')).toBe('/pilotage/mouvements?event=e1');
    await w.get('[data-testid=onglet-presences]').trigger('click');
    expect(w.get('[data-testid=presences-site]').text()).toContain('9 personnes présentes sur le site (identités distinctes');
    expect(w.get('[data-testid=presences-multi]').text()).toContain('2 présences enregistrées dans plusieurs contextes');
    expect(w.findAll('[data-testid=presences-contextes] li').map(l => l.text())).toEqual(['Site (personnel permanent) : 6', 'Stage fictif : 5']);
    expect(w.get('[data-testid=vers-evacuation]').attributes('href')).toBe('/pilotage/evacuation?event=e1');
    await w.get('[data-testid=onglet-incidents]').trigger('click');
    expect(w.get('[data-testid=inc-litiges]').text()).toBe('2');
    expect(w.get('[data-testid=vers-litiges]').attributes('href')).toBe('/pilotage/litiges?event=e1');
    await w.get('[data-testid=onglet-journaux]').trigger('click');
    expect(w.get('[data-testid=vers-journal]').attributes('href')).toBe('/administration/journal?event=e1');
  });

  it('masque les liens et le détail nominatif sans les droits correspondants, et ne porte aucune action de contrôle', async () => {
    const w = mountPage({ centre: null, can: { presences: false, alertes: false, litiges: false, journal: false, postes: false, groupes: false } });
    expect(w.text()).toContain('réservé aux comptes qui consultent les présences');
    await w.get('[data-testid=onglet-incidents]').trigger('click');
    expect(w.find('[data-testid=vers-alertes]').exists()).toBe(false);
    expect(w.find('[data-testid=vers-litiges]').exists()).toBe(false);
    await w.get('[data-testid=onglet-journaux]').trigger('click');
    expect(w.find('[data-testid=vers-journal]').exists()).toBe(false);
    expect(w.findAll('button').map(b => b.text()).filter(t => /cl[ôo]turer|restituer|valider|autoriser/i.test(t))).toEqual([]);
  });

  it('change d’activité consultée, y compris le site, et actualise sans rien écrire', async () => {
    const w = mountPage();
    await w.get('[data-testid=choix-activite]').setValue('p0');
    expect(get).toHaveBeenCalledWith('/administration/operations', { event: 'p0' }, expect.objectContaining({ preserveScroll: true }));
    expect(w.findAll('[data-testid=choix-activite] option').map(o => o.text())).toEqual(['Stage fictif', 'Site (personnel permanent)']);
    await w.get('[data-testid=actualiser]').trigger('click');
    expect(reload).toHaveBeenCalledWith({ only: ['general', 'counters', 'overview', 'centre'] });
  });
});
