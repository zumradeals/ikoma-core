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
const menu = (active: string, permissions: string[], demo = false) => {
  page.props.auth.permissions = permissions; page.props.demoLabAvailable = demo;
  const wrapper = mount(AdminLayout, { props: { active } });
  return { ids: wrapper.findAll('[data-testid^="menu-"]').map(a => a.attributes('data-testid')!.replace('menu-', '')), hrefs: wrapper.findAll('[data-testid^="menu-"]').map(a => a.attributes('href')), wrapper };
};

describe('navigation d’administration', () => {
  it('propose une seule entrée par usage, sans doublon d’adresse ni d’intitulé', () => {
    for (const active of ['tableau', 'titulaires', 'badges', 'imports']) {
      const { hrefs, wrapper } = menu(active, ALL);
      expect(new Set(hrefs).size, active).toBe(hrefs.length);
      const labels = wrapper.findAll('[data-testid^="menu-"]').map(a => a.text());
      expect(new Set(labels).size, active).toBe(labels.length);
    }
  });

  it('sépare les badges visiteurs et les cartes permanentes ; l’Atelier des badges est une entrée de premier niveau toujours visible, les autres sous-entrées seulement dans leur rubrique', () => {
    expect(menu('tableau', ALL).ids).toEqual(expect.arrayContaining(['badges', 'titulaires']));
    expect(menu('tableau', ALL).ids).not.toEqual(expect.arrayContaining(['modeles-cartes', 'habilitations', 'categories-institutionnelles']));
    const badges = menu('badges', ALL);
    expect(badges.ids).toContain('atelier'); expect(badges.ids).not.toContain('modeles-cartes');
    expect(badges.wrapper.get('[data-testid="menu-badges"]').text()).toBe('Badges visiteurs');
    for (const active of ['titulaires', 'modeles-cartes', 'habilitations', 'categories-institutionnelles']) {
      const ids = menu(active, ALL).ids;
      expect(ids, active).toEqual(expect.arrayContaining(['titulaires', 'modeles-cartes', 'habilitations', 'categories-institutionnelles'])); expect(ids).toContain('atelier');
    }
    expect(menu('atelier', ALL).ids).toContain('atelier');
  });

  it('retire la démonstration de la navigation en exploitation et ne la montre que dans les environnements d’essai', () => {
    expect(menu('tableau', ALL, false).ids).not.toContain('demonstration');
    expect(menu('tableau', ALL, true).ids).toContain('demonstration');
  });

  it('ne montre aucun lien vers une fonction interdite à l’utilisateur', () => {
    const limited = menu('titulaires', ['listes.importer']);
    expect(limited.ids).toEqual(expect.arrayContaining(['imports', 'titulaires', 'habilitations']));
    expect(limited.ids).not.toEqual(expect.arrayContaining(['badges', 'modeles-cartes', 'categories-institutionnelles', 'zones', 'comptes', 'postes', 'demonstration']));
    expect(menu('tableau', []).ids).toEqual(['securite']);
  });
});
