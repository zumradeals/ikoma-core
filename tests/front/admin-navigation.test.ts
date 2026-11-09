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
const FULL = ['tableau', 'evenements', 'zones', 'categories', 'personnes', 'badges-cartes', 'acces-zones', 'habilitations', 'categories-institutionnelles', 'postes', 'groupes', 'identite', 'modules', 'comptes', 'donnees', 'journal', 'securite', 'demonstration'];

describe('navigation d’administration', () => {
  it('propose une seule entrée par usage, sans doublon d’adresse ni d’intitulé', () => {
    for (const active of ['tableau', 'badges', 'titulaires', 'atelier', 'modeles', 'imports']) {
      const { hrefs, wrapper } = menu(active, ALL, true);
      expect(new Set(hrefs).size, active).toBe(hrefs.length);
      const labels = wrapper.findAll('[data-testid^="menu-"]').map(a => a.text());
      expect(new Set(labels).size, active).toBe(labels.length);
    }
  });

  it('regroupe le menu en cinq rubriques par intention, dont une seule entrée « Badges et cartes »', () => {
    const { wrapper, ids } = menu('tableau', ALL, true);
    expect(ids).toEqual(FULL);
    const headings = wrapper.findAll('[data-testid^="rubrique-"]').map(b => b.text().replace(/[+–]/g, '').trim());
    expect(headings).toEqual(['Activités et personnes', 'Accès et habilitations', 'Postes et opérations', 'Administration et sécurité']);
    expect(wrapper.get('[data-testid="menu-badges-cartes"]').text()).toBe('Badges et cartes');
    expect(wrapper.get('[data-testid="menu-badges-cartes"]').attributes('href')).toBe('/administration/badges');
    expect(wrapper.findAll('[data-testid^="groupe-"],[data-testid="menu-badges-cartes"]')).toHaveLength(5);
  });

  it('compte 16 entrées visibles en exploitation, 18 avec le module Groupes et la démonstration', () => {
    expect(menu('tableau', ALL, false, false).ids).toHaveLength(16);
    expect(menu('tableau', ALL, true, true).ids).toHaveLength(18);
    expect(menu('tableau', ALL, false, false).ids).not.toEqual(expect.arrayContaining(['groupes', 'demonstration']));
  });

  it('ne déplie que la rubrique de la page en cours, et rattache chaque page de l’espace Badges et cartes à son entrée', () => {
    expect(menu('tableau', ALL).wrapper.findAll('[data-testid^="rubrique-"][aria-expanded="true"]')).toHaveLength(0);
    const cards = menu('habilitations', ALL);
    expect(cards.wrapper.findAll('[data-testid^="rubrique-"][aria-expanded="true"]').map(b => b.attributes('data-testid'))).toEqual(['rubrique-acces']);
    for (const active of ['badges', 'titulaires', 'modeles', 'modeles-cartes', 'atelier', 'atelier-parcours']) {
      expect(menu(active, ALL).wrapper.get('[data-testid="menu-badges-cartes"]').attributes('aria-current'), active).toBe('page');
    }
    // les pages rattachées gardent leur entrée : liste d'invités sous « Personnes », conservation sous « Données et conservation »
    const imports = menu('imports', ALL);
    expect(imports.wrapper.get('[data-testid="menu-personnes"]').attributes('aria-current')).toBe('page');
    expect(imports.wrapper.get('[data-testid="rubrique-activites"]').attributes('aria-expanded')).toBe('true');
    expect(menu('conservation', ALL).wrapper.get('[data-testid="menu-donnees"]').attributes('aria-current')).toBe('page');
  });

  it('déplie une rubrique au clic sans quitter la page', async () => {
    const { wrapper } = menu('tableau', ALL);
    await wrapper.get('[data-testid="rubrique-administration"]').trigger('click');
    expect(wrapper.get('[data-testid="rubrique-administration"]').attributes('aria-expanded')).toBe('true');
    await wrapper.get('[data-testid="rubrique-administration"]').trigger('click');
    expect(wrapper.get('[data-testid="rubrique-administration"]').attributes('aria-expanded')).toBe('false');
  });

  it('retire la démonstration de la navigation en exploitation et ne la montre que dans les environnements d’essai', () => {
    expect(menu('tableau', ALL, false).ids).not.toContain('demonstration');
    expect(menu('tableau', ALL, true).ids).toContain('demonstration');
  });

  it('ne montre aucun lien vers une fonction interdite à l’utilisateur', () => {
    const limited = menu('titulaires', ['listes.importer']);
    expect(limited.ids).toEqual(expect.arrayContaining(['personnes', 'habilitations']));
    expect(limited.ids).not.toEqual(expect.arrayContaining(['badges-cartes', 'categories-institutionnelles', 'zones', 'comptes', 'postes', 'demonstration']));
    expect(menu('tableau', []).ids).toEqual(['securite']);
    expect(menu('tableau', ['listes.importer']).wrapper.findAll('[data-testid^="rubrique-"]').map(b => b.attributes('data-testid'))).not.toContain('rubrique-administration');
  });
});
