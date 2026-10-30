import 'fake-indexeddb/auto';
import { describe, expect, it, vi } from 'vitest';
import { createDeviceKey, createVault, fromB64, openJson, openVault, PBKDF2_ITERATIONS, randomBytes, sealJson, toB64 } from '../../resources/js/poste/local/crypto';
import { PosteDb } from '../../resources/js/poste/local/db';
import { PosteVault, PinError, pinProblem } from '../../resources/js/poste/local/vault';
import { LocalList, type Snapshot, type SnapVisitor } from '../../resources/js/poste/local/list';
import { applyOp, searchOf } from '../../resources/js/poste/local/apply';
import { PosteStore } from '../../resources/js/poste/local/store';
import { LocalPosteRepository } from '../../resources/js/poste/local/local';
import { SyncManager } from '../../resources/js/poste/local/sync';
import { PosteRuntime } from '../../resources/js/poste/local/runtime';
import { ApiError } from '../../resources/js/poste/types';

const ITER = 1000; // le coût réel (600 000) est vérifié séparément : ici seulement la logique
type Op = { id: string; seq: number; payload: Record<string, string> };
type Call = { url: string; body: { operations: Op[] } };
let n = 0;
const dbName = () => `test-${++n}-${Math.random()}`;
const SECRET = toB64(randomBytes(32));
const PIN = '482915';

const vip = { id: 'c0', name: 'VIP', color: '#C9962B', priority_circuit: true };
const std = { id: 'c1', name: 'Visiteur', color: '#0F5AA6', priority_circuit: false };
const now0 = Date.now();
const iso = (offsetMs: number) => new Date(now0 + offsetMs).toISOString();
function visitor(id: string, name: string, over: Partial<SnapVisitor> = {}): SnapVisitor {
  return { id, full_name: name, organisation: 'Organisation fictive', host_id: 'h1', category_id: 'c1', valid_from: iso(-3_600_000), valid_until: iso(86_400_000), source: 'listé', status: 'attendu', host_validation: null, rev: '2026-11-05T07:00:00.000000Z', identity_done: false, photo_done: false, validated: false, badge_code: null, ...over };
}
function snapshot(visitors: SnapVisitor[], badges = [{ code: 'B-001', token_hash: '', category_id: 'c1', status: 'Disponible', mine: true }, { code: 'B-002', token_hash: '', category_id: 'c1', status: 'Disponible', mine: true }, { code: 'B-900', token_hash: '', category_id: 'c0', status: 'Disponible', mine: true }]): Snapshot {
  return {
    version: 'v1', server_time: new Date(now0).toISOString(), poste: { id: 'p1', name: 'Tablette A', station: 'Poste fictif A', event_id: 'e1' }, event: { id: 'e1', name: 'Événement fictif', starts_at: iso(-3_600_000), ends_at: iso(86_400_000) },
    settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [vip, std], zones: [{ id: 'z1', name: 'Hall', est_accueil: true }, { id: 'z2', name: 'Salle', est_accueil: false }],
    category_zone: [{ category_id: 'c1', zone_id: 'z1' }, { category_id: 'c1', zone_id: 'z2' }, { category_id: 'c0', zone_id: 'z1' }], hosts: [{ id: 'h1', name: 'Hôte fictif', service: null }], postes: [{ id: 'p1', name: 'Poste fictif A' }], visitors, badges,
  };
}

describe('chiffrement', () => {
  it('impose 600 000 itérations PBKDF2', () => { expect(PBKDF2_ITERATIONS).toBe(600_000); });
  it('scelle et rouvre avec le bon PIN, refuse le mauvais, ne permet pas de lire sans la clé de l’appareil', async () => {
    const deviceKey = await createDeviceKey();
    expect(deviceKey.extractable).toBe(false);
    const { record, dek } = await createVault(PIN, fromB64(SECRET), deviceKey, ITER);
    const sealed = await sealJson(dek, { nom: 'Koné Mariam' }, 'x');
    const reopened = await openVault(PIN, record, fromB64(SECRET), deviceKey);
    expect(await openJson(reopened, sealed, 'x')).toEqual({ nom: 'Koné Mariam' });
    await expect(openVault('000111', record, fromB64(SECRET), deviceKey)).rejects.toBeDefined();
    await expect(openVault(PIN, record, fromB64(toB64(randomBytes(32))), deviceKey)).rejects.toBeDefined();
    await expect(openVault(PIN, record, fromB64(SECRET), await createDeviceKey())).rejects.toBeDefined();
    await expect(openJson(reopened, sealed, 'autre-contexte')).rejects.toBeDefined(); // un bloc ne peut pas être déplacé
    expect(new TextDecoder().decode(sealed)).not.toContain('Koné');
  });
  it('refuse les PIN faibles', () => {
    for (const weak of ['123456', '654321', '000000', '121212', '123123', '12345']) expect(pinProblem(weak), weak).not.toBeNull();
    expect(pinProblem(PIN)).toBeNull();
  });
});

