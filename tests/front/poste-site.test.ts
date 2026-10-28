import 'fake-indexeddb/auto';
import { describe, expect, it, vi } from 'vitest';
import { PosteDb } from '../../resources/js/poste/local/db';
import { LocalList, type Snapshot, type SnapVisitor } from '../../resources/js/poste/local/list';
import { LocalPosteRepository } from '../../resources/js/poste/local/local';
import { PosteStore } from '../../resources/js/poste/local/store';
import { controlLocal, exitLocal, resolveBadge } from '../../resources/js/poste/local/site';
import type { SyncManager } from '../../resources/js/poste/local/sync';
import { PosteVault } from '../../resources/js/poste/local/vault';
import { randomBytes, sha256Hex, toB64 } from '../../resources/js/poste/local/crypto';
import { ApiError, type ControlResult } from '../../resources/js/poste/types';

const now0 = Date.now();
const iso = (ms: number) => new Date(now0 + ms).toISOString();
const TOKEN = 'ab'.repeat(32);
const std = { id: 'c1', name: 'Visiteur', color: '#0F5AA6', priority_circuit: false };

function visitor(id: string, name: string, over: Partial<SnapVisitor> = {}): SnapVisitor {
  return { id, full_name: name, organisation: 'Organisation fictive', host_id: 'h1', category_id: 'c1', valid_from: iso(-3_600_000), valid_until: iso(86_400_000), source: 'listé', status: 'present', host_validation: null, rev: iso(-7_200_000), identity_done: true, photo_done: true, validated: true, badge_code: null, ...over };
}
async function snapshot(): Promise<Snapshot> {
  const hash = await sha256Hex(TOKEN);
  return {
    version: 'v1', server_time: iso(0), poste: { id: 'p1', name: 'Tablette A', station: 'Poste A', event_id: 'e1' }, event: { id: 'e1', name: 'Événement fictif', starts_at: iso(-3_600_000), ends_at: iso(86_400_000) },
    settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [std], zones: [{ id: 'z1', name: 'Hall', est_accueil: true }, { id: 'z2', name: 'Salle', est_accueil: false }, { id: 'z3', name: 'Espace VIP', est_accueil: false }],
    category_zone: [{ category_id: 'c1', zone_id: 'z1' }, { category_id: 'c1', zone_id: 'z2' }], hosts: [{ id: 'h1', name: 'Hôte fictif', service: null }], postes: [{ id: 'p1', name: 'Poste A' }, { id: 'p2', name: 'Poste B' }],
    visitors: [
      visitor('v1', 'Koné Mariam', { badge_code: 'B-001' }), visitor('v2', 'Yao Brou', { status: 'attendu', badge_code: 'B-002' }), visitor('v3', 'Soro Fictif', { badge_code: 'B-004', valid_until: iso(-60_000) }), visitor('v4', 'Traoré Fictif', { status: 'sorti', badge_code: 'B-006' }), visitor('v5', 'Bamba Fictif', { status: 'attendu' }),
    ],
    badges: [
      { code: 'B-001', token_hash: hash, category_id: 'c1', status: 'Associé', mine: true }, { code: 'B-002', token_hash: 'x2', category_id: 'c1', status: 'Associé', mine: false }, { code: 'B-003', token_hash: 'x3', category_id: 'c1', status: 'Disponible', mine: true },
      { code: 'B-004', token_hash: 'x4', category_id: 'c1', status: 'Associé', mine: false }, { code: 'B-005', token_hash: 'x5', category_id: 'c1', status: 'Annulé', mine: false }, { code: 'B-006', token_hash: 'x6', category_id: 'c1', status: 'Restitué', mine: false }, { code: 'B-007', token_hash: 'x7', category_id: 'c1', status: 'Disponible', mine: false },
    ],
  };
}

describe('contrôle et sortie sur la copie locale', () => {
  it('rend les mêmes verdicts que le serveur, avec la zone et l’heure de la situation connue', async () => {
    const list = new LocalList(await snapshot());
    const at = iso(-120_000);
    expect(await resolveBadge(list, 'IKOMA1:' + TOKEN)).toBe('B-001');
    expect(await resolveBadge(list, ' b-003 ')).toBe('B-003');
    expect(await resolveBadge(list, 'cd'.repeat(32))).toBeNull();
    const ok = controlLocal(list, 'B-001', { type: 'zone', id: 'z2' }, now0, at);
    expect(ok).toMatchObject({ valid: true, source: 'local', known_at: at, zone_ok: true, point: 'Salle', organisation: 'Organisation fictive', zones: ['Hall', 'Salle'] });
    expect(ok.person?.name).toBe('Koné Mariam');
    expect(controlLocal(list, 'B-001', { type: 'zone', id: 'z3' }, now0, at)).toMatchObject({ valid: true, zone_ok: false, point: 'Espace VIP' });
    expect(controlLocal(list, 'B-001', { type: 'poste', id: 'p2' }, now0, at)).toMatchObject({ valid: true, zone_ok: null, point: 'Poste B' });
    expect(controlLocal(list, 'B-001', null, now0, at).point).toBe('Poste A');
    for (const [code, reason] of [['B-003', 'non_associe'], ['B-005', 'annule'], ['B-006', 'restitue'], ['B-004', 'hors_periode'], [null, 'inconnu']] as const) {
      expect(controlLocal(list, code, null, now0, at), String(code)).toMatchObject({ valid: false, reason, person: null });
    }
  });

  it('juge la sortie : refus sans rien écrire de faux, avertissement si l’entrée est inconnue de la tablette', async () => {
    const list = new LocalList(await snapshot());
    expect(exitLocal(list, 'B-001', null, null)).toMatchObject({ ok: true, warning: null, person: { name: 'Koné Mariam' } });
    expect(exitLocal(list, 'B-002', null, null)).toMatchObject({ ok: true, warning: expect.stringContaining('Aucune entrée connue') });
    expect(exitLocal(list, 'B-003', null, null)).toMatchObject({ ok: false, reason: 'non_associe' });
    expect(exitLocal(list, 'B-005', null, null)).toMatchObject({ ok: false, reason: 'annule' });
    expect(exitLocal(list, 'B-006', null, null)).toMatchObject({ ok: false, reason: 'deja_sorti' });
    expect(exitLocal(list, null, null, null)).toMatchObject({ ok: false, reason: 'inconnu' });
  });
});

