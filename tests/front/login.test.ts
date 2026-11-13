import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { Institution } from '../../resources/js/composables/useInstitution';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
const page = reactive<{ props: { institution?: Institution; flash?: { status?: string | null } } }>({ props: {} });
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => page,
  Head: { template: '<div><slot /></div>' },
  useForm: (data: object) => reactive({ ...data, errors: {} as Record<string, string>, processing: false, post, reset: vi.fn() }),
}));

import Login from '../../resources/js/pages/Auth/Login.vue';

const ailct: Institution = {
  custom: true, name: 'AILCT', fullName: 'Académie Internationale de Lutte Contre le Terrorisme', tagline: '', logoUrl: '/institution/logo?v=1',
  homeTitle: '', homeDescription: '', loginGreeting: 'Bienvenue à AILCT', footer: '', primary: '#0B3C6B', secondary: '#0F5AA6', accent: '#D4A72C',
};

describe('page de connexion institutionnelle', () => {
  beforeEach(() => { page.props = { institution: ailct }; post.mockClear(); });

  it('reprend la composition validée : bandeau, identité, carte de connexion, encadré et pied de page', () => {
    const wrapper = mount(Login);
    expect(wrapper.get('[data-testid="bandeau-connexion"]').text()).toContain('Accès sécurisé');
    expect(wrapper.get('[data-testid="bandeau-connexion"]').text()).toContain('AILCT');
    expect(wrapper.get('aside').text()).toContain('Académie Internationale de Lutte Contre le Terrorisme');
    expect(wrapper.get('h1').text()).toBe('Connexion sécurisée');
    expect(wrapper.get('[data-testid="accroche-connexion"]').text()).toBe('Bienvenue à AILCT'); // accroche configurée dans l’identité
    expect(wrapper.text()).toContain('Accès réservé aux utilisateurs habilités.');
    expect(wrapper.text()).toContain('Accès nominatif et sécurisé. Les comptes sont fournis par votre administrateur.');
    expect(wrapper.get('footer').text()).toContain('Besoin d’aide pour votre compte ? Contactez l’administrateur de votre organisation.');
    expect(wrapper.get('footer').text()).toContain('© AILCT – Tous droits réservés.');
    expect(wrapper.get('button[type="submit"]').text()).toContain('Se connecter');
  });

  it('montre l’identité institutionnelle aussi sur téléphone (bandeau non masqué)', () => {
    const aside = mount(Login).get('aside');
    expect(aside.classes()).not.toContain('hidden');
    expect(aside.classes()).toContain('flex-col');
  });

  it('garde les champs, la validation native et la visibilité du mot de passe', async () => {
    const wrapper = mount(Login);
    const email = wrapper.get('input[type="email"]');
    expect(email.attributes('autocomplete')).toBe('username');
    expect(email.attributes('required')).toBeDefined();
    const password = wrapper.get('#ikoma-password');
    expect(password.attributes('type')).toBe('password');
    expect(password.attributes('autocomplete')).toBe('current-password');
    await wrapper.get('button[aria-label="Afficher le mot de passe"]').trigger('click');
    expect(wrapper.get('#ikoma-password').attributes('type')).toBe('text');
    expect(wrapper.get('button[aria-label="Masquer le mot de passe"]').text()).toBe('Masquer');
  });

  it('envoie le formulaire par la même route et affiche le message de statut', async () => {
    page.props = { institution: ailct, flash: { status: 'Mot de passe mis à jour.' } };
    const wrapper = mount(Login);
    expect(wrapper.get('[data-testid="statut"]').text()).toBe('Mot de passe mis à jour.');
    await wrapper.get('form').trigger('submit');
    expect(post).toHaveBeenCalledWith('/login', expect.objectContaining({ onFinish: expect.any(Function) }));
  });

  it('garde l’identité IKOMA ACCESS quand aucune identité institutionnelle n’est enregistrée', () => {
    page.props = {};
    const wrapper = mount(Login);
    expect(wrapper.get('[data-testid="bandeau-connexion"]').text()).toContain('IKOMA ACCESS');
    expect(wrapper.get('footer').text()).toContain('© IKOMA ACCESS');
    expect(wrapper.get('h1').text()).toBe('Connexion sécurisée');
    expect(wrapper.get('[data-testid="accroche-connexion"]').text()).toBe('Bienvenue sur IKOMA ACCESS');
  });
});
