import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi } from 'vitest';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { name: 'IKOMA ADMIN', permissions: ['evenements.gerer'] } } }),
  router: { get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post, reset: vi.fn() }),
}));

import Evenements from '../../resources/js/pages/Admin/Evenements.vue';
import AdminLayout from '../../resources/js/layouts/AdminLayout.vue';

describe('création des événements dans IKOMA ACCESS', () => {
  it('affiche le formulaire et un état vide explicite', () => {
    const wrapper = mount(Evenements, { props: { events: [] } });
    expect(wrapper.find('[data-testid=form-creer-evenement]').exists()).toBe(true);
    expect(wrapper.get('[data-testid=liste-evenements-vide]').text()).toContain('Aucun événement');
    expect(wrapper.get('[data-testid=creer-evenement]').text()).toContain('Créer l’événement');
  });

  it('envoie les données à la bonne route', async () => {
    post.mockClear();
    const wrapper = mount(Evenements, { props: { events: [] } });
    await wrapper.get('#nom-evenement').setValue('Conférence de sécurité');
    await wrapper.get('#site-evenement').setValue('Abidjan');
    await wrapper.get('#debut-evenement').setValue('2030-11-10T08:00');
    await wrapper.get('#fin-evenement').setValue('2030-11-10T18:00');
    await wrapper.get('[data-testid=form-creer-evenement]').trigger('submit');
    expect(post).toHaveBeenCalledWith('/administration/evenements', expect.any(Object));
  });

  it('liste plusieurs événements sans mélanger leurs identifiants', () => {
    const wrapper = mount(Evenements, {
      props: {
        events: [
          { id: 'e1', name: 'Forum de la sécurité', site: 'Abidjan', starts_at: '2030-11-10T08:00:00Z', ends_at: '2030-11-10T18:00:00Z' },
          { id: 'e2', name: 'Salon professionnel', site: null, starts_at: '2030-12-10T08:00:00Z', ends_at: '2030-12-10T18:00:00Z' },
        ],
      },
    });
    expect(wrapper.get('[data-testid=ouvrir-evenement-e1]').attributes('href')).toBe('/administration?event=e1');
    expect(wrapper.get('[data-testid=ouvrir-evenement-e2]').attributes('href')).toBe('/administration?event=e2');
  });

  it('affiche le nouveau nom et le pictogramme IKOMA ACCESS dans l’administration', () => {
    const wrapper = mount(AdminLayout, { props: { active: 'tableau' } });
    expect(wrapper.get('.admin-brand').text()).toContain('IKOMA ACCESS');
    expect(wrapper.get('.admin-logo img').attributes('src')).toBe('/brand/ikoma-access-icon.svg');
    expect(wrapper.get('[data-testid=menu-evenements]').attributes('href')).toBe('/administration/evenements');
  });
});
