import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
const { getMeta, close } = vi.hoisted(() => ({ getMeta: vi.fn(), close: vi.fn() }));
const state = reactive({ f06Active: true, auth: { name: 'Agent fictif', permissions: ['visiteurs.rechercher', 'groupes.appel_nominal', 'badges.controler'] } });
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: state }), Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('../../resources/js/poste/local/db', () => ({ PosteDb: class { close = close; }, getMeta }));
import Layout from '../../resources/js/layouts/AgentLayout.vue';
import Home from '../../resources/js/components/agent/AgentHome.vue';
const wrappers: ReturnType<typeof mount>[] = [];
afterEach(() => wrappers.splice(0).forEach(w => w.unmount()));
beforeEach(() => { state.f06Active = true; state.auth.permissions = ['visiteurs.rechercher', 'groupes.appel_nominal', 'badges.controler']; getMeta.mockReset(); close.mockClear(); });
describe('cadre Agent V2', () => {
  it('garde le menu replié, puis l’ouvre avec un état accessible', async () => {
    const w = mount(Layout, { props: { active: 'poste', agent: 'Agent fictif' } }); wrappers.push(w);
    const toggle = w.get('[data-testid=menu-agent-toggle]');
    expect(toggle.attributes('aria-expanded')).toBe('false'); await toggle.trigger('click');
    expect(toggle.attributes('aria-expanded')).toBe('true');
    expect(w.get('[data-testid=agent-nav-poste]').attributes('aria-current')).toBe('page');
    expect(w.get('[data-testid=agent-nav-controle]').attributes('href')).toBe('/controle');
  });
  it('ne présente pas un module absent des permissions', () => {
    state.auth.permissions = ['visiteurs.rechercher'];
    const w = mount(Layout, { props: { active: 'home' } }); wrappers.push(w);
    expect(w.find('[data-testid=agent-nav-groupe]').exists()).toBe(false);
    expect(w.find('[data-testid=agent-nav-controle]').exists()).toBe(false);
    expect(w.find('[data-testid=agent-nav-poste]').exists()).toBe(true);
  });
  it('n’engage aucune navigation serveur hors ligne et laisse le parcours ouvert', () => {
    const w = mount(Layout, { props: { active: 'poste', online: false }, slots: { default: '<p>Étape en cours</p>' } }); wrappers.push(w);
    expect(w.findAll('nav a')).toHaveLength(0);
    expect(w.text()).toContain('Étape en cours');
    expect(w.text()).toContain('Connexion indisponible');
  });
  it('affiche les quatre fonctions et ne prétend pas à une affectation sans information locale', async () => {
    getMeta.mockResolvedValue(undefined);
    const w = mount(Home, { props: { agent: state.auth } }); wrappers.push(w); await flushPromises();
    expect(w.findAll('.agent-function-card')).toHaveLength(4);
    expect(w.text()).toContain('À enrôler');
    expect(w.text()).toContain('Aucune synchronisation connue');
    expect(w.text()).not.toContain('Zone :');
    expect(getMeta.mock.calls.map(c => c[1])).toEqual(['vault', 'posteName', 'lastSyncAt']);
  });
  it('retire la fonction de groupes lorsque F06 est inactif', async () => {
    state.f06Active = false;
    getMeta.mockResolvedValue(undefined);
    const w = mount(Home, { props: { agent: state.auth } }); wrappers.push(w); await flushPromises();
    expect(w.findAll('.agent-function-card')).toHaveLength(3);
    expect(w.find('.agent-home-grid').text()).not.toContain('Groupes et cars');
  });
  it('présente l’état connu de cet appareil sans ouvrir le coffre ni exposer une clé', async () => {
    getMeta.mockImplementation((_db: unknown, key: string) => Promise.resolve({ vault: { opaque: true }, posteName: 'Poste fictif A', lastSyncAt: '2026-10-29T12:00:00Z' }[key as 'vault']));
    const w = mount(Home, { props: { agent: state.auth } }); wrappers.push(w); await flushPromises();
    expect(w.text()).toContain('Poste fictif A'); expect(w.text()).toContain('PIN requis');
    expect(w.text()).not.toContain('opaque');
  });
});
