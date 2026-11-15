import 'fake-indexeddb/auto';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PosteGate from '../../resources/js/components/poste/PosteGate.vue';
import { PosteRuntime } from '../../resources/js/poste/local/runtime';
import { PosteStore } from '../../resources/js/poste/local/store';
import { randomBytes } from '../../resources/js/poste/local/crypto';
import type { Snapshot } from '../../resources/js/poste/local/list';
import { LegacyVault } from './support/legacy-vault';
import { addPending, leaseOf, leaseServer, newSecret } from './support/lease';

vi.mock('../../resources/js/components/poste/PosteApp.vue', () => ({ default: { template: '<div data-testid="app">poste ouvert</div>' } }));

const PIN = '482915';
const snapshot: Snapshot = {
  version: 'v1', server_time: new Date().toISOString(), poste: { id: 'p1', name: 'Tablette A', station: 'Poste A', event_id: 'e1', point_code: 'poste_2', capabilities: ['accueil', 'scan', 'sortie'] }, event: { id: 'e1', name: 'Événement fictif', starts_at: new Date(Date.now() - 3_600_000).toISOString(), ends_at: new Date(Date.now() + 86_400_000).toISOString() } as never,
  settings: { offline_trust_hours: 12, inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [], zones: [], category_zone: [], hosts: [], postes: [], visitors: [], badges: [],
} as never;

/** Tablette enrôlée sans PIN : coffre enveloppé par le bail, bail conservé, puis fermée (comme après un redémarrage). */
async function enrolled(secret = newSecret(), fetcher?: typeof fetch, hours = 12, pending = 0): Promise<PosteRuntime> {
  const runtime = new PosteRuntime(undefined, { dbName: `gate-${Math.random()}`, iterations: 1000, fetcher });
  await runtime.vault.enrolLease({ token: 'd'.repeat(64), lease: leaseOf(secret, hours), agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: snapshot.event?.ends_at ?? null, settings: snapshot.settings });
  await new PosteStore(runtime.vault).saveSnapshot(snapshot);
  await addPending(runtime.store, pending);
  runtime.lock();
  return runtime;
}

describe('ouverture automatique, sans PIN', () => {
  afterEach(() => { vi.useRealTimers(); });

  it('ouvre la tablette toute seule avec le bail conservé : aucun écran de PIN, aucune saisie', async () => {
    const runtime = await enrolled();
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="app"]').exists()).toBe(true));
    expect(wrapper.find('[data-testid="ecran-verrou"]').exists()).toBe(false);
    expect(wrapper.find('input[type="password"]').exists()).toBe(false);
    expect(runtime.vault.unlocked).toBe(true);
    wrapper.unmount();
  });

  it('demande un nouveau bail au serveur quand le bail conservé est échu (en ligne), puis s’ouvre sans saisie', async () => {
    const secret = newSecret();
    const server = leaseServer(secret);
    const runtime = await enrolled(secret, server.fetcher, -1); // bail déjà échu
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="app"]').exists()).toBe(true));
    expect(server.state.calls).toBe(1);
    expect(await runtime.vault.storedLease()).not.toBeNull(); // le nouveau bail est conservé
    wrapper.unmount();
  });

  it('attend le réseau quand le bail est échu et qu’il n’y en a pas : écran clair, rien n’est perdu, ouverture automatique au retour du réseau', async () => {
    const secret = newSecret();
    const server = leaseServer(secret, { online: false, calls: 0 });
    const runtime = await enrolled(secret, server.fetcher, -1, 1);
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-ferme"]').exists()).toBe(true));
    expect(wrapper.text()).toContain('Connexion au serveur nécessaire');
    expect(wrapper.find('[data-testid="app"]').exists()).toBe(false);
    expect(await runtime.db.ops.count()).toBe(1); // rien n'est effacé
    server.state.online = true;
    window.dispatchEvent(new Event('online'));
    await vi.waitFor(() => expect(wrapper.find('[data-testid="app"]').exists()).toBe(true));
    expect(await runtime.store.count('pending')).toBe(1);
    wrapper.unmount();
  });

  it('refuse l’ancien coffre d’un agent recréé avec le même email mais un nouvel identifiant', async () => {
    const runtime = await enrolled();
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u2', name: 'Nouvel agent' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-enrolement"]').exists()).toBe(true));
    expect(wrapper.text()).toContain('Le compte actuellement connecté n’est plus celui');
    expect(await runtime.db.meta.count()).toBe(0);
    expect(await runtime.db.chunks.count()).toBe(0);
    expect(runtime.vault.unlocked).toBe(false);
    wrapper.unmount();
  });

  it('propose une remise à zéro locale confirmée depuis l’écran fermé, sans supprimer le nouveau poste sur le serveur', async () => {
    const server = leaseServer(newSecret(), { online: false, calls: 0 });
    const runtime = await enrolled(newSecret(), server.fetcher, -1);
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-ferme"]').exists()).toBe(true));
    await wrapper.get('[data-testid="reinitialiser-tablette-verrouillee"]').trigger('click');
    await vi.waitFor(() => expect(wrapper.find('[role="dialog"]').exists()).toBe(true));
    expect(wrapper.text()).toContain('Seules les données enregistrées dans ce navigateur');
    await wrapper.get('[data-testid="confirmer-reset-tablette"]').trigger('click');
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-enrolement"]').exists()).toBe(true));
    expect(await runtime.db.meta.count()).toBe(0);
    wrapper.unmount();
  });

  it('l’écran d’enrôlement ne demande plus aucun PIN', async () => {
    const runtime = new PosteRuntime(undefined, { dbName: `gate-${Math.random()}`, iterations: 1000 });
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-enrolement"]').exists()).toBe(true));
    expect(wrapper.find('[data-testid="code-enrolement"]').exists()).toBe(true);
    expect(wrapper.find('input[type="password"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="pin"]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('migration unique d’une tablette enrôlée avant la suppression du PIN', () => {
  async function legacy(maxFailures = 15, pending = 2) {
    const secret = newSecret();
    const server = leaseServer(secret);
    const runtime = new PosteRuntime(undefined, { dbName: `legacy-${Math.random()}`, iterations: 1000, fetcher: server.fetcher });
    const old = new LegacyVault(runtime.db, { iterations: 1000 });
    await old.enrolLegacy({ token: 'd'.repeat(64), secret: Buffer.from(randomBytes(32)).toString('base64'), pin: PIN, agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: snapshot.event?.ends_at ?? null, settings: { ...snapshot.settings, pin_max_failures: maxFailures } });
    await new PosteStore(old).saveSnapshot(snapshot);
    await addPending(new PosteStore(old), pending);
    old.lock();
    return { runtime, server };
  }

  it('demande le PIN une dernière fois, ouvre sans rien perdre, puis ne le demande plus jamais', async () => {
    const { runtime } = await legacy();
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-migration"]').exists()).toBe(true));
    expect(wrapper.text()).toContain('Une dernière fois');
    await wrapper.get('[data-testid="pin-migration"]').setValue('111222'); // mauvais PIN : refusé, rien n'est perdu
    await wrapper.get('form').trigger('submit');
    await vi.waitFor(() => expect(wrapper.find('[data-testid="erreur-gate"]').exists()).toBe(true));
    expect(wrapper.find('[data-testid="app"]').exists()).toBe(false);
    await wrapper.get('[data-testid="pin-migration"]').setValue(PIN);
    await wrapper.get('form').trigger('submit');
    await vi.waitFor(() => expect(wrapper.find('[data-testid="app"]').exists()).toBe(true));
    expect(await runtime.store.count('pending')).toBe(2); // la file d'opérations est intacte
    expect(await runtime.db.ops.count()).toBe(2);
    expect(await runtime.vault.agentState()).toBe('deverrouille');
    expect(await runtime.db.meta.get('serverSecret')).toBeUndefined(); // l'ancienne enveloppe est supprimée
    expect(await runtime.db.chunks.get('failures')).toBeUndefined();
    wrapper.unmount();
    runtime.lock();
    expect(await runtime.vault.agentState()).toBe('ferme'); // désormais un coffre sans PIN
    const again = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(again.find('[data-testid="app"]').exists()).toBe(true));
    expect(again.find('[data-testid="ecran-migration"]').exists()).toBe(false);
    again.unmount();
  });

  it('efface après trop de PIN faux pendant la migration (seuil réglé à 3), comme avant', async () => {
    const { runtime } = await legacy(3, 0);
    const wrapper = mount(PosteGate, { props: { agent: { id: 'u1', name: 'Agent fictif' }, runtime } });
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-migration"]').exists()).toBe(true));
    for (let i = 0; i < 3; i++) {
      await wrapper.get('[data-testid="pin-migration"]').setValue('999111');
      await wrapper.get('form').trigger('submit');
      await flushPromises();
      if (i < 2) await vi.waitFor(() => expect(wrapper.get('[data-testid="migrer"]').attributes('disabled')).toBeUndefined());
    }
    await vi.waitFor(() => expect(wrapper.find('[data-testid="ecran-enrolement"]').exists()).toBe(true));
    expect(wrapper.get('[data-testid="message-efface"]').text()).toContain('effacées par sécurité');
    expect(await runtime.db.chunks.count() + await runtime.db.meta.count() + await runtime.db.ops.count()).toBe(0);
    wrapper.unmount();
  });
});
