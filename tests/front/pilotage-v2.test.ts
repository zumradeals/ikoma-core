import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
const { get, permissions } = vi.hoisted(() => ({ get: vi.fn(), permissions: ['pilotage.consulter', 'presences.consulter', 'alertes.consulter', 'litiges.decider', 'groupes.suivre', 'cars.suivre', 'journal.consulter', 'badges.annuler'] }));
const state = reactive({ f06Active: true, auth: { name: 'Superviseur fictif', permissions: [...permissions] }, errors: {}, criticalAlerts: 0 });
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: state }),
  router: { get, reload: vi.fn(), post: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
import Layout from '../../resources/js/layouts/PilotageLayout.vue';
import Index from '../../resources/js/pages/Pilotage/Index.vue';
import { pilotageHref } from '../../resources/js/pilotage/navigation';
const event = { id: 'e1', name: 'Événement fictif' };
const wrappers: ReturnType<typeof mount>[] = [];
const layout = () => { const w = mount(Layout, { props: { active: 'plus', event, events: [event, { id: 'e2', name: 'Autre événement fictif' }], openAlerts: 2, title: 'Groupes et cars' } }); wrappers.push(w); return w; };
beforeEach(() => { state.f06Active = true; state.auth.permissions = [...permissions]; get.mockClear(); window.localStorage.removeItem('ikoma.son-alertes'); });
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); });
describe('navigation commune du superviseur V2', () => {
  it('replie le menu par défaut puis le ferme après une navigation, sans perdre l’événement', async () => {
    const w = layout();
    const toggle = w.get('[data-testid=ouvrir-menu-pilotage]');
    expect(toggle.attributes('aria-expanded')).toBe('false');
    await toggle.trigger('click');
    expect(toggle.attributes('aria-expanded')).toBe('true');
    expect(w.get('[data-testid=menu-plus]').attributes('aria-current')).toBe('page');
    expect(w.get('[data-testid=menu-plus]').attributes('href')).toBe('/pilotage/groupes?event=e1');
    await w.get('[data-testid=menu-presents]').trigger('click');
    expect(toggle.attributes('aria-expanded')).toBe('false');
    expect(w.get('[data-testid=nav-evacuation]').attributes('href')).toBe('/pilotage/evacuation?event=e1');
  });
  it('ne présente que les liens autorisés, avec les deux droits requis pour les groupes', () => {
    state.auth.permissions = ['pilotage.consulter', 'groupes.suivre'];
    const w = layout();
    expect(w.find('[data-testid=menu-tableau]').exists()).toBe(true);
    for (const key of ['plus', 'journal', 'litiges', 'presents', 'alertes', 'evacuation']) expect(w.find(`[data-testid=menu-${key}]`).exists()).toBe(false);
  });
  it('masque les groupes si F06 est désactivé même lorsque les droits sont présents', () => {
    state.f06Active = false;
    const w = layout();
    expect(w.find('[data-testid=menu-plus]').exists()).toBe(false);
    expect(w.find('[data-testid=menu-tableau]').exists()).toBe(true);
  });
  it('conserve les filtres du lien et remplace uniquement l’événement', () => {
    expect(pilotageHref('/pilotage/alertes?etat=prises&event=ancien', 'nouveau')).toBe('/pilotage/alertes?etat=prises&event=nouveau');
    expect(pilotageHref('/pilotage')).toBe('/pilotage');
  });
  it('conserve la commande sonore explicite et son état accessible', async () => {
    const w = layout(); const sound = w.get('[data-testid=son-alertes]');
    expect(sound.attributes('aria-pressed')).toBe('false');
    await sound.trigger('click');
    expect(sound.attributes('aria-pressed')).toBe('true');
    expect(sound.text()).toContain('activé');
  });
  it('ne rend aucun menu ni session dans la coquille publique d’évacuation', () => {
    const w = mount(Layout, { props: { active: 'evacuation', bare: true }, slots: { default: 'Liste verrouillée' } }); wrappers.push(w);
    expect(w.find('header').exists()).toBe(false);
    expect(w.find('nav').exists()).toBe(false);
    expect(w.text()).toContain('Liste verrouillée');
  });
  it('place l’annulation contrôlée dans Autres actions et masque les fonctions sans droits', () => {
    const props = { event, events: [event], openAlerts: 0, counters: { attendus: 10, arrives: 2, presents: 1, refuses: 0, as_of: '2026-10-29T12:00:00Z' }, alerts: [], overview: { critiques: 0, hautes: 0, litiges: 0, cars: { total: 0, partis: 0, arrives: 0, ecarts: 0 }, postes_silencieux: 0 } };
    const w = mount(Index, { props }); wrappers.push(w);
    expect(w.get('[data-testid=autres-actions]').element.tagName).toBe('DETAILS');
    expect(w.find('[data-testid=autres-actions] [data-testid=annulation]').exists()).toBe(true);
    state.auth.permissions = ['pilotage.consulter'];
    const restricted = mount(Index, { props }); wrappers.push(restricted);
    expect(restricted.find('[data-testid=autres-actions]').exists()).toBe(false);
    expect(restricted.find('[data-testid=terrain-litiges]').exists()).toBe(false);
    expect(restricted.find('[data-testid=annulation]').exists()).toBe(false);
  });
});
