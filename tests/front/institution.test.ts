import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import BrandName from '../../resources/js/components/BrandName.vue';
import BrandLogo from '../../resources/js/components/BrandLogo.vue';
import InstitutionHome from '../../resources/js/components/InstitutionHome.vue';
import { STANDARD_INSTITUTION, type Institution } from '../../resources/js/composables/useInstitution';

const page = reactive<{ props: { institution?: Institution } }>({ props: {} });
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, Head: { template: '<div><slot /></div>' } }));

const ailct: Institution = {
  custom: true, name: 'AILCT', fullName: 'Académie Internationale de Lutte Contre le Terrorisme', tagline: '', logoUrl: '/institution/logo?v=1',
  homeTitle: 'Plateforme AILCT', homeDescription: 'Accueil sécurisé.', loginGreeting: 'Bienvenue à AILCT', footer: 'AILCT — usage interne',
  primary: '#0C2D66', secondary: '#1F5FBF', accent: '#D6B46B',
};

describe('identité institutionnelle', () => {
  beforeEach(() => { page.props = {}; });

  it('garde IKOMA ACCESS avec sa mise en forme d’origine quand rien n’est configuré', () => {
    const host = document.createElement('div');
    mount(BrandName, { props: { variant: 'strong' }, attachTo: host });
    expect(host.textContent).toBe('IKOMA ACCESS');
    expect(host.querySelector('strong')?.textContent).toBe('IKOMA');
    const accent = document.createElement('div');
    mount(BrandName, { props: { variant: 'accent' }, attachTo: accent });
    expect(accent.textContent).toBe('IKOMA ACCESS');
    expect(accent.querySelector('span')?.textContent).toBe('ACCESS');
    expect(mount(BrandLogo).find('img').attributes('src')).toBe('/brand/ikoma-access-icon.svg');
    page.props = { institution: STANDARD_INSTITUTION };
    const standard = document.createElement('div');
    mount(BrandName, { attachTo: standard });
    expect(standard.textContent).toBe('IKOMA ACCESS');
  });

  it('affiche le nom et le logo de l’institution quand ils sont configurés', () => {
    page.props = { institution: ailct };
    expect(mount(BrandName, { props: { variant: 'strong' } }).text()).toBe('AILCT');
    expect(mount(BrandName, { props: { variant: 'accent' } }).text()).toBe('AILCT');
    expect(mount(BrandLogo).find('img').attributes('src')).toBe('/institution/logo?v=1');
  });

  it('présente une page d’accueil sobre, sans argumentaire commercial ni mention IKOMA', () => {
    page.props = { institution: ailct };
    const home = mount(InstitutionHome);
    expect(home.text()).toContain('Plateforme AILCT');
    expect(home.text()).toContain('Accueil sécurisé.');
    expect(home.text()).toContain('AILCT — usage interne');
    expect(home.text()).not.toMatch(/IKOMA/i);
    expect(home.find('a[href="/login"]').exists()).toBe(true);
  });
});
