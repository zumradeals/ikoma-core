import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, expect, it, vi } from 'vitest';
import TwoFactorReset from '../../resources/js/components/TwoFactorReset.vue';
import Retention from '../../resources/js/pages/Admin/Retention.vue';
const { post, put, reset } = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn(), reset: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ Link: { template: '<a><slot /></a>' }, useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post, put, reset }) }));
beforeEach(() => { post.mockClear(); put.mockClear(); reset.mockClear(); });
it('sépare la préparation du 2FA de sa confirmation explicite avec mot de passe', async () => {
  const w = mount(TwoFactorReset, { props: { user: { id: 'u1', name: 'Compte fictif' }, challenge: null } });
  expect(w.find('input[type="password"]').exists()).toBe(false);
  await w.get('form').trigger('submit.prevent');
  expect(post).toHaveBeenCalledWith('/administration/comptes/u1/2fa/preparer');
  await w.setProps({ challenge: { user_id: 'u1', token: 'jeton-ephemere-fictif' } });
  expect(w.get('input[type="password"]').attributes('required')).toBeDefined();
  expect(w.get('input[type="checkbox"]').attributes('required')).toBeDefined();
  expect(w.text()).toContain('Toutes les sessions seront fermées');
  await w.get('form').trigger('submit.prevent');
  expect(post).toHaveBeenCalledWith('/administration/comptes/u1/2fa/confirmer', expect.any(Object));
});
it('présente les durées comme des propositions sans approbation implicite et sans bouton de purge', () => {
  const w = mount(Retention, { props: { durations: { visiteurs: 30, evenements: 90, photos: 7, synchronisations: 30, alertes: 30, notifications: 7, audit: 365 }, approved: false, exportMaximum: 10000 }, global: { stubs: { BaseLayout: { template: '<main><slot /></main>' } } } });
  expect(w.text()).toContain('Valeurs proposées');
  expect((w.get('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false);
  expect(w.findAll('button').map(b => b.text())).toEqual(['Enregistrer la politique']);
});
