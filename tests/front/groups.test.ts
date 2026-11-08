import 'fake-indexeddb/auto';
import { describe, expect, it, vi } from 'vitest';
import { PosteDb } from '../../resources/js/poste/local/db';
import { LocalList, type Snapshot, type SnapMember } from '../../resources/js/poste/local/list';
import { LocalPosteRepository } from '../../resources/js/poste/local/local';
import { applyGroupOp, groupSummaries, groupView } from '../../resources/js/poste/local/group';
import { PosteStore } from '../../resources/js/poste/local/store';
import { PosteVault } from '../../resources/js/poste/local/vault';
import { randomBytes, sha256Hex, toB64 } from '../../resources/js/poste/local/crypto';
import { ApiError } from '../../resources/js/poste/types';

const now0 = Date.now();
const iso = (ms: number) => new Date(now0 + ms).toISOString();
const AGENT = { id: '11111111-1111-4111-8111-111111111111', name: 'Agent fictif' };
const stagiaire = { id: 'c2', name: 'Stagiaire', color: '#1E9E5A', priority_circuit: false };

/** Un car de quatre stagiaires : le 1er et le 2e confirmés par l'encadreur, un badge hors lot et un badge du lot déjà pris. */
async function snapshot(mode: 'identite' | 'encadreur' = 'encadreur'): Promise<Snapshot> {
  const visitors = ['Aka Fictif', 'Bamba Fictif', 'Coulibaly Fictif', 'Diomandé Fictif'].map((name, i) => ({ id: `s${i + 1}`, full_name: name, organisation: null, host_id: null, category_id: 'c2', valid_from: iso(-3_600_000), valid_until: iso(86_400_000), source: 'listé', status: 'attendu', host_validation: null as null, group_id: 'g1', rev: iso(-7_200_000), identity_done: false, photo_done: false, validated: false, badge_code: null as string | null }));
  const members: SnapMember[] = visitors.map((v, i) => ({ group_id: 'g1', visitor_id: v.id, checked: false, confirmed: i < 2, absent: false, boarded: false, arrived: false }));
  const token = (n: number) => `${n}`.repeat(64).slice(0, 64);
  return {
    version: 'v1', server_time: iso(0), poste: { id: 'p1', name: 'Tablette cars', station: 'Poste cars', event_id: 'e1', point_code: 'poste_2', capabilities: ['accueil', 'scan', 'sortie'] }, event: { id: 'e1', name: 'Événement fictif', starts_at: iso(-3_600_000), ends_at: iso(86_400_000) },
    settings: { offline_trust_hours: 12, inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15, controle_stagiaires: mode, f06_active: true }, categories: [stagiaire], zones: [{ id: 'z1', name: 'Hall', est_accueil: true }], category_zone: [{ category_id: 'c2', zone_id: 'z1' }],
    hosts: [], postes: [{ id: 'p1', name: 'Poste cars' }], visitors, members,
    groups: [{ id: 'g1', name: 'Groupe fictif', bus_number: 'Car 1', capacity: 10, opened: false, departed: false, arrival_closed: false }],
    badges: [
      { code: 'B-001', token_hash: await sha256Hex(token(1)), category_id: 'c2', status: 'Disponible', mine: false, group_id: 'g1' }, { code: 'B-002', token_hash: await sha256Hex(token(2)), category_id: 'c2', status: 'Disponible', mine: false, group_id: 'g1' },
      { code: 'B-003', token_hash: await sha256Hex(token(3)), category_id: 'c2', status: 'Disponible', mine: false, group_id: 'g1' }, { code: 'B-900', token_hash: await sha256Hex(token(9)), category_id: 'c2', status: 'Disponible', mine: false, group_id: 'g2' },
    ],
  };
}
const op = (list: LocalList, type: string, payload: Record<string, unknown>) => applyGroupOp(list, type, payload);
const refused = (fn: () => unknown): string | null => { try { fn(); } catch (e) { return e instanceof ApiError ? e.code : 'autre'; } return null; };