describe('coffre et PIN', () => {
  async function enrolled(settings = { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }) {
    const clock = { t: 1_000_000 };
    const vault = new PosteVault(new PosteDb(dbName()), { iterations: ITER, now: () => clock.t });
    await vault.enrol({ token: 'a'.repeat(64), secret: SECRET, pin: PIN, agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: null, settings });
    return { vault, clock };
  }
  it('verrouille puis déverrouille avec le bon PIN', async () => {
    const { vault } = await enrolled();
    expect(vault.unlocked).toBe(true);
    vault.lock();
    expect(vault.key).toBeNull();
    await expect(vault.getSecret('deviceToken')).rejects.toBeInstanceOf(PinError);
    await vault.unlock(PIN);
    expect(await vault.getSecret('deviceToken')).toBe('a'.repeat(64));
  });
  it('compte les échecs, impose des attentes, puis efface tout après 15 échecs', async () => {
    const { vault, clock } = await enrolled();
    vault.lock();
    for (let i = 1; i <= 4; i++) await expect(vault.unlock('999111')).rejects.toMatchObject({ code: 'pin_incorrect' });
    await expect(vault.unlock('999111')).rejects.toMatchObject({ code: 'verrouille' }); // 5e échec : une minute d'attente
    await expect(vault.unlock(PIN)).rejects.toMatchObject({ code: 'verrouille' }); // même le bon PIN est refusé pendant l'attente
    clock.t += 61_000;
    for (let i = 6; i <= 9; i++) await expect(vault.unlock('999111')).rejects.toMatchObject({ code: 'pin_incorrect' });
    await expect(vault.unlock('999111')).rejects.toMatchObject({ code: 'verrouille' });
    clock.t += 301_000;
    for (let i = 11; i <= 14; i++) await expect(vault.unlock('999111')).rejects.toMatchObject({ code: 'pin_incorrect' });
    await expect(vault.unlock('999111')).rejects.toMatchObject({ code: 'efface' }); // 15e échec
    for (const table of [vault.db.meta, vault.db.chunks, vault.db.ops, vault.db.photos]) expect(await table.count()).toBe(0);
    expect(await vault.state()).toBe('vide');
  });
  it('un bon PIN remet le compteur à zéro', async () => {
    const { vault } = await enrolled();
    vault.lock();
    for (let i = 0; i < 3; i++) await expect(vault.unlock('999111')).rejects.toBeDefined();
    await vault.unlock(PIN); vault.lock();
    for (let i = 0; i < 4; i++) await expect(vault.unlock('999111')).rejects.toMatchObject({ code: 'pin_incorrect' });
  });
  it('efface si le compteur d’échecs a été supprimé pour gagner des essais', async () => {
    const { vault } = await enrolled();
    vault.lock();
    await vault.db.chunks.delete('failures');
    await expect(vault.unlock(PIN)).rejects.toMatchObject({ code: 'efface' });
    expect(await vault.db.meta.count()).toBe(0);
  });
});

