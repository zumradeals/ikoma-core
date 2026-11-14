import 'fake-indexeddb/auto';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PosteGate from '../../resources/js/components/poste/PosteGate.vue';
import { PosteRuntime } from '../../resources/js/poste/local/runtime';
import { PosteStore } from '../../resources/js/poste/local/store';
import { randomBytes, toB64 } from '../../resources/js/poste/local/crypto';
import { armIdleLock, lockSharedRuntime, useSharedRuntime } from '../../resources/js/poste/local/session';
import type { Snapshot } from '../../resources/js/poste/local/list';

vi.mock('../../resources/js/components/poste/PosteApp.vue', () => ({ default: { template: '<div data-testid="app">poste ouvert</div>' } }));

const PIN = '482915';
const snapshot: Snapshot = {
  version: 'v1', server_time: new Date().toISOString(), poste: { id: 'p1', name: 'Tablette A', station: 'Poste A', event_id: 'e1', point_code: 'poste_2', capabilities: ['accueil', 'scan', 'sortie'] },
  event: { id: 'e1', name: 'Événement fictif', starts_at: new Date(Date.now() - 3_600_000).toISOString(), ends_at: new Date(Date.now() + 86_400_000).toISOString() } as never,
  settings: { offline_trust_hours: 12, inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [], zones: [], category_zone: [], hosts: [], postes: [], visitors: [], badges: [],
} as never;

async function enrolled(): Promise<PosteRuntime> {
  const runtime = new PosteRuntime(undefined, { dbName: `une-fois-${Math.random()}`, iterations: 1000 });
  await runtime.vault.enrol({ token: 'd'.repeat(64), secret: toB64(randomBytes(32)), pin: PIN, agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: snapshot.event?.ends_at ?? null, settings: snapshot.settings });
  await new PosteStore(runtime.vault).saveSnapshot(snapshot);
  runtime.lock();
  return runtime;
}
const mountGate = (agentId: string) => mount(PosteGate, { props: { agent: { id: agentId, name: 'Agent fictif' } } });
async function unlockWith(wrapper: ReturnType<typeof mountGate>) {
  await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-verrou"]').exists()).toBe(true));
  await wrapper.get('[data-testid="pin-deverrouillage"]').setValue(PIN);
  await wrapper.get('form').trigger('submit');
  await vi.waitFor(() => expect(wrapper.find('[data-testid="app"]').exists()).toBe(true));
}

describe('le PIN est demandé une seule fois par connexion', () => {
  afterEach(() => { lockSharedRuntime(); useSharedRuntime(null); });

  it('ne le redemande pas quand l’agent change d’écran et revient, puis le redemande après la déconnexion', async () => {
    const runtime = await enrolled();
    useSharedRuntime(runtime);

    const first = mountGate('u1');
    await unlockWith(first); // une seule saisie du PIN
    first.unmount(); // l'agent va sur Mon espace, Sécurité, Groupes… : la page change sans recharger

    const back = mountGate('u1'); // …et revient : même coffre, déjà ouvert
    await vi.waitFor(() => expect(back.find('[data-testid="app"]').exists()).toBe(true));
    expect(back.find('[data-testid="ecran-verrou"]').exists()).toBe(false);
    expect(runtime.vault.unlocked).toBe(true);
    back.unmount();

    const again = mountGate('u1'); // plusieurs allers-retours
    await vi.waitFor(() => expect(again.find('[data-testid="app"]').exists()).toBe(true));
    expect(again.find('[data-testid="pin-deverrouillage"]').exists()).toBe(false);
    again.unmount();

    lockSharedRuntime(); // déconnexion (ou page de connexion)
    expect(runtime.vault.unlocked).toBe(false);
    const afterLogout = mountGate('u1');
    await vi.waitFor(() => expect(afterLogout.find('[data-testid="ecran-verrou"]').exists()).toBe(true));
    expect(afterLogout.find('[data-testid="app"]').exists()).toBe(false); // le PIN est exigé de nouveau
    afterLogout.unmount();
  });

  it('ne laisse jamais un autre compte profiter du coffre ouvert par le précédent', async () => {
    const runtime = await enrolled();
    useSharedRuntime(runtime);
    const first = mountGate('u1');
    await unlockWith(first);
    first.unmount();
    const other = mountGate('u2'); // un autre agent se connecte sans que le coffre ait été fermé
    await vi.waitFor(() => expect(other.find('[data-testid="ecran-verrou"]').exists()).toBe(true));
    expect(other.find('[data-testid="app"]').exists()).toBe(false);
    expect(runtime.vault.unlocked).toBe(false);
    other.unmount();
  });

  it('referme le coffre à l’inactivité même quand aucun écran du poste n’est affiché', async () => {
    const runtime = await enrolled();
    useSharedRuntime(runtime);
    const first = mountGate('u1');
    await unlockWith(first);
    first.unmount(); // l'agent est sur une autre page
    expect(runtime.vault.unlocked).toBe(true);
    vi.useFakeTimers();
    try {
      armIdleLock(5); // même minuterie que celle armée à l'ouverture (réglage « inactivité »), reprise sur l'horloge simulée
      await vi.advanceTimersByTimeAsync(4 * 60_000);
      expect(runtime.vault.unlocked).toBe(true);
      window.dispatchEvent(new Event('pointerdown')); // un geste de l'agent repousse l'échéance
      await vi.advanceTimersByTimeAsync(4 * 60_000);
      expect(runtime.vault.unlocked).toBe(true);
      await vi.advanceTimersByTimeAsync(61_000);
      await flushPromises();
      expect(runtime.vault.unlocked).toBe(false); // clé disparue de la mémoire
    } finally { vi.useRealTimers(); }
  });
});