describe('règles des groupes sur la copie locale', () => {
  it('refuse hors ligne les actions F06 désactivées ou provenant d’une copie sans indicateur', async () => {
    const inactive = await snapshot();
    inactive.settings.f06_active = false;
    const list = new LocalList(inactive);
    expect(groupSummaries(list)).toEqual([]);
    expect(() => groupView(list, 'g1')).toThrow('désactivé');
    expect(() => applyGroupOp(list, 'groupe_depart', { group_id: 'g1' })).toThrow('désactivé');
    delete inactive.settings.f06_active;
    expect(() => applyGroupOp(new LocalList(inactive), 'groupe_depart', { group_id: 'g1' })).toThrow('désactivé');
  });


  it('mode encadreur : le contrôle exige la confirmation, puis la remise exige le contrôle et un badge du lot', async () => {
    const list = new LocalList(await snapshot());
    expect(refused(() => op(list, 'groupe_controle', { group_id: 'g1', visitor_id: 's3' }))).toBe('confirmation_encadreur_requise');
    expect(refused(() => op(list, 'groupe_badge', { group_id: 'g1', visitor_id: 's1', code: 'B-001' }))).toBe('controle_requis');
    op(list, 'groupe_controle', { group_id: 'g1', visitor_id: 's1' });
    expect(refused(() => op(list, 'groupe_badge', { group_id: 'g1', visitor_id: 's1', code: 'B-900' }))).toBe('hors_lot_groupe');
    expect(refused(() => op(list, 'groupe_badge', { group_id: 'g1', visitor_id: 's1', code: 'B-404' }))).toBe('badge_inconnu');
    expect(op(list, 'groupe_badge', { group_id: 'g1', visitor_id: 's1', code: 'B-001' })).toEqual({ badge_code: 'B-001' });
    expect(op(list, 'groupe_badge', { group_id: 'g1', visitor_id: 's1', code: 'B-001' })).toEqual({ badge_code: 'B-001' }); // rejeu sans effet
    op(list, 'groupe_controle', { group_id: 'g1', visitor_id: 's2' });
    expect(refused(() => op(list, 'groupe_badge', { group_id: 'g1', visitor_id: 's2', code: 'B-001' }))).toBe('deja_associe');
    expect(groupView(list, 'g1').progress).toMatchObject({ total: 4, badged: 1, complete: false });
    expect(groupSummaries(list)[0]).toMatchObject({ id: 'g1', total: 4, badged: 1, departed: false });
  });

  it('mode identité : la case « pièce contrôlée » remplace la confirmation de l’encadreur', async () => {
    const list = new LocalList(await snapshot('identite'));
    expect(groupView(list, 'g1').mode).toBe('identite');
    expect(refused(() => op(list, 'groupe_controle', { group_id: 'g1', visitor_id: 's3', identity_checked: false }))).toBe('identite_non_cochee');
    op(list, 'groupe_controle', { group_id: 'g1', visitor_id: 's3', identity_checked: true });
    expect(list.members.get('s3')?.checked).toBe(true);
  });

  /** Appel + badge pour les personnes données (s1 → B-001, s2 → B-002, …). */
  const call = (list: LocalList, ids: string[]) => ids.forEach((id, i) => { op(list, 'groupe_controle', { group_id: 'g1', visitor_id: id }); op(list, 'groupe_badge', { group_id: 'g1', visitor_id: id, code: `B-00${i + 1}` }); });

  it('embarquement individuel : exige l’appel et le badge de la personne, et se rejoue sans effet', async () => {
    const list = new LocalList(await snapshot());
    expect(refused(() => op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-404' }))).toBe('badge_inconnu');
    expect(refused(() => op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-001' }))).toBe('non_associe');
    call(list, ['s1', 's2']);
    expect(refused(() => op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-900' }))).toBe('non_associe'); // badge du lot d'un autre groupe, personne n'en est porteur
    expect(op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-001' }).boarding).toMatchObject({ boarded: true, already: false, places_restantes: 9 });
    expect(op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-001' }).boarding).toMatchObject({ already: true });
    expect(list.members.get('s1')?.boarded).toBe(true);
    expect(list.members.get('s2')?.boarded).toBe(false);
    // la personne dont l'appel n'est pas fait ne peut pas embarquer
    list.members.get('s2')!.checked = false;
    expect(refused(() => op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-002' }))).toBe('controle_requis');
  });

  it('capacité obligatoire : aucun embarquement sans places renseignées, jamais illimité', async () => {
    for (const capacity of [null, 0] as const) {
      const snap = await snapshot();
      snap.groups![0].capacity = capacity;
      const list = new LocalList(snap);
      call(list, ['s1']);
      expect(refused(() => op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-001' }))).toBe('capacite_non_renseignee');
      expect(list.members.get('s1')?.boarded).toBe(false);
      expect(groupView(list, 'g1').progress.capacity).toBeNull();
    }
  });

  it('capacité bloquante : impossible d’embarquer au-delà des places, absent levé par l’embarquement', async () => {
    const snap = await snapshot();
    snap.groups![0].capacity = 1;
    const list = new LocalList(snap);
    call(list, ['s1', 's2']);
    expect(groupView(list, 'g1').progress).toMatchObject({ capacity: 1, free: 1 });
    expect(op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-001' }).boarding).toMatchObject({ places_restantes: 0 });
    expect(refused(() => op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-002' }))).toBe('car_complet');
    expect(list.members.get('s2')?.boarded).toBe(false);
    expect(groupView(list, 'g1').progress).toMatchObject({ boarded: 1, free: 0 });
  });

  it('absence tracée : motif obligatoire, refusée pour un embarqué, levée par l’embarquement avant le départ', async () => {
    const list = new LocalList(await snapshot());
    call(list, ['s1', 's2']);
    expect(refused(() => op(list, 'groupe_absent', { group_id: 'g1', visitor_id: 's3', reason: '  ' }))).toBe('motif_absence_obligatoire');
    op(list, 'groupe_absent', { group_id: 'g1', visitor_id: 's3', reason: 'Malade' });
    expect(list.members.get('s3')?.absent).toBe(true);
    op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-001' });
    expect(refused(() => op(list, 'groupe_absent', { group_id: 'g1', visitor_id: 's1', reason: 'Erreur' }))).toBe('deja_embarque');
    // une personne notée absente qui se présente est appelée puis embarquée : l'absence est levée
    list.members.get('s3')!.confirmed = true; // confirmation de l'encadreur
    op(list, 'groupe_controle', { group_id: 'g1', visitor_id: 's3' });
    op(list, 'groupe_badge', { group_id: 'g1', visitor_id: 's3', code: 'B-003' });
    op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-003' });
    expect(list.members.get('s3')).toMatchObject({ boarded: true, absent: false });
  });

  it('départ : refusé tant que l’appel nominal est incomplet, sans embarquement déduit, puis plus rien ne change', async () => {
    const list = new LocalList(await snapshot());
    call(list, ['s1', 's2']);
    op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-001' });
    expect(refused(() => op(list, 'groupe_depart', { group_id: 'g1' }))).toBe('appel_incomplet');
    expect(groupView(list, 'g1').progress.unresolved).toBe(3);
    op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-002' });
    op(list, 'groupe_absent', { group_id: 'g1', visitor_id: 's3', reason: 'Malade' });
    op(list, 'groupe_absent', { group_id: 'g1', visitor_id: 's4', reason: 'Transport personnel' });
    expect(op(list, 'groupe_depart', { group_id: 'g1' })).toEqual({ boarded: 2, absent: 2 });
    expect(op(list, 'groupe_depart', { group_id: 'g1' })).toEqual({ boarded: 2, absent: 2 });
    expect(refused(() => op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-002' }))).toBe('car_ferme');
    expect(refused(() => op(list, 'groupe_absent', { group_id: 'g1', visitor_id: 's3', reason: 'x' }))).toBe('car_ferme');
    expect(groupView(list, 'g1').members.filter(m => m.absent).map(m => m.name)).toEqual(['Coulibaly Fictif', 'Diomandé Fictif']);
  });

  it('arrivée : débarquement individuel sans présence déduite, écart pour un badge non embarqué, bilan des manquants', async () => {
    const list = new LocalList(await snapshot());
    call(list, ['s1', 's2']);
    op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-001' });
    op(list, 'groupe_embarquer', { group_id: 'g1', code: 'B-002' });
    op(list, 'groupe_absent', { group_id: 'g1', visitor_id: 's3', reason: 'Malade' });
    op(list, 'groupe_absent', { group_id: 'g1', visitor_id: 's4', reason: 'Malade' });
    op(list, 'groupe_depart', { group_id: 'g1' });
    expect(op(list, 'groupe_arrivee', { code: 'B-001' }).arrival).toMatchObject({ name: 'Aka Fictif', ecart: null, already: false });
    expect(op(list, 'groupe_arrivee', { code: 'B-001' }).arrival).toMatchObject({ already: true });
    expect(list.members.get('s1')?.arrived).toBe(true);
    expect(list.visitors.get('s1')?.status).toBe('attendu'); // l'arrivée du car n'établit pas la présence physique
    expect(refused(() => op(list, 'groupe_arrivee', { code: 'B-003' }))).toBe('non_associe');
    expect(op(list, 'groupe_arrivee_fin', { group_id: 'g1' })).toEqual({ arrived: 1, missing: 1 });
  });
});

describe('dépôt local : file d’opérations des groupes', () => {
  async function setup() {
    const vault = new PosteVault(new PosteDb(`groups-${Math.random()}`), { iterations: 1000 });
    await vault.enrol({ token: 'e'.repeat(64), secret: toB64(randomBytes(32)), pin: '482915', agent: AGENT, poste: { id: 'p1', name: 'Poste cars' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
    const store = new PosteStore(vault);
    const snap = await snapshot();
    await store.saveSnapshot(snap);
    const repo = new LocalPosteRepository(snap, vault, store, AGENT, vi.fn(), 0, null);
    return { repo, store };
  }

  it('enregistre chaque action une seule fois, même rejouée, et résout le QR par son hachage', async () => {
    const { repo, store } = await setup();
    await repo.groupCheck('op-1', 'g1', 's1', false);
    await repo.groupCheck('op-1', 'g1', 's1', false);
    expect(await repo.groupDeliver('op-2', 'g1', 's1', 'IKOMA1:' + '1'.repeat(64))).toEqual({ badge_code: 'B-001' });
    expect(await repo.groupDeliver('op-2', 'g1', 's1', 'B-001')).toEqual({ badge_code: 'B-001' });
    expect(await store.count('pending')).toBe(2);
    await expect(repo.groupDeliver('op-3', 'g1', 's2', 'B-001')).rejects.toMatchObject({ code: 'controle_requis' });
    await expect(repo.groupDeliver('op-4', 'g1', 's2', 'ff'.repeat(32))).rejects.toMatchObject({ code: 'badge_inconnu' });
    expect(await store.count('pending')).toBe(2);
    expect((await repo.groupView('g1')).progress.badged).toBe(1);
    await expect(repo.groupDepart('op-5', 'g1')).rejects.toMatchObject({ code: 'appel_incomplet' });
    expect(await repo.groupBoard('op-6', 'g1', 'IKOMA1:' + '1'.repeat(64))).toMatchObject({ boarded: true, already: false });
    expect(await repo.groupBoard('op-6', 'g1', 'B-001')).toMatchObject({ already: true }); // rejeu : une seule opération en file
    await repo.groupAbsent('op-7', 'g1', 's2', 'Malade');
    await repo.groupAbsent('op-8', 'g1', 's3', 'Malade');
    await repo.groupAbsent('op-9', 'g1', 's4', 'Malade');
    expect(await repo.groupDepart('op-10', 'g1')).toEqual({ boarded: 1, absent: 3 });
    expect(await repo.arrive('op-11', 'B-001')).toMatchObject({ name: 'Aka Fictif', ecart: null });
    expect(await repo.arriveFinish('op-12', 'g1')).toEqual({ arrived: 1, missing: 0 });
    expect(await store.count('pending')).toBe(9);
  });
});