describe('recherche locale', () => {
  const names = ['Koné Mariam', 'Marie Koné', 'Yao Brou', 'Yao Brou', 'Yao Brou', 'Aminata Dembélé', 'Bamba Seydou', ...Array.from({ length: 30 }, (_, i) => `Visiteur fictif ${i + 1}`)];
  const list = () => new LocalList(snapshot(names.map((name, i) => visitor(`v${i}`, name, name === 'Yao Brou' && i === 4 ? { category_id: 'c0' } : {}))));
  const find = (q: string) => searchOf(list(), q);
  it('affiche les dix premières personnes par ordre alphabétique même sans saisie, depuis la copie locale', () => {
    const initial = find('');
    expect(initial).toHaveLength(10);
    expect(initial[0].full_name).toBe('Aminata Dembélé');
    expect(initial.every(v => v.category && v.id)).toBe(true);
    expect(find('k')).toEqual([]);
    expect(find('ko').map(v => v.full_name)).toContain('Koné Mariam');
  });
  it('tolère accents et fautes, et cherche sur n’importe quel mot', () => {
    expect(find('Koné').map(r => r.full_name)).toEqual(expect.arrayContaining(['Marie Koné', 'Koné Mariam']));
    expect(find('kone').map(r => r.full_name)).toEqual(expect.arrayContaining(['Marie Koné', 'Koné Mariam']));
    expect(find('mariam kone').slice(0, 1).map(r => r.full_name)).toEqual(['Koné Mariam']);
    expect(find('Marie').map(r => r.full_name)).toContain('Marie Koné');
    expect(find('Mariam Konne')[0].full_name).toBe('Koné Mariam');
    expect(find('Yaw Brou')[0].full_name).toBe('Yao Brou');
    expect(find('ko')[0].full_name).toBe('Koné Mariam');
    expect(find('DEMBELE')[0].full_name).toBe('Aminata Dembélé');
  });
  it('classe le VIP en premier à qualité égale et signale les homonymes', () => {
    const yao = find('Yao Brou');
    expect(yao).toHaveLength(3);
    expect(yao[0].category.priority_circuit).toBe(true);
    expect(yao[0].homonyms).toBe(3);
  });
  it('limite à 10 résultats et ignore les saisies trop courtes ou hostiles', () => {
    expect(find('Visiteur fictif')).toHaveLength(10);
    expect(find('x')).toEqual([]);
    expect(find("' OR 1=1 --")).toEqual([]);
    expect(find('zzzzzz')).toEqual([]);
    expect(find('a'.repeat(200))).toEqual([]);
  });
  it('répond en moins de 200 ms sur 5 000 visiteurs', () => {
    const prenoms = ['Aminata', 'Seydou', 'Mariam', 'Yao', 'Fatou', 'Ibrahim', 'Awa', 'Kouadio', 'Salif', 'Nanou'];
    const noms = ['Koné', 'Dembélé', 'Traoré', 'Bamba', 'Ouattara', 'Diallo', 'Soro', 'Coulibaly', 'Brou', 'Yéo'];
    const big = new LocalList(snapshot(Array.from({ length: 5000 }, (_, i) => visitor(`b${i}`, `${prenoms[i % 10]} ${noms[Math.floor(i / 10) % 10]} ${i}`))));
    big.indexed();
    for (const q of ['Traore', 'seydou kon', 'Mariam Konne', 'ouatara', 'zzzzzz']) {
      const start = performance.now();
      searchOf(big, q);
      expect(performance.now() - start, q).toBeLessThan(200);
    }
  });
});

