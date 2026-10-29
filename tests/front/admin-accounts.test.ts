import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
const { put, post, permissions } = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn(), permissions: ['evenements.gerer', 'comptes.gerer', 'roles.attribuer', 'badges.gerer'] }));
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { auth: { id: 'admin', name: 'Admin fictif', permissions } } }),
  router: { get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, put, post, reset: vi.fn() }),
}));
import Accounts from '../../resources/js/pages/Admin/Accounts.vue';
import AccountRow from '../../resources/js/components/AccountRow.vue';
const user = { id: 'a1', name: 'Élodie Fictive', email: 'elodie@exemple.test', role: 'agent_accueil', active: true, two_factor_confirmed: false };
const props = { users: [user, { ...user, id: 's1', name: 'Superviseur fictif', email: 'superviseur@exemple.test', role: 'superviseur', active: false, two_factor_confirmed: true }], roles: ['agent_accueil', 'superviseur', 'hote'], hosts: [], idle: 27, hostTimeout: 9, twoFactorReset: null };
beforeEach(() => { put.mockClear(); post.mockClear(); vi.restoreAllMocks(); });
describe('comptes intégrés au cadre administrateur', () => {
  it('garde un seul cadre et cherche les comptes sans accents par nom ou rôle', async () => {
    const w = mount(Accounts, { props });
    expect(w.findAll('[data-testid=admin-layout]')).toHaveLength(1);
    expect(w.findAll('tbody > tr')).toHaveLength(2);
    expect(w.findAll('tbody form')).toHaveLength(0);
    await w.get('input[type=search]').setValue('elodie');
    expect(w.findAll('tbody > tr')).toHaveLength(1);
    await w.get('input[type=search]').setValue('superviseur');
    expect(w.get('tbody').text()).toContain('Superviseur fictif');
    await w.get('input[type=search]').setValue('introuvable');
    expect(w.get('tbody').text()).toContain('Aucun compte ne correspond');
  });
  it('initialise les réglages depuis le serveur et ne compte que les superviseurs actifs', () => {
    const w = mount(Accounts, { props });
    expect(w.findAll('input[type=number]').map(x => (x.element as HTMLInputElement).value)).toEqual(['9', '27']);
    expect(w.get('[aria-label="Synthèse des comptes"]').text()).toContain('0superviseurs actifs');
  });
  it('garde le rattachement cohérent quand le compte hôte sélectionné change de rôle', async () => {
    const hosts = ['h1', 'h2', 'h3'].map(id => ({ ...user, id, name: 'Compte ' + id, role: 'hote' }));
    const w = mount(Accounts, { props: { ...props, users: hosts } });
    const selector = w.findAll('select').find(s => s.find('option[value=h3]').exists())!;
    await selector.setValue('h3');
    await w.setProps({ users: hosts.slice(0, 2) });
    expect((selector.element as HTMLSelectElement).value).toBe('h1');
    expect(w.find('form[aria-label="Rattacher les fiches de Compte h1"]').exists()).toBe(true);
  });
  it('masque création et modification si roles.attribuer manque sans supprimer la réinitialisation', () => {
    const index = permissions.indexOf('roles.attribuer'); permissions.splice(index, 1);
    const w = mount(Accounts, { props });
    expect(w.find('#creer-compte').exists()).toBe(false);
    expect(w.findAll('tbody button').some(b => b.text().startsWith('Modifier'))).toBe(false);
    expect(w.findAll('tbody button').some(b => b.text().startsWith('Mot de passe'))).toBe(true);
    permissions.push('roles.attribuer');
  });
});
describe('actions sur un compte', () => {
  const row = () => mount(AccountRow, { props: { user, roles: props.roles, canChangeRoles: true, canResetTwoFactor: false, challenge: null } });
  it('demande une confirmation avant de désactiver et transmet les données au contrat existant', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const w = row();
    await w.findAll('button')[0].trigger('click');
    await w.get('input[type=checkbox]').setValue(false);
    await w.get('form').trigger('submit');
    expect(put).not.toHaveBeenCalled();
    confirm.mockReturnValue(true);
    await w.get('form').trigger('submit');
    expect(put).toHaveBeenCalledWith('/administration/comptes/a1', expect.any(Object));
  });
  it('conserve validation du mot de passe et ne propose pas le 2FA lorsque interdit', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const w = row();
    expect(w.text()).not.toContain('Réinitialiser 2FA');
    await w.findAll('button')[1].trigger('click');
    expect(w.get('input[type=password]').attributes('minlength')).toBe('12');
    await w.get('form').trigger('submit');
    expect(put).toHaveBeenCalledWith('/administration/comptes/a1/mot-de-passe', expect.any(Object));
  });
});
