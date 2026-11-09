import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { permissions } = vi.hoisted(() => ({ permissions: { list: ['badges.gerer', 'listes.importer'] as string[] } }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'Administrateur fictif', permissions: permissions.list }, f06Active: true, demoLabAvailable: false, flash: {}, errors: {} } }),
  router: { get: vi.fn(), post: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import AtelierParcours from '../../resources/js/pages/Admin/AtelierParcours.vue';

const props = {
  visitors: { batches: 3, badges: { Disponible: 40, Associé: 6, Restitué: 2, Annulé: 1, Clos: 3 }, print_runs: 4, to_return: 2 },
  cards: { holders: 12, active: 10, inactive: 3, prints: 15 },
};

describe('parcours de l’atelier', () => {
  it('sépare clairement les badges visiteurs et les cartes permanentes, avec leurs nombres', () => {
    permissions.list = ['badges.gerer', 'listes.importer'];
    const wrapper = mount(AtelierParcours, { props });
    expect(wrapper.get('[data-testid=domaine-visiteurs]').text()).toContain('Badges visiteurs');
    expect(wrapper.get('[data-testid=domaine-cartes]').text()).toContain('Cartes permanentes');
    expect(wrapper.get('[data-testid=stats-visiteurs]').text()).toContain('40 disponible(s)');
    expect(wrapper.get('[data-testid=stats-visiteurs]').text()).toContain('5 restitué(s) ou clos');
    expect(wrapper.get('[data-testid=stats-cartes]').text()).toContain('12 titulaire(s)');
    // aucune étape d'un domaine ne figure dans l'autre
    expect(wrapper.get('[data-testid=domaine-visiteurs]').text()).not.toContain('Titulaires');
    expect(wrapper.get('[data-testid=domaine-cartes]').text()).not.toContain('Restituer');
  });

  it('chaque lien mène à un écran existant, et les étapes d’accueil n’ont pas de bouton', () => {
    permissions.list = ['badges.gerer', 'listes.importer'];
    const wrapper = mount(AtelierParcours, { props });
    expect(wrapper.get('[data-testid=lien-visiteur-preparer]').attributes('href')).toBe('/administration/atelier');
    expect(wrapper.get('[data-testid=lien-visiteur-stock]').attributes('href')).toBe('/administration/badges/lots');
    expect(wrapper.get('[data-testid=lien-visiteur-historique]').attributes('href')).toBe('/administration/badges/registre');
    expect(wrapper.get('[data-testid=lien-carte-titulaires]').attributes('href')).toBe('/administration/titulaires');
    expect(wrapper.get('[data-testid=lien-carte-modeles]').attributes('href')).toBe('/administration/titulaires/modeles');
    expect(wrapper.get('[data-testid=lien-carte-imprimer]').attributes('href')).toBe('/administration/atelier?mode=cartes');
    expect(wrapper.find('[data-testid=lien-visiteur-attribuer]').exists()).toBe(false);
    expect(wrapper.find('[data-testid=lien-visiteur-restituer]').exists()).toBe(false);
    expect(wrapper.get('[data-testid=etape-visiteur-restituer]').text()).toContain('acte distinct de la sortie');
    expect(wrapper.get('[data-testid=etape-visiteur-restituer]').text()).toContain('2 badge(s) encore attribué(s)');
  });

  it('masque les liens que les droits de l’utilisateur ne permettent pas', () => {
    permissions.list = ['badges.gerer'];
    const wrapper = mount(AtelierParcours, { props });
    expect(wrapper.find('[data-testid=lien-visiteur-preparer]').exists()).toBe(true);
    expect(wrapper.find('[data-testid=lien-carte-titulaires]').exists()).toBe(false);
    expect(wrapper.find('[data-testid=lien-carte-imprimer]').exists()).toBe(false);
  });
});
