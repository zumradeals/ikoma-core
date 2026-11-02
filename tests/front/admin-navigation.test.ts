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

  it('regroupe le menu en rubriques par usage et ne déplie que la rubrique de la page en cours', () => {
    const { wrapper, ids } = menu('tableau', ALL, true);
    const headings = wrapper.findAll('[data-testid^="rubrique-"]').map(b => b.text().replace(/[+–]/g, '').trim());
    expect(headings).toEqual(['Événement', 'Badges et cartes', 'Sur le terrain', 'Réglages', 'Suivi et données']);
    expect(ids).toEqual(expect.arrayContaining(['tableau', 'atelier', 'badges', 'titulaires', 'modeles-cartes', 'habilitations', 'categories-institutionnelles']));
    // Page d'accueil : toutes les rubriques sont repliées ; sur une page, seule sa rubrique est dépliée.
    expect(wrapper.findAll('[data-testid^="rubrique-"][aria-expanded="true"]')).toHaveLength(0);
    const cards = menu('habilitations', ALL);
    expect(cards.wrapper.findAll('[data-testid^="rubrique-"][aria-expanded="true"]').map(b => b.attributes('data-testid'))).toEqual(['rubrique-badges']);
    expect(cards.wrapper.get('[data-testid="menu-badges"]').text()).toBe('Badges visiteurs');
  });

  it('déplie une rubrique au clic sans quitter la page', async () => {
    const { wrapper } = menu('tableau', ALL);
    await wrapper.get('[data-testid="rubrique-reglages"]').trigger('click');
    expect(wrapper.get('[data-testid="rubrique-reglages"]').attributes('aria-expanded')).toBe('true');
    await wrapper.get('[data-testid="rubrique-reglages"]').trigger('click');
    expect(wrapper.get('[data-testid="rubrique-reglages"]').attributes('aria-expanded')).toBe('false');
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
    expect(menu('tableau', ['listes.importer']).wrapper.findAll('[data-testid^="rubrique-"]').map(b => b.attributes('data-testid'))).not.toContain('rubrique-reglages');
  });
});
