import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SiteModeNotice from '../../resources/js/components/poste/SiteModeNotice.vue';
import type { PosteRepository } from '../../resources/js/poste/repository';
import { ApiError } from '../../resources/js/poste/types';

function repo(over: Partial<PosteRepository> = {}): PosteRepository {
  return { activities: vi.fn(async () => [{ id: 'e1', name: 'Formation fictive', starts_at: '2027-01-01T08:00:00Z', ends_at: '2027-01-02T18:00:00Z', current: false }]), switchActivity: vi.fn(async () => undefined), ...over } as unknown as PosteRepository;
}

describe('écran du mode site', () => {
  const reload = vi.fn();
  beforeEach(() => { vi.stubGlobal('location', { ...window.location, reload }); reload.mockClear(); });
  afterEach(() => { vi.unstubAllGlobals(); });

  it('explique le mode site et ouvre le contrôle des cartes', async () => {
    const w = mount(SiteModeNotice, { props: { repository: repo(), online: true } });
    await flushPromises();
    expect(w.get('[data-testid="mode-site"]').text()).toContain('aucune activité en cours');
    expect(w.get('[data-testid="ouvrir-controle"]').attributes('href')).toBe('/controle');
  });

  it('propose de choisir une activité en ligne, puis recharge la tablette', async () => {
    const r = repo();
    const w = mount(SiteModeNotice, { props: { repository: r, online: true } });
    await flushPromises();
    expect((w.get('[data-testid="valider-activite-site"]').element as HTMLButtonElement).disabled).toBe(true);
    await w.get('[data-testid="activite-site"]').setValue('e1');
    await w.get('[data-testid="valider-activite-site"]').trigger('click');
    await flushPromises();
    expect(r.switchActivity).toHaveBeenCalledWith('e1');
    expect(reload).toHaveBeenCalled();
  });

  it('ne propose aucune activité hors ligne ou sans activité autorisée, et affiche l’erreur d’un changement refusé', async () => {
    const offline = mount(SiteModeNotice, { props: { repository: repo(), online: false } });
    await flushPromises();
    expect(offline.find('[data-testid="choix-activite-site"]').exists()).toBe(false);
    const none = mount(SiteModeNotice, { props: { repository: repo({ activities: vi.fn(async () => []) }), online: true } });
    await flushPromises();
    expect(none.find('[data-testid="choix-activite-site"]').exists()).toBe(false);
    const failing = mount(SiteModeNotice, { props: { repository: repo({ switchActivity: vi.fn(async () => { throw new ApiError('reseau', 'Changer d’activité demande le réseau.'); }) }), online: true } });
    await flushPromises();
    await failing.get('[data-testid="activite-site"]').setValue('e1');
    await failing.get('[data-testid="valider-activite-site"]').trigger('click');
    await flushPromises();
    expect(failing.get('[role="alert"]').text()).toContain('réseau');
    expect(reload).not.toHaveBeenCalled();
  });
});
