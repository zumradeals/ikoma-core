import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { auth: { name: 'Administrateur fictif', permissions: [] as string[] }, demoLabAvailable: false, f06Active: true, adminContext: null, flash: {} } }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => page,
  router: { get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import AdminLayout from '../../resources/js/layouts/AdminLayout.vue';

const ALL = ['evenements.gerer', 'zones.gerer', 'categories.gerer', 'listes.importer', 'badges.gerer', 'groupes.gerer', 'postes.gerer', 'comptes.gerer', 'journal.consulter'];
const menu = (active: string, permissions: string[], demo = false, f06 = true) => {
  page.props.auth.permissions = permissions; page.props.demoLabAvailable = demo; page.props.f06Active = f06;
  const wrapper = mount(AdminLayout, { props: { active } });
  return { ids: wrapper.findAll('[data-testid^="menu-"]').map(a => a.attributes('data-testid')!.replace('menu-', '')), hrefs: wrapper.findAll('[data-testid^="menu-"]').map(a => a.attributes('href')), wrapper };
};
const SEPT = ['tableau', 'site', 'personnes', 'activites', 'badges-cartes', 'operations', 'administration'];
const SEPT_LABELS = ['Tableau de bord', 'Site et accès', 'Personnes et habilitations', 'Activités', 'Badges et cartes', 'Opérations', 'Administration'];
const tabs = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('[data-testid^="sous-menu-"]').map(a => a.attributes('data-testid')!.replace('sous-menu-', ''));

describe('navigation d’administration', () => {
  it('propose exactement les sept menus validés, à plat, sans accordéon', () => {
    const { wrapper, ids } = menu('tableau', ALL, true);
    expect(ids).toEqual(SEPT);
    expect(wrapper.findAll('[data-testid^="menu-"]').map(a => a.text())).toEqual(SEPT_LABELS);
    expect(wrapper.findAll('[data-testid^="rubrique-"],[data-testid^="groupe-"],[aria-expanded]').filter(e => e.attributes('data-testid') !== 'ouvrir-menu')).toHaveLength(0);
    expect(wrapper.get('[data-testid="menu-badges-cartes"]').attributes('href')).toBe('/administration/badges');
    expect(wrapper.get('[data-testid="menu-site"]').attributes('href')).toBe('/administration/site');
  });

  it('ne duplique ni adresse ni intitulé dans les menus', () => {
    for (const active of ['tableau', 'badges', 'titulaires', 'atelier', 'modeles', 'imports']) {
      const { hrefs, wrapper } = menu(active, ALL, true);
      expect(new Set(hrefs).size, active).toBe(hrefs.length);
      const labels = wrapper.findAll('[data-testid^="menu-"]').map(a => a.text());
      expect(new Set(labels).size, active).toBe(labels.length);
    }
  });

  it('garde toutes les pages existantes accessibles, en onglets du menu qui les regroupe', () => {
    const expected: Record<string, string[]> = {
      'personnes': ['personnes', 'habilitations', 'categories-institutionnelles'],
      'evenements': ['evenements', 'categories'],
      'postes': ['postes', 'groupes'],
      'comptes': ['identite', 'modules', 'comptes', 'donnees', 'journal', 'securite'],
    };
    for (const [active, onglets] of Object.entries(expected)) expect(tabs(menu(active, ALL).wrapper), active).toEqual(onglets);
    expect(tabs(menu('tableau', ALL).wrapper)).toEqual([]);
    // Site et accès : une seule entrée ; zones et accès des participants y restent rattachés, sans navigation concurrente
    expect(tabs(menu('site-acces', ALL).wrapper)).toEqual([]);
    expect(tabs(menu('badges', ALL).wrapper)).toEqual([]);
    expect(tabs(menu('comptes', ALL, true).wrapper)).toContain('demonstration');
    // chaque ancienne entrée reste atteignable : un onglet ou un menu pour chacune des 19 pages
    const reachable = new Set<string>();
    for (const active of ['tableau', 'site-acces', 'personnes', 'evenements', 'badges', 'postes', 'comptes']) {
      const m = menu(active, ALL, true);
      m.ids.forEach(i => reachable.add(i)); tabs(m.wrapper).forEach(i => reachable.add(i));
    }
    for (const key of ['tableau', 'evenements', 'categories', 'personnes', 'badges-cartes', 'habilitations', 'categories-institutionnelles', 'site', 'postes', 'groupes', 'identite', 'modules', 'comptes', 'donnees', 'journal', 'securite', 'demonstration']) {
      expect(reachable.has(key), key).toBe(true);
    }
  });

  it('marque le menu de la page en cours, y compris les pages rattachées à Badges et cartes, Personnes et Données', () => {
    expect(menu('tableau', ALL).wrapper.get('[data-testid="menu-tableau"]').attributes('aria-current')).toBe('page');
    expect(menu('site-acces', ALL).wrapper.get('[data-testid="menu-site"]').attributes('aria-current')).toBe('page');
    for (const active of ['zones', 'acces-zones']) expect(menu(active, ALL).wrapper.get('[data-testid="menu-site"]').attributes('aria-current'), active).toBe('page');
    expect(menu('habilitations', ALL).wrapper.get('[data-testid="menu-personnes"]').attributes('aria-current')).toBe('page');
    expect(menu('postes', ALL).wrapper.get('[data-testid="menu-operations"]').attributes('aria-current')).toBe('page');
    for (const active of ['badges', 'titulaires', 'modeles', 'modeles-cartes', 'atelier', 'atelier-parcours']) {
      expect(menu(active, ALL).wrapper.get('[data-testid="menu-badges-cartes"]').attributes('aria-current'), active).toBe('page');
    }
    const imports = menu('imports', ALL);
    expect(imports.wrapper.get('[data-testid="menu-personnes"]').attributes('aria-current')).toBe('page');
    expect(imports.wrapper.get('[data-testid="sous-menu-personnes"]').attributes('aria-current')).toBe('page');
    const conservation = menu('conservation', ALL);
    expect(conservation.wrapper.get('[data-testid="menu-administration"]').attributes('aria-current')).toBe('page');
    expect(conservation.wrapper.get('[data-testid="sous-menu-donnees"]').attributes('aria-current')).toBe('page');
  });

  it('retire la démonstration et le module Groupes quand ils ne sont pas actifs', () => {
    expect(tabs(menu('comptes', ALL, false, false).wrapper)).not.toEqual(expect.arrayContaining(['demonstration', 'groupes']));
    expect(tabs(menu('comptes', ALL, true).wrapper)).toContain('demonstration');
    expect(tabs(menu('postes', ALL, false, false).wrapper)).toEqual([]);
    expect(tabs(menu('postes', ALL, false, true).wrapper)).toContain('groupes');
  });

  it('ne montre aucun lien vers une fonction interdite à l’utilisateur', () => {
    const limited = menu('titulaires', ['listes.importer']);
    expect(limited.ids).toEqual(['personnes', 'administration']);
    expect(tabs(menu('personnes', ['listes.importer']).wrapper)).toEqual(['personnes', 'habilitations']);
    expect(menu('tableau', []).ids).toEqual(['administration']);
    expect(menu('securite', []).hrefs).toEqual(['/securite']);
    expect(menu('tableau', ['postes.gerer']).ids).toEqual(['site', 'operations', 'administration']);
  });

  it('s’ouvre et se referme sur petit écran par un seul bouton', async () => {
    const { wrapper } = menu('tableau', ALL);
    const toggle = wrapper.get('[data-testid="ouvrir-menu"]');
    expect(toggle.attributes('aria-expanded')).toBe('false');
    await toggle.trigger('click');
    expect(toggle.attributes('aria-expanded')).toBe('true');
    await wrapper.get('[data-testid="menu-site"]').trigger('click');
    expect(toggle.attributes('aria-expanded')).toBe('false');
  });
});
