import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { auth: { permissions: [] as string[] } as { permissions: string[] } | null, badgesCartes: null as unknown } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import BadgesCartesShell from '../../resources/js/components/admin/BadgesCartesShell.vue';

const summary = { visitors: { available: 12, to_return: 3, batches: 2, print_runs: 4 }, cards: { holders: 7, active: 5, prints: 6 } };
const render = (active: 'visiteurs' | 'cartes' | 'modeles' | 'impression', permissions: string[], badgesCartes: unknown = null) => {
  page.props.auth = { permissions }; page.props.badgesCartes = badgesCartes;
  return mount(BadgesCartesShell, { props: { active } });
};
const ALL = ['badges.gerer', 'listes.importer'];

describe('cadre « Badges et cartes »', () => {
  it('présente les quatre responsabilités, chacune menant à sa page existante', () => {
    const wrapper = render('visiteurs', ALL);
    expect(wrapper.findAll('[data-testid^="onglet-"]').map(a => [a.attributes('data-testid'), a.attributes('href')])).toEqual([
      ['onglet-visiteurs', '/administration/badges'], ['onglet-cartes', '/administration/titulaires'], ['onglet-modeles', '/administration/modeles'], ['onglet-impression', '/administration/atelier'],
    ]);
    expect(wrapper.get('[data-testid="onglet-visiteurs"]').attributes('aria-current')).toBe('page');
    expect(wrapper.get('[data-testid="onglet-cartes"]').attributes('aria-current')).toBeUndefined();
  });

  it('ne propose un onglet que si la permission de sa page d’arrivée est détenue', () => {
    expect(render('visiteurs', ['badges.gerer']).findAll('[data-testid^="onglet-"]').map(a => a.attributes('data-testid'))).toEqual(['onglet-visiteurs', 'onglet-modeles', 'onglet-impression']);
    expect(render('cartes', ['listes.importer']).findAll('[data-testid^="onglet-"]').map(a => a.attributes('data-testid'))).toEqual(['onglet-cartes']);
    expect(render('cartes', []).findAll('[data-testid^="onglet-"]')).toHaveLength(0);
  });

  it('affiche les compteurs fournis par le serveur, sans en inventer', () => {
    const wrapper = render('modeles', ALL, summary);
    expect(wrapper.get('[data-testid="compteur-disponibles"]').text()).toBe('12');
    expect(wrapper.get('[data-testid="compteur-a-restituer"]').text()).toBe('3');
    expect(wrapper.get('[data-testid="compteur-cartes-actives"]').text()).toBe('5');
    expect(wrapper.get('[data-testid="compteur-tirages"]').text()).toBe('10');
    expect(render('modeles', ALL, null).find('[data-testid="compteurs-badges-cartes"]').exists()).toBe(false);
  });
});