describe('dépôt local : contrôle et sortie', () => {
  async function setup(request: (...args: unknown[]) => unknown, online = true) {
    const vault = new PosteVault(new PosteDb(`site-${Math.random()}`), { iterations: 1000 });
    await vault.enrol({ token: 'e'.repeat(64), secret: toB64(randomBytes(32)), pin: '482915', agent: { id: '11111111-1111-4111-8111-111111111111', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    const store = new PosteStore(vault);
    const snap = await snapshot();
    await store.saveSnapshot(snap);
    const sync = { request: vi.fn(request), sync: vi.fn(async () => undefined) } as unknown as SyncManager;
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(online);
    const repo = new LocalPosteRepository(snap, vault, store, { id: '11111111-1111-4111-8111-111111111111', name: 'Agent fictif' }, vi.fn(), 0, sync);
    return { repo, store, vault, sync: sync as unknown as { request: ReturnType<typeof vi.fn> } };
  }
  const serverVerdict: ControlResult = { valid: false, reason: 'annule', message: 'Badge annulé : accès refusé.', badge_code: 'B-001', zone_ok: null, point: 'Salle', person: null, organisation: null, category: null, zones: [], source: 'serveur' };

  it('en ligne, la réponse du serveur fait foi et rien n’est mis dans la file', async () => {
    const { repo, store, sync } = await setup(() => serverVerdict);
    const result = await repo.control('op-1', 'IKOMA1:' + TOKEN, { type: 'zone', id: 'z2' });
    expect(result).toMatchObject({ valid: false, reason: 'annule', source: 'serveur' }); // la copie locale disait « valide » : le serveur est plus récent
    expect(sync.request).toHaveBeenCalledWith('POST', '/poste/api/controle', { code: 'IKOMA1:' + TOKEN, point: { type: 'zone', id: 'z2' } }, 'op-1', 4000);
    expect(await store.count('pending')).toBe(0);
  });

  it('sans réseau, répond d’après la copie, met l’opération en file avec le code imprimé et jamais le contenu du QR', async () => {
    const { repo, store, vault } = await setup(() => { throw new ApiError('reseau', 'Pas de connexion'); });
    const result = await repo.control('op-2', 'IKOMA1:' + TOKEN, { type: 'zone', id: 'z2' });
    expect(result).toMatchObject({ valid: true, source: 'local', zone_ok: true });
    const [op] = await store.ops('pending');
    expect(op).toMatchObject({ type: 'controle', payload: { code: 'B-001', local_valid: true, point: { type: 'zone', id: 'z2' } } });
    const raw = JSON.stringify(await vault.db.ops.toArray().then(r => r.map(x => toB64(x.data)))) + JSON.stringify(op);
    expect(raw).not.toContain(TOKEN);
    // même opération rejouée : pas de doublon dans la file
    await repo.control('op-2', 'IKOMA1:' + TOKEN, { type: 'zone', id: 'z2' });
    expect(await store.count('pending')).toBe(1);
    // un QR inconnu ne laisse que « INCONNU »
    await repo.control('op-3', 'IKOMA1:' + 'cd'.repeat(32), null);
    expect((await store.ops('pending'))[1].payload.code).toBe('INCONNU');
  });

  it('hors ligne, une sortie met le visiteur « sorti » et le badge n’est plus valable ; une deuxième sortie est refusée', async () => {
    const { repo, store } = await setup(() => { throw new ApiError('reseau', 'x'); }, false);
    expect(await repo.exit('op-4', 'b-001', null)).toMatchObject({ ok: true, source: 'local' });
    expect((await repo.visitor('v1')).status).toBe('sorti');
    expect(await repo.exit('op-5', 'B-001', null)).toMatchObject({ ok: false, reason: 'deja_sorti' });
    expect(await repo.control('op-6', 'B-001', null)).toMatchObject({ valid: false, reason: 'restitue' });
    expect((await store.ops('pending')).map(o => o.type)).toEqual(['sortie', 'controle']); // le refus de sortie n'est pas mis en file
    expect(await repo.presentCount()).toBeNull();
  });

  it('refuse d’associer hors ligne un badge qui n’est pas réservé à ce poste, et accepte un badge réservé', async () => {
    const { repo } = await setup(() => ({}), false);
    await expect(repo.assignBadge('op-7', 'v5', 'B-007')).rejects.toMatchObject({ code: 'autre_poste' });
    expect(await repo.assignBadge('op-8', 'v5', 'B-003')).toEqual({ badge_code: 'B-003' });
  });

  it('transmet une erreur du serveur autre qu’une coupure', async () => {
    const { repo } = await setup(() => { throw new ApiError('point_inconnu', 'Point de contrôle inconnu', null, 422); });
    await expect(repo.control('op-9', 'B-001', { type: 'zone', id: 'z2' })).rejects.toMatchObject({ code: 'point_inconnu' });
  });
});
