import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { auth: { permissions: [] as string[] } as { permissions: string[] } | null, badgesCartes: null as unknown } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import BadgesCartesShell from '../../resources/js/components/admin/BadgesCartesShell.vue';

const summary = {
  visitors: { available: 12, to_return: 3, batches: 2, print_runs: 4 }, cards: { holders: 7, active: 5, prints: 6 },
  totals: { supports_actifs: 9, cartes_permanentes: 5, badges_visiteurs: 4, tirages_30j: 2 },
};
const render = (active: 'registre' | 'visiteurs' | 'cartes' | 'modeles' | 'impression' | 'preparer', permissions: string[], badgesCartes: unknown = null, titre = false) => {
  page.props.auth = { permissions }; page.props.badgesCartes = badgesCartes;
  return mount(BadgesCartesShell, { props: { active, titre } });
};
const ALL = ['badges.gerer', 'listes.importer'];

describe('cadre « Badges et cartes »', () => {
  it('présente les trois responsabilités, chacune menant à sa page existante', () => {
    const wrapper = render('registre', ALL);
    expect(wrapper.findAll('[data-testid^="onglet-"]').map(a => [a.attributes('data-testid'), a.attributes('href')])).toEqual([
      ['onglet-registre', '/administration/badges'], ['onglet-modeles', '/administration/modeles'], ['onglet-impression', '/administration/atelier'],
    ]);
    expect(wrapper.get('[data-testid="onglet-registre"]').attributes('aria-current')).toBe('page');
    expect(wrapper.get('[data-testid="onglet-modeles"]').attributes('aria-current')).toBeUndefined();
  });

  it('rattache les anciennes pages (lots, fiche d’un badge, cartes, préparation) à « Registre et cartes »', () => {
    for (const key of ['visiteurs', 'cartes', 'preparer'] as const) expect(render(key, ALL).get('[data-testid="onglet-registre"]').attributes('aria-current')).toBe('page');
  });

  it('ne propose un onglet que si la permission de sa page d’arrivée est détenue', () => {
    expect(render('registre', ['badges.gerer']).findAll('[data-testid^="onglet-"]')).toHaveLength(3);
    expect(render('registre', ['listes.importer']).findAll('[data-testid^="onglet-"]')).toHaveLength(0);
    expect(render('registre', []).findAll('[data-testid^="onglet-"]')).toHaveLength(0);
  });

  it('« Préparer un badge » est une action de l’espace de travail, pas un onglet ni un quatrième moteur', () => {
    expect(render('modeles', ALL).get('[data-testid="preparer-badge"]').attributes('href')).toBe('/administration/badges');
    expect(render('modeles', ['badges.gerer']).find('[data-testid="preparer-badge"]').exists()).toBe(false);
    expect(render('registre', ALL, null, true).find('[data-testid="preparer-badge"]').exists()).toBe(false);
    expect(render('registre', ALL, null, true).get('[data-testid="titre-badges-cartes"]').text()).toBe('Badges et cartes');
  });

  it('affiche les quatre compteurs fournis par le serveur, sans en inventer', () => {
    const wrapper = render('modeles', ALL, summary);
    expect(wrapper.get('[data-testid="compteur-supports-actifs"]').text()).toBe('9');
    expect(wrapper.get('[data-testid="compteur-cartes-permanentes"]').text()).toBe('5');
    expect(wrapper.get('[data-testid="compteur-badges-visiteurs"]').text()).toBe('4');
    expect(wrapper.get('[data-testid="compteur-tirages-30j"]').text()).toBe('2');
    expect(render('modeles', ALL, null).find('[data-testid="compteurs-badges-cartes"]').exists()).toBe(false);
    expect(render('modeles', ALL, { visitors: summary.visitors, cards: summary.cards }).find('[data-testid="compteurs-badges-cartes"]').exists()).toBe(false);
  });
});
