import 'fake-indexeddb/auto';
import { describe, expect, it, vi } from 'vitest';
import { createDeviceKey, createVault, fromB64, openJson, openVault, PBKDF2_ITERATIONS, randomBytes, sealJson, sha256Hex, toB64 } from '../../resources/js/poste/local/crypto';
import { PosteDb, setMeta } from '../../resources/js/poste/local/db';
import { PosteVault, PinError, pinProblem } from '../../resources/js/poste/local/vault';
import { LocalList, type Snapshot, type SnapVisitor } from '../../resources/js/poste/local/list';
import { applyOp, searchOf } from '../../resources/js/poste/local/apply';
import { PosteStore } from '../../resources/js/poste/local/store';
import { LocalPosteRepository } from '../../resources/js/poste/local/local';
import { SyncManager } from '../../resources/js/poste/local/sync';
import { PosteRuntime } from '../../resources/js/poste/local/runtime';
import { OP_POLICY, posteGate } from '../../resources/js/poste/local/capabilities';
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
    version: 'v1', server_time: new Date(now0).toISOString(), poste: { id: 'p1', name: 'Tablette A', station: 'Poste fictif A', event_id: 'e1', point_code: 'poste_2', capabilities: ['accueil', 'scan', 'sortie'] }, event: { id: 'e1', name: 'Événement fictif', starts_at: iso(-3_600_000), ends_at: iso(86_400_000) },
    settings: { offline_trust_hours: 12, inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [vip, std], zones: [{ id: 'z1', name: 'Hall', est_accueil: true }, { id: 'z2', name: 'Salle', est_accueil: false }],
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
    const { repo } = await setup([visitor('v1', 'Koné Mariam', { valid_from: iso(86_400_000), valid_until: iso(172_800_000), identity_done: true, photo_done: true })]);
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

  it('garde la file intacte et n’insiste pas quand le serveur refuse un auteur incohérent (S02)', async () => {
    let syncCalls = 0;
    const { repo, sync, store } = await setup(undefined, (url) => {
      if (url.endsWith('/sync')) { syncCalls++; return new Response(JSON.stringify({ code: 'auteur_incoherent', message: 'Ces opérations ont été saisies par un autre compte.', action: null }), { status: 403 }); }
      return new Response(JSON.stringify(url.endsWith('/version') ? { version: 'v1', server_time: new Date(now0).toISOString() } : {}), { status: 200 });
    });
    await repo.refuse('a1', 'v1', 'motif');
    await sync.sync(); sync.stop();
    expect(syncCalls).toBe(1);
    expect(await store.count('pending')).toBe(1);
    expect(await store.count('conflict')).toBe(0);
    expect(sync.state.lastError).toBe('Ces opérations ont été saisies par un autre compte.');
    expect(sync.state.revoked).toBe(false);
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
    expect(revoked).toHaveBeenCalledOnce(); // le helper de test relaie le nettoyage sans transmettre le motif
    expect(sync.state.revoked).toBe(true);
    expect(await vault.db.meta.count()).toBe(0);
    expect(await vault.db.ops.count()).toBe(0);
    expect(await vault.db.chunks.count()).toBe(0);
  });

  it('autorise un nouveau cycle de synchronisation après invalidation d’un ancien poste', async () => {
    const { sync } = await setup(undefined, () =>
      new Response(JSON.stringify({ code: 'appareil_non_enrole' }), { status: 401 }));
    await sync.sync();
    expect(sync.state.revoked).toBe(true);
    sync.resetAfterEnrollment();
    expect(sync.state.revoked).toBe(false);
    expect(sync.state.needsLogin).toBe(false);
    expect(sync.state.lastSyncAt).toBeNull();
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

describe('S02 — restrictions de poste appliquées avant toute décision locale', () => {
  async function repoFor(over: { point_code?: string | null; capabilities?: string[]; trustHours?: number; lastSync?: string | null; online?: boolean; permanentHash?: string }) {
    const vault = new PosteVault(new PosteDb(dbName()), { iterations: ITER });
    await vault.enrol({ token: 'b'.repeat(64), secret: SECRET, pin: PIN, agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    const store = new PosteStore(vault);
    const snap = snapshot([visitor('v1', 'Koné Mariam', { identity_done: true, photo_done: true }), visitor('v2', 'Yao Brou', { identity_done: true, photo_done: true })]);
    snap.poste = { ...snap.poste, point_code: over.point_code, capabilities: over.capabilities };
    if (over.permanentHash) snap.permanent = { trust_minutes: 0, cards: [{ hash: over.permanentHash, status: 'active', holder_id: 'h1', identifier: 'P-0001' }], policies: [], holders: [] };
    snap.settings = { ...snap.settings };
    if (over.trustHours !== undefined) snap.settings.offline_trust_hours = over.trustHours; else delete snap.settings.offline_trust_hours;
    await store.saveSnapshot(snap);
    await setMeta(vault.db, 'lastSyncAt', over.lastSync === undefined ? new Date(now0).toISOString() : over.lastSync);
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(over.online ?? false);
    const repo = new LocalPosteRepository(snap, vault, store, { id: 'u1', name: 'Agent fictif' }, vi.fn());
    return { repo, store };
  }
  const gateReasons = ['poste_non_identifie', 'poste_non_habilite', 'politique_non_approuvee', 'politique_perimee'];
  const full = ['accueil', 'scan', 'sortie'];
  const hoursAgo = (h: number) => new Date(now0 - h * 3_600_000).toISOString();

  it('ne produit aucun résultat favorable pour un poste non identifié ou dont la copie ne porte pas ses capacités', async () => {
    for (const over of [{ point_code: null, capabilities: [] }, { point_code: undefined, capabilities: undefined }, { point_code: 'poste_2', capabilities: undefined }]) {
      const { repo, store } = await repoFor({ ...over, trustHours: 12 });
      const control = await repo.control('c1', 'B-001', null);
      expect(control.valid).toBe(false);
      expect(control.reason).toBe('poste_non_identifie');
      const exit = await repo.exit('x1', 'B-001', null);
      expect(exit.ok).toBe(false);
      expect(exit.reason).toBe('poste_non_identifie');
      await expect(repo.refuse('r1', 'v1', 'motif')).rejects.toMatchObject({ code: 'poste_non_identifie' });
      await expect(repo.enter('e1', 'v1', 1000)).rejects.toMatchObject({ code: 'poste_non_identifie' });
      expect(await store.count('pending')).toBe(0); // rien n'est mis en file : aucune opération à rejuger côté serveur
    }
  });

  it('refuse les capacités absentes du point tenu par la tablette (poste 1, poste 3, poste 4, soute)', async () => {
    for (const [point, capabilities] of [['poste_1', []], ['poste_3', []], ['poste_4', []], ['soute', ['scan']]] as [string, string[]][]) {
      const { repo, store } = await repoFor({ point_code: point, capabilities, trustHours: 12 });
      const scan = await repo.control('c-' + point, 'B-001', null, point === 'soute' ? 'entree' : undefined); // à la soute le sens est obligatoire (S10)
      if (capabilities.includes('scan')) expect(gateReasons).not.toContain(scan.reason);
      else expect(scan.reason).toBe('poste_non_habilite');
      expect((await repo.exit('x-' + point, 'B-001', null)).reason).toBe('poste_non_habilite');
      await expect(repo.refuse('r-' + point, 'v1', 'motif')).rejects.toMatchObject({ code: 'poste_non_habilite' });
      expect(await store.count('pending')).toBe(capabilities.includes('scan') ? 1 : 0); // le seul scan de la soute est consigné, comme avant
    }
  });

  it('refuse par défaut toute décision favorable sans réseau tant qu’aucun délai n’est approuvé : délai nul, absent ou non approuvé ne signifie jamais « illimité »', async () => {
    for (const trustHours of [undefined, 0, -1]) {
      const { repo, store } = await repoFor({ point_code: 'poste_2', capabilities: full, trustHours, lastSync: hoursAgo(0) });
      expect((await repo.control('c', 'B-001', null)).reason).toBe('politique_non_approuvee');
      for (const op of [() => repo.validate('v', 'v1'), () => repo.assignBadge('b', 'v1', 'B-001'), () => repo.enter('e', 'v1', 1000)]) {
        await expect(op()).rejects.toMatchObject({ code: 'politique_non_approuvee' });
      }
      // ce qui n'accorde rien reste possible : refus, identité, sortie (jamais soumise à la confiance)
      await repo.refuse('r', 'v1', 'motif');
      expect(gateReasons).not.toContain((await repo.exit('x', 'B-001', null)).reason);
      expect(await store.count('pending')).toBe(1);
    }
  });

  it('refuse une copie plus ancienne que le délai approuvé, jamais en dessous, et laisse passer ce qui n’accorde rien', async () => {
    const stale = await repoFor({ point_code: 'poste_2', capabilities: full, trustHours: 12, lastSync: hoursAgo(13) });
    expect((await stale.repo.control('s1', 'B-001', null)).reason).toBe('politique_perimee');
    await expect(stale.repo.enter('s3', 'v1', 1000)).rejects.toMatchObject({ code: 'politique_perimee' });
    expect(gateReasons).not.toContain((await stale.repo.exit('s2', 'B-001', null)).reason); // la sortie n'est jamais bloquée par la péremption
    await stale.repo.refuse('s4', 'v1', 'motif'); // un refus non plus
    const never = await repoFor({ point_code: 'poste_2', capabilities: full, trustHours: 12, lastSync: null });
    expect((await never.repo.control('n1', 'B-001', null)).reason).toBe('politique_perimee');
    const fresh = await repoFor({ point_code: 'poste_2', capabilities: full, trustHours: 12, lastSync: hoursAgo(1) });
    expect(gateReasons).not.toContain((await fresh.repo.control('f1', 'B-001', null)).reason);
    await fresh.repo.validate('f2', 'v2');
    expect(await fresh.store.count('pending')).toBe(2);
  });

  it('n’applique pas la péremption en ligne : le serveur juge alors lui-même, et la file embarque l’âge de la copie', async () => {
    const { repo, store } = await repoFor({ point_code: 'poste_2', capabilities: full, trustHours: 0, lastSync: hoursAgo(40), online: true });
    await repo.refuse('o1', 'v1', 'motif');
    await repo.validate('o2', 'v2');
    const ops = await store.ops('pending');
    const valider = ops.find(o => o.type === 'valider');
    expect(valider?.payload).toMatchObject({ offline: false });
    expect(typeof valider?.payload.known_at).toBe('string');
  });

  it('laisse les cartes permanentes à leur règle propre : la péremption ne s’applique pas, la capacité de poste oui', async () => {
    const token = 'a'.repeat(64);
    const hash = await sha256Hex(token);
    const { repo } = await repoFor({ point_code: 'soute', capabilities: ['scan'], trustHours: 0, permanentHash: hash });
    const result = await repo.control('p1', 'IKOMA1:' + token, null, 'entree');
    expect(gateReasons).not.toContain(result.reason); // la carte est jugée par sa propre règle (PermanentAccess), pas par le délai des badges temporaires
    const none = await repoFor({ point_code: 'poste_1', capabilities: [], trustHours: 12, permanentHash: hash });
    expect((await none.repo.control('p2', 'IKOMA1:' + token, null)).reason).toBe('poste_non_habilite');
  });

  // S10 — sens du passage à la soute : choisi avant le scan, obligatoire, consigné avec l'opération.
  it('exige Entrée ou Sortie avant tout scan à la soute, sans rien consigner', async () => {
    const { repo, store } = await repoFor({ point_code: 'soute', capabilities: ['scan'], trustHours: 12 });
    expect(repo.requiresDirection()).toBe(true);
    await expect(repo.control('s1', 'B-001', null)).rejects.toMatchObject({ code: 'sens_requis' });
    expect(await store.count('pending')).toBe(0);
  });

  it('consigne le sens choisi avec le contrôle hors ligne, pour qu’il atteigne le journal à la synchronisation', async () => {
    const { repo, store } = await repoFor({ point_code: 'soute', capabilities: ['scan'], trustHours: 12 });
    const out = await repo.control('s2', 'B-001', null, 'sortie');
    expect(out.direction).toBe('sortie');
    expect(await store.count('pending')).toBe(1);
  });

  it('n’impose aucun sens hors de la soute', async () => {
    const { repo } = await repoFor({ point_code: 'poste_2', capabilities: ['accueil', 'scan', 'sortie'], trustHours: 12 });
    expect(repo.requiresDirection()).toBe(false);
    const result = await repo.control('s3', 'B-001', null);
    expect(result.direction ?? null).toBeNull();
  });
});

describe('S02 — l’hôtel n’est pas un poste de l’Académie', () => {
  const listFor = (point: string, capabilities: string[], trustHours?: number) => {
    const snap = snapshot([]);
    snap.poste = { ...snap.poste, point_code: point, capabilities };
    snap.settings = { ...snap.settings };
    if (trustHours === undefined) delete snap.settings.offline_trust_hours; else snap.settings.offline_trust_hours = trustHours;
    return new LocalList(snap);
  };
  const known = new Date(now0).toISOString();

  it('autorise à l’hôtel la préparation et la remise des badges de groupe par permission de rôle, sans aucune capacité de poste', () => {
    const hotel = listFor('hotel', [], 12);
    expect(posteGate(hotel, OP_POLICY.groupe_badge, now0, known, { offline: true })).toBeNull(); // pas de capacité de poste exigée
    for (const type of ['groupe_controle', 'groupe_depart', 'groupe_arrivee_fin']) expect(OP_POLICY[type]).toBeUndefined(); // enregistrements : aucune porte
    // ... mais aucune opération de poste : ni accueil, ni scan, ni sortie, ni arrivée du car
    for (const type of ['valider', 'badge', 'entree', 'controle', 'sortie', 'groupe_arrivee', 'refuser']) {
      expect(posteGate(hotel, OP_POLICY[type], now0, known, { offline: false })?.reason, type).toBe('poste_non_habilite');
    }
  });

  it('refuse la remise de badge de groupe sans réseau tant qu’aucun délai n’est approuvé, y compris à l’hôtel : c’est une décision d’accès', () => {
    for (const trust of [undefined, 0]) {
      expect(posteGate(listFor('hotel', [], trust), OP_POLICY.groupe_badge, now0, known, { offline: true })?.reason).toBe('politique_non_approuvee');
    }
    expect(posteGate(listFor('hotel', [], 12), OP_POLICY.groupe_badge, now0, known, { offline: false })).toBeNull(); // en ligne : jugée par le serveur
    expect(posteGate(listFor('poste_2', ['accueil', 'scan', 'sortie'], 12), OP_POLICY.groupe_arrivee, now0, known, { offline: true })).toBeNull();
    expect(posteGate(listFor('hotel', [], 12), OP_POLICY.groupe_arrivee, now0, known, { offline: true })?.reason).toBe('poste_non_habilite'); // l'arrivée est un scan à un poste de l’Académie
  });
});

describe('S02 — l’auteur initial des opérations en attente est préservé', () => {
  const quiet = vi.fn(async () => { throw new TypeError('network'); }) as unknown as typeof fetch;
  async function runtimeWith(agent: { id: string; name: string }, pending: number) {
    const wiped = vi.fn();
    const runtime = new PosteRuntime({ onState: () => undefined, onWiped: wiped }, { dbName: dbName(), iterations: ITER, fetcher: quiet });
    await runtime.vault.enrol({ token: 'c'.repeat(64), secret: SECRET, pin: PIN, agent, poste: { id: 'p1', name: 'Poste fictif' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    for (let i = 1; i <= pending; i++) await runtime.db.ops.put({ id: 'op-' + i, seq: i, type: 'refuser', status: 'pending', data: new Uint8Array([1]) });
    runtime.lock();
    return { runtime, wiped };
  }

  it('refuse qu’un autre compte ouvre la tablette tant que des opérations attendent : rien n’est effacé, envoyé ni repris', async () => {
    const { runtime, wiped } = await runtimeWith({ id: 'u1', name: 'Agent A' }, 2);
    await expect(runtime.unlock(PIN, 'u2')).rejects.toMatchObject({ code: 'operations_en_attente_autre_agent', status: 409 });
    expect(wiped).not.toHaveBeenCalled();
    expect(runtime.vault.unlocked).toBe(false);
    expect(await runtime.db.ops.count()).toBe(2);
    expect(await runtime.store.count('pending')).toBe(2);
    expect(await runtime.vault.state()).not.toBe('vide');
    // l'auteur initial, authentifié, retrouve ses opérations intactes
    await runtime.unlock(PIN, 'u1');
    expect(runtime.vault.unlocked).toBe(true);
    expect(await runtime.store.count('pending')).toBe(2);
    expect(wiped).not.toHaveBeenCalled();
    runtime.lock();
  });

  it('garde l’ancien comportement, déclaré, quand aucune opération n’attend : un autre agent peut reprendre la tablette', async () => {
    const { runtime, wiped } = await runtimeWith({ id: 'u1', name: 'Agent A' }, 0);
    await runtime.unlock(PIN, 'u2');
    expect(wiped).toHaveBeenCalledWith('compte_change');
    expect(await runtime.vault.state()).toBe('vide');
  });

  it('refuse un nouvel enrôlement par-dessus des opérations non envoyées, sans appeler le serveur', async () => {
    const calls: string[] = [];
    const runtime = new PosteRuntime(undefined, { dbName: dbName(), iterations: ITER, fetcher: (async (url: string | URL | Request) => { calls.push(String(url)); throw new TypeError('network'); }) as typeof fetch });
    await runtime.db.ops.put({ id: 'op-1', seq: 1, type: 'refuser', status: 'pending', data: new Uint8Array([1]) });
    await expect(runtime.enrol('ABCD-EFGH-2345', PIN, { id: 'u2', name: 'Agent B' })).rejects.toMatchObject({ code: 'operations_en_attente' });
    expect(calls).toEqual([]);
    expect(await runtime.db.ops.count()).toBe(1);
  });
});

it('explique la différence entre badge libre et badge réservé à un autre poste dans la copie locale', () => {
  const snap = snapshot([visitor('v1', 'Visiteur Test', { validated: true })]);
  snap.badges[0].mine = false;
  snap.badges[0].reservation = 'aucune';
  const list = new LocalList(snap);
  const op = { id: 'badge-test', seq: 1, type: 'badge' as const, agent_id: 'u1', client_time: new Date(now0).toISOString(), visitor_rev: null, payload: { visitor_id: 'v1', code: 'B-001' } };
  expect(() => applyOp(list, op, now0)).toThrow('pas encore réservé');
  snap.badges[0].reservation = 'autre_poste';
  expect(() => applyOp(new LocalList(snap), op, now0)).toThrow('autre poste');
  snap.badges[0].reservation = 'ce_poste';
  snap.badges[0].mine = true;
  expect(applyOp(new LocalList(snap), op, now0)).toEqual({ badge_code: 'B-001' });
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

describe('portraits copiés pour l’accueil hors ligne', () => {
  const jpeg = new Uint8Array([255, 216, 255, 224, 9, 8, 7, 6]);
  async function build(visitors: SnapVisitor[]) {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);
    const vault = new PosteVault(new PosteDb(dbName()), { iterations: ITER });
    await vault.enrol({ token: 'b'.repeat(64), secret: SECRET, pin: PIN, agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    await vault.putSecret('deviceToken', 'a'.repeat(64));
    const store = new PosteStore(vault);
    const snap = snapshot(visitors);
    const urls: string[] = [];
    const fetcher = vi.fn(async (url: string | URL | Request) => {
      urls.push(String(url));
      return String(url).includes('/v2/') ? new Response('', { status: 404 }) : new Response(jpeg, { status: 200, headers: { 'Content-Type': 'image/jpeg' } });
    }) as unknown as typeof fetch;
    const sync = new SyncManager(vault, store, { onState: () => undefined, onSnapshot: async () => undefined, onRevoked: async () => undefined, now: () => now0, fetcher });
    const repo = new LocalPosteRepository(snap, vault, store, { id: 'u1', name: 'Agent fictif' }, vi.fn(), 0, sync);
    return { vault, store, repo, urls };
  }

  it('copie les portraits chiffrés, les rend hors ligne, ne redemande rien et efface ce qui quitte la liste', async () => {
    const { vault, store, repo, urls } = await build([visitor('v1', 'Koné Mariam'), visitor('v2', 'Yao Brou')]);
    await repo.copyPortraits();
    expect(urls).toHaveLength(2);
    expect(await store.portraitIds()).toEqual(expect.arrayContaining(['v1', 'v2']));
    // le contenu stocké est scellé : aucune trace de l'image en clair
    const raw = JSON.stringify((await vault.db.photos.toArray()).map(r => Array.from(r.data)));
    expect(raw).not.toContain(Array.from(jpeg).join(','));
    // hors ligne : le portrait vient de la copie
    const nav = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
    const blob = await repo.portrait('v1');
    expect(blob).not.toBeNull();
    expect(new Uint8Array(await blob!.arrayBuffer())).toEqual(jpeg);
    nav.mockReturnValue(true);
    // déjà copiés à la même révision : pas de nouvelle demande
    await repo.copyPortraits();
    expect(urls).toHaveLength(2);
    // la personne quitte la copie (autre activité) : son portrait est effacé
    repo.list = new LocalList(snapshot([visitor('v1', 'Koné Mariam')]));
    await repo.copyPortraits();
    expect(await store.portraitIds()).toEqual(['v1']);
  });

  it('ne copie rien tant que le coffre est verrouillé et efface tout à l’effacement de la tablette', async () => {
    const { vault, store, repo, urls } = await build([visitor('v1', 'Koné Mariam')]);
    vault.lock();
    await repo.copyPortraits();
    expect(urls).toHaveLength(0);
    await vault.wipe();
    expect(await vault.db.photos.count()).toBe(0);
    expect(store).toBeDefined();
  });
});
