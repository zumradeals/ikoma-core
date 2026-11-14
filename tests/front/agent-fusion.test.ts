import 'fake-indexeddb/auto';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PosteGate from '../../resources/js/components/poste/PosteGate.vue';
import { PosteRuntime } from '../../resources/js/poste/local/runtime';
import { PosteStore } from '../../resources/js/poste/local/store';
import { randomBytes } from '../../resources/js/poste/local/crypto';
import { leaseOf } from './support/lease';
import type { Snapshot } from '../../resources/js/poste/local/list';

/**
 * Test de faisabilité de la fusion (poste d'accueil, contrôle, groupes dans un seul écran déverrouillé).
 * Vrais composants PosteApp, ControleApp et GroupeApp ; vrai coffre et vraie copie locale (IndexedDB simulée) ; le réseau est coupé pendant tout le test.
 */
vi.mock('@inertiajs/vue3', () => ({
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  usePage: () => ({ props: { auth: { name: 'Agent fictif', permissions: ['visiteurs.rechercher', 'badges.controler', 'badges.associer', 'groupes.appel_nominal'] }, f06Active: true, institution: { custom: false, name: 'IKOMA ACCESS', fullName: 'IKOMA ACCESS' } } }),
}));
vi.mock('../../resources/js/poste/media', () => ({
  PHOTO_MAX_SIDE: 640, openCamera: vi.fn(), closeCamera: vi.fn(), captureJpeg: vi.fn(),
  startScan: vi.fn(async () => ({ stop: vi.fn() })),
}));

const snapshot: Snapshot = {
  version: 'v1', server_time: new Date().toISOString(), poste: { id: 'p1', name: 'Tablette A', station: 'Poste A', event_id: 'e1', point_code: 'poste_2', capabilities: ['accueil', 'scan', 'sortie'] },
  event: { id: 'e1', name: 'Événement fictif', starts_at: new Date(Date.now() - 3_600_000).toISOString(), ends_at: new Date(Date.now() + 86_400_000).toISOString() } as never,
  settings: { offline_trust_hours: 12, inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [], zones: [], category_zone: [], hosts: [], postes: [], visitors: [], badges: [],
} as never;

async function enrolled(): Promise<PosteRuntime> {
  const runtime = new PosteRuntime(undefined, { dbName: `fusion-${Math.random()}`, iterations: 1000 });
  await runtime.vault.enrolLease({ token: 'd'.repeat(64), lease: leaseOf(randomBytes(32)), agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: snapshot.event?.ends_at ?? null, settings: snapshot.settings });
  await new PosteStore(runtime.vault).saveSnapshot(snapshot);
  runtime.lock();
  return runtime;
}

describe('fusion : une seule page déverrouillée, trois vues', () => {
  afterEach(() => { vi.restoreAllMocks(); });

  it('passe de l’accueil au contrôle, aux groupes et retour, sans réseau, sans recharger la page et sans aucun PIN', async () => {
    vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false);
    const runtime = await enrolled();
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    // ouverture automatique : aucun PIN, même sans réseau (bail conservé sur la tablette)
    await vi.waitFor(() => expect(wrapper.find('[data-testid="agent-layout"]').exists()).toBe(true));
    expect(wrapper.find('[data-testid="ecran-verrou"]').exists()).toBe(false);
    expect(wrapper.get('[data-testid="agent-layout"]').attributes('data-active')).toBe('poste');
    const repo = runtime.repo;
    expect(repo).toBeTruthy();

    // Accueil → contrôle : le menu change la vue par état, même hors ligne (aucun lien de page).
    await wrapper.get('[data-testid="agent-nav-controle"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="agent-layout"]').attributes('data-active')).toBe('controle');
    expect(runtime.vault.unlocked).toBe(true);
    expect(runtime.repo).toBe(repo); // même coffre, même copie, même file de synchronisation

    // Contrôle → accueil : retour sans perte.
    await wrapper.get('[data-testid="agent-nav-poste"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="agent-layout"]').attributes('data-active')).toBe('poste');
    expect(runtime.vault.unlocked).toBe(true);

    // Depuis « Autres opérations » : l'entrée « Enregistrer une sortie » ouvre le contrôle directement en mode sortie.
    const sortie = wrapper.find('[data-testid="operation-sortie"]');
    if (sortie.exists()) {
      await sortie.trigger('click');
      await flushPromises();
      expect(wrapper.get('[data-testid="agent-layout"]').attributes('data-active')).toBe('controle');
      // L'écran de contrôle s'ouvre directement sur le poste de la tablette (plus de choix du point) et dans le mode demandé.
      expect(wrapper.text()).not.toContain('Où effectuez-vous les contrôles');
      expect(wrapper.get('[data-testid="point"]').text()).toBe('Poste A');
      expect(wrapper.get('[data-testid="mode-sortie"]').attributes('aria-pressed')).toBe('true');
    }

    // Retour puis groupes : même coffre, même file.
    await wrapper.get('[data-testid="agent-nav-poste"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="agent-nav-groupe"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="agent-layout"]').attributes('data-active')).toBe('groupe');
    expect(runtime.vault.unlocked).toBe(true);
    expect(runtime.repo).toBe(repo);
    wrapper.unmount();
  });
});
