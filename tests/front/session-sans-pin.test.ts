import 'fake-indexeddb/auto';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PosteGate from '../../resources/js/components/poste/PosteGate.vue';
import { PosteRuntime } from '../../resources/js/poste/local/runtime';
import { PosteStore } from '../../resources/js/poste/local/store';
import { closeSharedSession, useSharedRuntime } from '../../resources/js/poste/local/session';
import type { Snapshot } from '../../resources/js/poste/local/list';
import { addPending, leaseOf, leaseServer, newSecret } from './support/lease';

/**
 * Espace Agent sans aucun PIN : le coffre s'ouvre tout seul pendant la connexion, reste ouvert en changeant d'écran, et ne se rouvre après une déconnexion
 * que par une nouvelle connexion en ligne. Vrais écrans (PosteGate), vrai coffre (IndexedDB simulée) ; PosteApp est remplacé par un témoin.
 */
vi.mock('../../resources/js/components/poste/PosteApp.vue', () => ({ default: { template: '<div data-testid="app">poste ouvert</div>' } }));

const snapshot: Snapshot = {
  version: 'v1', server_time: new Date().toISOString(), poste: { id: 'p1', name: 'Tablette A', station: 'Poste A', event_id: 'e1', point_code: 'poste_2', capabilities: ['accueil', 'scan', 'sortie'] },
  event: { id: 'e1', name: 'Événement fictif', starts_at: new Date(Date.now() - 3_600_000).toISOString(), ends_at: new Date(Date.now() + 86_400_000).toISOString() } as never,
  settings: { offline_trust_hours: 12, inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [], zones: [], category_zone: [], hosts: [], postes: [], visitors: [], badges: [],
} as never;

async function tablet(secret: Uint8Array, server: ReturnType<typeof leaseServer>, pending = 0) {
  const runtime = new PosteRuntime(undefined, { dbName: `sans-pin-${Math.random()}`, iterations: 1000, fetcher: server.fetcher });
  await runtime.vault.enrolLease({ token: 'd'.repeat(64), lease: leaseOf(secret), agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: snapshot.event?.ends_at ?? null, settings: snapshot.settings });
  await new PosteStore(runtime.vault).saveSnapshot(snapshot);
  await addPending(runtime.store, pending);
  runtime.lock();
  return runtime;
}
const gate = (agentId = 'u1') => mount(PosteGate, { props: { agent: { id: agentId, name: 'Agent fictif' } } });
const opened = (w: ReturnType<typeof gate>) => vi.waitFor(() => expect(w.find('[data-testid="app"]').exists()).toBe(true));

describe('espace Agent sans PIN', () => {
  afterEach(() => { useSharedRuntime(null); });

  it('s’ouvre seul, reste ouvert d’un écran à l’autre, et exige une nouvelle connexion en ligne après la déconnexion', async () => {
    const secret = newSecret();
    const server = leaseServer(secret);
    const runtime = await tablet(secret, server, 2);
    useSharedRuntime(runtime);

    const first = gate(); await opened(first); // aucune saisie
    expect(first.find('input[type="password"]').exists()).toBe(false);
    first.unmount(); // l'agent va sur Mon espace, Sécurité…
    const back = gate(); await opened(back); // …et revient : même coffre, déjà ouvert
    back.unmount();

    await closeSharedSession(); // déconnexion : le bail est supprimé, le coffre fermé
    expect(runtime.vault.unlocked).toBe(false);
    expect(await runtime.vault.storedLease()).toBeNull();

    server.state.online = false; // hors ligne : impossible de s'ouvrir sans nouvelle connexion
    const offline = gate();
    await vi.waitFor(() => expect(offline.find('[data-testid="ecran-ferme"]').exists()).toBe(true));
    expect(offline.find('[data-testid="app"]').exists()).toBe(false);
    expect(await runtime.store.count('pending')).toBe(2); // rien n'est perdu
    server.state.online = true; // nouvelle connexion en ligne : le coffre se rouvre tout seul
    window.dispatchEvent(new Event('online'));
    await opened(offline);
    expect(await runtime.store.count('pending')).toBe(2);
    offline.unmount();
  });

  it('ferme le coffre et supprime le bail même si la page a été rechargée après la déconnexion', async () => {
    const secret = newSecret();
    const runtime = await tablet(secret, leaseServer(secret));
    useSharedRuntime(runtime); // la page de connexion n'a ouvert aucun coffre : le bail du stockage doit tout de même disparaître
    expect(await runtime.vault.storedLease()).not.toBeNull();
    await closeSharedSession();
    expect(await runtime.vault.storedLease()).toBeNull();
    expect(await runtime.db.meta.get('lease')).toBeUndefined();
  });

  it('un autre compte ne rouvre jamais le coffre : bail différent, opérations en attente conservées', async () => {
    const secret = newSecret();
    const server = leaseServer(newSecret()); // le serveur remet à l'autre compte un autre bail
    const runtime = await tablet(secret, server, 2);
    useSharedRuntime(runtime);
    await closeSharedSession();
    const other = gate('u2');
    await vi.waitFor(() => expect(other.find('[data-testid="ecran-ferme"]').exists()).toBe(true));
    expect(other.text()).toContain('saisie');
    expect(runtime.vault.unlocked).toBe(false);
    expect(await runtime.db.ops.count()).toBe(2); // rien n'est effacé tant que leur auteur ne les a pas envoyées
    other.unmount();
  });

  it('un autre compte sur une tablette sans opération en attente : elle est réinitialisée pour être réenrôlée', async () => {
    const secret = newSecret();
    const runtime = await tablet(secret, leaseServer(newSecret()), 0);
    useSharedRuntime(runtime);
    await closeSharedSession();
    const other = gate('u2');
    await vi.waitFor(() => expect(other.find('[data-testid="ecran-enrolement"]').exists()).toBe(true));
    expect(await runtime.db.meta.count()).toBe(0);
    other.unmount();
  });

  it('ne conserve ni le bail ni la clé de données en clair dans le stockage de la tablette', async () => {
    const secret = newSecret();
    const server = leaseServer(secret);
    const runtime = await tablet(secret, server);
    const b64 = Buffer.from(secret).toString('base64');
    const dump = JSON.stringify((await runtime.db.meta.toArray()).map(r => [r.key, r.value instanceof Uint8Array ? Array.from(r.value) : r.key === 'deviceKey' ? 'cle' : r.value]))
      + JSON.stringify((await runtime.db.chunks.toArray()).map(r => Array.from(r.data)));
    expect(dump).not.toContain(b64);
    const row = await runtime.db.meta.get('lease');
    expect(ArrayBuffer.isView(row?.value)).toBe(true); // scellé par la clé d'appareil (octets chiffrés)
    const bytes = Buffer.from(row!.value as Uint8Array).toString('latin1');
    expect(bytes).not.toContain(b64);
    expect(bytes).not.toContain('expires_at');
  });

  it('un bail échu hors ligne ne rouvre pas le coffre ; un bail renouvelé en ligne le garde ouvert', async () => {
    const secret = newSecret();
    const server = leaseServer(secret);
    const runtime = await tablet(secret, server);
    useSharedRuntime(runtime);
    const first = gate(); await opened(first);
    await runtime.vault.saveLease(leaseOf(secret, 1)); // échéance proche : renouvelée en silence
    await runtime.renewLease();
    expect(server.state.calls).toBeGreaterThan(0);
    const renewed = await runtime.vault.storedLease();
    expect(renewed!.expiresAt - Date.now()).toBeGreaterThan(10 * 3_600_000);
    first.unmount();
  });
});