describe('file hors ligne, règles locales et envoi', () => {
  async function setup(visitors: SnapVisitor[] = [visitor('v1', 'Koné Mariam'), visitor('v2', 'Yao Brou')], reply?: (url: string, init: RequestInit) => Response | Promise<Response>) {
    const calls: Call[] = [];
    const clock = { t: now0 };
    const vault = new PosteVault(new PosteDb(dbName()), { iterations: ITER });
    await vault.enrol({ token: 'b'.repeat(64), secret: SECRET, pin: PIN, agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    const store = new PosteStore(vault);
    const snap = snapshot(visitors);
    await store.saveSnapshot(snap);
    const queued = vi.fn();
    const repo = new LocalPosteRepository(snap, vault, store, { id: 'u1', name: 'Agent fictif' }, queued);
    const revoked = vi.fn(); const snaps: Snapshot[] = [];
    const fetcher = vi.fn(async (url: string | URL | Request, init?: RequestInit) => {
      const body = init?.body ? JSON.parse(String(init.body)) : null;
      calls.push({ url: String(url), body });
      if (reply) return reply(String(url), init ?? {});
      if (String(url).endsWith('/sync')) return new Response(JSON.stringify({ results: body.operations.map((o: Op) => ({ id: o.id, status: 'applique' })), summary: {}, clock_skew_seconds: 2 }), { status: 200 });
      if (String(url).endsWith('/liste/version')) return new Response(JSON.stringify({ version: 'v2', server_time: new Date(now0).toISOString() }), { status: 200 });
      return new Response(JSON.stringify({ ...snap, version: 'v2' }), { status: 200 });
    }) as unknown as typeof fetch;
    const sync = new SyncManager(vault, store, { onState: () => undefined, onSnapshot: async s => { snaps.push(s); await repo.replaceSnapshot(s); }, onRevoked: async () => { revoked(); await vault.wipe(); }, now: () => clock.t, fetcher });
    return { vault, store, repo, sync, calls, queued, revoked, snaps, clock };
  }
  const ops = (calls: Call[]) => calls.filter(c => c.url.endsWith('/sync')).flatMap(c => c.body.operations);

  it('enregistre chaque étape dans la file avant tout envoi, et applique les règles du poste', async () => {
    const { repo, store, calls } = await setup();
    await expect(repo.validate('o0', 'v1')).rejects.toMatchObject({ code: 'identite_requise' });
    await repo.saveIdentity('o1', 'v1', new Blob(['photo-fictive'], { type: 'image/jpeg' }));
    expect((await repo.visitor('v1')).identity_done).toBe(true);
    await repo.validate('o2', 'v1');
    await expect(repo.assignBadge('o3', 'v1', 'B-404')).rejects.toMatchObject({ code: 'badge_inconnu' });
    await expect(repo.assignBadge('o3', 'v1', 'B-900')).rejects.toMatchObject({ code: 'categorie' });
    expect(await repo.assignBadge('o4', 'v1', 'b-001')).toEqual({ badge_code: 'B-001' });
    await expect(repo.assignBadge('o5', 'v2', 'B-001')).rejects.toBeInstanceOf(ApiError);
    expect((await repo.enter('o6', 'v1', 1234)).zones).toEqual(['Hall', 'Salle']);
    expect((await repo.visitor('v1')).status).toBe('present');
    expect(await repo.pendingCount()).toBe(4);
    expect(calls).toHaveLength(0);
    expect((await store.ops('pending')).map(o => o.type)).toEqual(['identite', 'valider', 'badge', 'entree']);
  });

  it('refuse un visiteur hors période sans avis et rejoue une même opération sans la doubler', async () => {
    const { repo } = await setup([visitor('v1', 'Koné Mariam', { valid_from: '2026-11-01T00:00:00.000000Z', valid_until: '2026-11-02T00:00:00.000000Z', identity_done: true, photo_done: true })]);
    expect((await repo.visitor('v1')).hors_periode).toBe(true);
    await expect(repo.validate('o1', 'v1')).rejects.toMatchObject({ code: 'hors_periode' });
    await repo.validate('o1', 'v1', 'superviseur');
    await repo.validate('o1', 'v1', 'superviseur');
    expect(await repo.pendingCount()).toBe(1);
  });

  it('envoie la file par lots de 50 dans l’ordre, avec la photo, puis recopie la liste', async () => {
    const many = Array.from({ length: 60 }, (_, i) => visitor(`m${i}`, `Visiteur fictif ${i}`));
    const { repo, sync, calls, store, snaps } = await setup(many);
    for (let i = 0; i < 60; i++) await repo.refuse(`op-${i}`, `m${i}`, 'motif fictif');
    await repo.saveIdentity('photo-op', 'v-inexistant', new Blob(['x'])).catch(() => undefined);
    await sync.sync();
    const sent = ops(calls);
    expect(sent).toHaveLength(60);
    expect(calls.filter(c => c.url.endsWith('/sync')).map(c => c.body.operations.length)).toEqual([50, 10]);
    expect(sent.map((o: Op) => o.seq)).toEqual([...sent.map((o: Op) => o.seq)].sort((a, b) => a - b));
    expect(await store.count('pending')).toBe(0);
    expect(snaps).toHaveLength(1);
    expect(await store.count('done')).toBe(0);
  });

  it('joint la photo en clair seulement dans la requête, jamais dans la file', async () => {
    const { repo, sync, calls, vault } = await setup();
    await repo.saveIdentity('i1', 'v1', new Blob([new Uint8Array([255, 216, 255, 224, 1, 2, 3])], { type: 'image/jpeg' }));
    const rows = JSON.stringify(await vault.db.photos.toArray().then(r => r.map(x => toB64(x.data))));
    expect(rows).not.toContain('/9j/4AEC'); // préfixe base64 de l'image en clair
    await sync.sync();
    expect(ops(calls)[0].payload.photo_b64.startsWith('/9j/4AEC')).toBe(true);
    expect(await vault.db.photos.count()).toBe(0);
  });

  it('garde les conflits visibles, sans les écraser, et continue les autres opérations', async () => {
    const { repo, sync, store } = await setup(undefined, (url, init) => {
      const body = JSON.parse(String(init.body ?? '{}'));
      if (url.endsWith('/sync')) return new Response(JSON.stringify({ results: body.operations.map((o: Op, i: number) => (i === 0 ? { id: o.id, status: 'conflit', code: 'deja_present', message: 'Ce visiteur est déjà entré.' } : { id: o.id, status: 'applique' })), clock_skew_seconds: 0 }), { status: 200 });
      return new Response(JSON.stringify(url.endsWith('/version') ? { version: 'v1', server_time: new Date(now0).toISOString() } : {}), { status: 200 });
    });
    await repo.refuse('c1', 'v1', 'motif'); await repo.refuse('c2', 'v2', 'motif');
    await sync.sync();
    expect(await store.count('conflict')).toBe(1);
    expect((await store.ops('conflict'))[0].result?.code).toBe('deja_present');
    expect(await store.count('pending')).toBe(0);
  });

  it('garde la file intacte si le réseau tombe ou si la session a expiré, et la reprend ensuite', async () => {
    let mode: 'reseau' | 'session' | 'ok' = 'reseau';
    const { repo, sync, store } = await setup(undefined, (url, init) => {
      if (mode === 'reseau') throw new TypeError('network');
      if (mode === 'session') return new Response('{}', { status: 401 });
      const body = JSON.parse(String(init.body ?? '{}'));
      if (url.endsWith('/sync')) return new Response(JSON.stringify({ results: body.operations.map((o: Op) => ({ id: o.id, status: 'applique' })), clock_skew_seconds: 0 }), { status: 200 });
      return new Response(JSON.stringify(url.endsWith('/version') ? { version: 'v1', server_time: new Date(now0).toISOString() } : snapshot([visitor('v1', 'Koné Mariam')])), { status: 200 });
    });
    await repo.refuse('r1', 'v1', 'motif');
    await sync.sync(); sync.stop();
    expect(await store.count('pending')).toBe(1);
    mode = 'session'; await sync.sync(); sync.stop();
    expect(sync.state.needsLogin).toBe(true);
    expect(await store.count('pending')).toBe(1);
    mode = 'ok'; await sync.sync();
    expect(await store.count('pending')).toBe(0);
    expect(sync.state.needsLogin).toBe(false);
  });

  it('efface la tablette quand le serveur la déclare révoquée', async () => {
    const { repo, sync, vault, revoked } = await setup(undefined, () => new Response(JSON.stringify({ code: 'appareil_revoque', message: 'Révoquée' }), { status: 401 }));
    await repo.refuse('x1', 'v1', 'motif');
    await sync.sync();
    expect(revoked).toHaveBeenCalled();
    expect(await vault.db.meta.count()).toBe(0);
    expect(await vault.db.ops.count()).toBe(0);
  });

  it('considère le poste supprimé lors d’une remise à zéro comme invalide et efface son ancienne copie', async () => {
    const { repo, sync, vault, revoked } = await setup(undefined, () =>
      new Response(JSON.stringify({ code: 'appareil_non_enrole', message: 'Poste supprimé après reset' }), { status: 401 }));
    await repo.refuse('ancienne-operation', 'v1', 'Essai');
    await sync.sync();
    expect(revoked).toHaveBeenCalledWith('appareil_non_enrole');
    expect(sync.state.revoked).toBe(true);
    expect(await vault.db.meta.count()).toBe(0);
    expect(await vault.db.ops.count()).toBe(0);
    expect(await vault.db.chunks.count()).toBe(0);
  });

  it('ne laisse aucune donnée personnelle ni jeton en clair dans IndexedDB', async () => {
    const { repo, vault } = await setup([visitor('v1', 'Koné Mariam', { organisation: 'Organisation Secrète fictive' })]);
    await repo.saveIdentity('i1', 'v1', new Blob(['PHOTO-IDENTIFIABLE'], { type: 'image/jpeg' }));
    await repo.validate('i2', 'v1');
    await repo.assignBadge('i3', 'v1', 'B-001');
    const dump = async () => {
      const text = (u: Uint8Array) => Array.from(u).map(c => String.fromCharCode(c)).join('') + toB64(u);
      const rows = [...(await vault.db.meta.toArray()).map(r => JSON.stringify(r, (_, v) => (v instanceof Uint8Array ? text(v) : v))), ...(await vault.db.chunks.toArray()).map(r => text(r.data) + r.id), ...(await vault.db.ops.toArray()).map(r => text(r.data) + r.type), ...(await vault.db.photos.toArray()).map(r => text(r.data))];
      return rows.join('\n');
    };
    const all = await dump();
    for (const secret of ['Koné', 'Mariam', 'Secrète', 'PHOTO-IDENTIFIABLE', 'b'.repeat(64), SECRET, PIN, 'Agent fictif']) expect(all, secret).not.toContain(secret);
  });
});

describe('runtime', () => {
  it('efface une tablette dont l’événement est terminé depuis plus de 24 h, seulement si plus rien n’est à envoyer', async () => {
    const runtime = new PosteRuntime(undefined, { dbName: dbName(), iterations: ITER });
    await runtime.vault.enrol({ token: 'c'.repeat(64), secret: SECRET, pin: PIN, agent: { id: 'u1', name: 'A' }, poste: { id: 'p1', name: 'P' }, eventEndsAt: new Date(Date.now() - 25 * 3_600_000).toISOString(), settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    expect(await runtime.expired()).toBe(true);
    await runtime.db.ops.put({ id: 'o', seq: 1, type: 'refuser', status: 'pending', data: new Uint8Array([1]) });
    expect(await runtime.expired()).toBe(false);
  });
});

it('refuse hors ligne la remise d’une carte nominative à une autre personne', () => {
  const snap = snapshot([visitor('v1', 'Personne A', { validated: true }), visitor('v2', 'Personne B', { validated: true })]);
  snap.badges[0].printed_for_visitor_id = 'v1';
  const list = new LocalList(snap);
  const op = { id: 'nominal-op', seq: 1, type: 'badge' as const, agent_id: 'u1', client_time: new Date(now0).toISOString(), visitor_rev: null, payload: { visitor_id: 'v2', code: 'B-001' } };
  expect(() => applyOp(list, op, now0)).toThrow('autre personne');
  expect(applyOp(list, { ...op, payload: { visitor_id: 'v1', code: 'B-001' } }, now0)).toEqual({ badge_code: 'B-001' });
});

it('déclare l’effacement après révocation seulement une fois les données locales effacées', async () => {
  const receipts: { motif: string; operations_perdues: number }[] = [];
  const fetcher: typeof fetch = async (input, init) => {
    if (String(input) === '/poste/api/efface') {
      expect(await runtime.db.meta.count()).toBe(0);
      expect(await runtime.db.ops.count()).toBe(0);
      expect(init?.keepalive).toBe(true);
      receipts.push(JSON.parse(String(init?.body)));
      return new Response(JSON.stringify({ ok: true }));
    }
    return new Response(JSON.stringify({ code: 'appareil_revoque', message: 'Révoquée' }), { status: 401 });
  };
  const runtime = new PosteRuntime(undefined, { dbName: dbName(), iterations: ITER, fetcher });
  await runtime.vault.enrol({ token: 'c'.repeat(64), secret: SECRET, pin: PIN, agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste fictif' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
  await runtime.sync.sync();
  await vi.waitFor(() => expect(receipts).toEqual([{ motif: 'deconnexion', operations_perdues: 0 }]));
  expect(await runtime.vault.state()).toBe('vide');
});
