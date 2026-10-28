import 'fake-indexeddb/auto';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PosteGate from '../../resources/js/components/poste/PosteGate.vue';
import { PosteRuntime } from '../../resources/js/poste/local/runtime';
import { PosteStore } from '../../resources/js/poste/local/store';
import { randomBytes, toB64 } from '../../resources/js/poste/local/crypto';
import type { Snapshot } from '../../resources/js/poste/local/list';

vi.mock('../../resources/js/components/poste/PosteApp.vue', () => ({ default: { template: '<div data-testid="app">poste ouvert</div>' } }));

const PIN = '482915';
const snapshot: Snapshot = {
  version: 'v1', server_time: new Date().toISOString(), poste: { id: 'p1', name: 'Tablette A', station: 'Poste A', event_id: 'e1' }, event: { id: 'e1', name: 'Événement fictif', starts_at: new Date(Date.now() - 3_600_000).toISOString(), ends_at: new Date(Date.now() + 86_400_000).toISOString() },
  settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [], zones: [], category_zone: [], hosts: [], postes: [], visitors: [], badges: [],
};

async function enrolled(): Promise<PosteRuntime> {
  const runtime = new PosteRuntime(undefined, { dbName: `gate-${Math.random()}`, iterations: 1000 });
  await runtime.vault.enrol({ token: 'd'.repeat(64), secret: toB64(randomBytes(32)), pin: PIN, agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: snapshot.event.ends_at, settings: snapshot.settings });
  await new PosteStore(runtime.vault).saveSnapshot(snapshot);
  runtime.lock();
  return runtime;
}

describe('écran de déverrouillage', () => {
  afterEach(() => { vi.useRealTimers(); });

  it('se verrouille seul après l’inactivité réglée, et un geste de l’agent repousse l’échéance', async () => {
    const runtime = await enrolled();
    const wrapper = mount(PosteGate, { props: { agent: null, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-verrou"]').exists()).toBe(true));
    await wrapper.get('[data-testid="pin-deverrouillage"]').setValue(PIN);
    await wrapper.get('form').trigger('submit');
    await vi.waitFor(() => expect(wrapper.find('[data-testid="app"]').exists()).toBe(true));
    vi.useFakeTimers();
    window.dispatchEvent(new Event('pointerdown')); // l'agent touche l'écran : la minuterie repart
    await vi.advanceTimersByTimeAsync(4 * 60_000);
    expect(wrapper.find('[data-testid="app"]').exists()).toBe(true);
    window.dispatchEvent(new Event('pointerdown'));
    await vi.advanceTimersByTimeAsync(4 * 60_000);
    expect(wrapper.find('[data-testid="app"]').exists()).toBe(true);
    await vi.advanceTimersByTimeAsync(61_000);
    await flushPromises();
    expect(wrapper.find('[data-testid="app"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="ecran-verrou"]').exists()).toBe(true);
    expect(runtime.vault.unlocked).toBe(false); // la clé a quitté la mémoire
    wrapper.unmount();
  });

  it('efface et redemande un enrôlement après trop de PIN faux (seuil réglé à 3)', async () => {
    const runtime = await enrolled();
    await runtime.db.meta.put({ key: 'settings', value: { ...snapshot.settings, pin_max_failures: 3 } });
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-verrou"]').exists()).toBe(true));
    for (let i = 0; i < 3; i++) {
      await wrapper.get('[data-testid="pin-deverrouillage"]').setValue('999111');
      await wrapper.get('form').trigger('submit');
      await flushPromises();
      if (i < 2) await vi.waitFor(() => expect(wrapper.get('[data-testid="deverrouiller"]').attributes('disabled')).toBeUndefined());
    }
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-enrolement"]').exists()).toBe(true));
    expect(wrapper.get('[data-testid="message-efface"]').text()).toContain('effacées par sécurité');
    expect(await runtime.db.chunks.count() + await runtime.db.meta.count() + await runtime.db.ops.count()).toBe(0);
    wrapper.unmount();
  });
});
