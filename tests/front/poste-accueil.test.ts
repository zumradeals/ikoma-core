import 'fake-indexeddb/auto';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { toB64, randomBytes } from '../../resources/js/poste/local/crypto';
import { PosteDb } from '../../resources/js/poste/local/db';
import { PosteVault } from '../../resources/js/poste/local/vault';
import { PosteStore } from '../../resources/js/poste/local/store';
import { LocalPosteRepository } from '../../resources/js/poste/local/local';
import { PosteRuntime } from '../../resources/js/poste/local/runtime';
import { setMeta } from '../../resources/js/poste/local/db';
import type { Snapshot, SnapVisitor } from '../../resources/js/poste/local/list';
import type { DeskSummary } from '../../resources/js/poste/types';
import DeskPanel from '../../resources/js/components/poste/DeskPanel.vue';
import StatusBar from '../../resources/js/components/poste/StatusBar.vue';

const now0 = Date.now();
const iso = (ms: number) => new Date(now0 + ms).toISOString();
const std = { id: 'c1', name: 'Visiteur', color: '#0F5AA6', priority_circuit: false };
const visitor = (id: string, name: string, over: Partial<SnapVisitor> = {}): SnapVisitor => ({
  id, full_name: name, organisation: 'Organisation fictive', host_id: 'h1', category_id: 'c1', valid_from: iso(-3_600_000), valid_until: iso(86_400_000), source: 'listé', status: 'attendu',
  host_validation: null, rev: '2026-11-05T07:00:00.000000Z', identity_done: false, photo_done: false, validated: false, badge_code: null, ...over,
});
const snapshot = (visitors: SnapVisitor[], settings: Partial<Snapshot['settings']> = {}): Snapshot => ({
  version: 'v1', server_time: iso(0), poste: { id: 'p1', name: 'Tablette A', station: 'Poste fictif A', event_id: 'e1' }, event: { id: 'e1', name: 'Événement fictif', starts_at: iso(-3_600_000), ends_at: iso(86_400_000) },
  settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15, ...settings }, categories: [std], zones: [{ id: 'z1', name: 'Hall', est_accueil: true }], category_zone: [{ category_id: 'c1', zone_id: 'z1' }],
  hosts: [{ id: 'h1', name: 'Hôte fictif', service: null }], postes: [{ id: 'p1', name: 'Poste fictif A' }], visitors,
  badges: [
    { code: 'B-001', token_hash: '', category_id: 'c1', status: 'Disponible', mine: true }, { code: 'B-002', token_hash: '', category_id: 'c1', status: 'Disponible', mine: true },
    { code: 'B-003', token_hash: '', category_id: 'c1', status: 'Disponible', mine: true }, { code: 'B-900', token_hash: '', category_id: 'c1', status: 'Disponible', mine: false },
    { code: 'B-777', token_hash: '', category_id: 'c1', status: 'Disponible', mine: true, group_id: 'g1' },
  ],
});

async function desk(visitors: SnapVisitor[], settings: Partial<Snapshot['settings']> = {}) {
  const vault = new PosteVault(new PosteDb(`accueil-${Math.random()}`), { iterations: 1000 });
  await vault.enrol({ token: 'c'.repeat(64), secret: toB64(randomBytes(32)), pin: '482915', agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 } });
  const store = new PosteStore(vault); const snap = snapshot(visitors, settings);
  await store.saveSnapshot(snap);
  return { repo: new LocalPosteRepository(snap, vault, store, { id: 'u1', name: 'Agent fictif' }, vi.fn()), store };
}

describe('chiffres du poste calculés sur la tablette', () => {
  it('compte les accueillis, les refusés, les badges du poste et les visiteurs qui attendent leur hôte', async () => {
    const { repo } = await desk([visitor('v1', 'Koné Mariam'), visitor('v2', 'Yao Brou'), visitor('v3', 'Diallo Issa', { source: 'non listé', host_validation: 'en_attente' }), visitor('v4', 'Traoré Jean', { source: 'non listé', host_validation: 'refuse' })]);
    const empty = await repo.deskSummary();
    expect(empty).toMatchObject({ accueillis: 0, refuses: 0, badges: { restants: 3, total: 3 }, pending: [], recent: [], asOf: null });
    expect(empty.waiting).toEqual([{ id: 'v3', name: 'Diallo Issa' }]); // le refusé par l'hôte n'attend plus

    await repo.saveIdentity('o1', 'v1', new Blob(['photo'])); await repo.validate('o2', 'v1'); await repo.assignBadge('o3', 'v1', 'B-001'); await repo.enter('o4', 'v1', 1000);
    await repo.saveIdentity('o5', 'v2', new Blob(['photo'])); await repo.refuse('o6', 'v2', 'Pièce illisible');
    const s = await repo.deskSummary();
    expect(s.accueillis).toBe(1); expect(s.refuses).toBe(1);
    expect(s.badges).toEqual({ restants: 2, total: 3 }); // B-900 n'est pas à ce poste, B-777 est réservé à un car
    expect(s.recent.map(r => `${r.kind}:${r.name}`)).toEqual(['refus:Yao Brou', 'badge:Koné Mariam']); // du plus récent au plus ancien
    expect(Object.fromEntries(s.pending.map(p => [p.type, p.count]))).toEqual({ identite: 2, valider: 1, badge: 1, entree: 1, refuser: 1 });
  });

  it('ne fait pas attendre un visiteur déjà entré et ne déchiffre que les dernières opérations affichées', async () => {
    const { repo, store } = await desk([visitor('v3', 'Diallo Issa', { source: 'non listé', host_validation: 'en_attente', status: 'present' })]);
    expect((await repo.deskSummary()).waiting).toEqual([]);
    const spy = vi.spyOn(crypto.subtle, 'decrypt');
    const { recent } = await store.deskOps(['badge'], 3);
    expect(recent).toEqual([]); expect(spy).not.toHaveBeenCalled();
    spy.mockRestore();
  });
});

const summary = (over: Partial<DeskSummary> = {}): DeskSummary => ({
  accueillis: 48, refuses: 1, badges: { restants: 112, total: 150 }, waiting: [{ id: 'v3', name: 'Diallo Issa' }, { id: 'v4', name: 'Bamba Salif' }], pending: [], lowBadges: false, offlineCallMinutes: 15, recent: [{ kind: 'badge', name: 'Diallo Issa', at: '2027-01-10T10:41:00Z' }], asOf: '2027-01-10T10:42:00Z', ...over,
});

describe('panneau du poste', () => {
  it('affiche les chiffres, la barre de badges et les visiteurs qui attendent, qu’on peut reprendre', async () => {
    const w = mount(DeskPanel, { props: { summary: summary(), online: true } });
    expect(w.get('[data-testid=chiffre-accueillis]').text()).toContain('48'); expect(w.get('[data-testid=chiffre-refuses]').text()).toContain('1');
    expect(w.get('[data-testid=badges-poste]').text()).toContain('112 restants sur 150');
    expect(w.get('[role=progressbar]').attributes('aria-valuenow')).toBe('112');
    expect(w.find('[data-testid=badges-bas]').exists()).toBe(false);
    expect(w.get('[data-testid=attente-hote]').text()).toContain('2 visiteurs attendent leur hôte');
    await w.get('[data-testid=reprendre-v4]').trigger('click');
    expect(w.emitted('select')).toEqual([['v4']]);
    expect(w.get('[data-testid=derniers-passages]').text()).toContain('Badge remis à Diallo Issa');
    expect(w.find('[data-testid=a-envoyer]').exists()).toBe(false);
    expect(w.find('[data-testid=chiffres-arretes]').exists()).toBe(false);
  });

  it('prévient quand il reste peu de badges et détaille les opérations à envoyer, sans réseau', async () => {
    const w = mount(DeskPanel, { props: { summary: summary({ badges: { restants: 4, total: 150 }, lowBadges: true, waiting: [], pending: [{ type: 'badge', count: 4 }, { type: 'groupe_badge', count: 1 }, { type: 'groupe_depart', count: 1 }, { type: 'refuser', count: 1 }] }), online: false } });
    expect(w.get('[data-testid=badges-bas]').text()).toContain('Il reste peu de badges');
    expect(w.get('[data-testid=a-envoyer-total]').text()).toBe('7');
    expect(w.get('[data-testid=a-envoyer]').text()).toContain('Badges remis4'); expect(w.get('[data-testid=a-envoyer]').text()).toContain('Opérations de groupe2'); expect(w.get('[data-testid=a-envoyer]').text()).toContain('Accès refusés1');
    expect(w.get('[data-testid=reessayer]').attributes('disabled')).toBeDefined();
    expect(w.get('[data-testid=chiffres-arretes]').text()).toContain('Chiffres calculés sur la copie de cette tablette');
    expect(w.find('[data-testid=attente-hote]').exists()).toBe(false);
  });

  it('propose l’envoi immédiat quand le réseau est revenu', async () => {
    const w = mount(DeskPanel, { props: { summary: summary({ pending: [{ type: 'entree', count: 1 }] }), online: true } });
    await w.get('[data-testid=reessayer]').trigger('click');
    expect(w.emitted('sync')).toHaveLength(1);
  });
});

describe('barre d’état du poste', () => {
  const sync = (over = {}) => ({ online: true, syncing: false, pending: 0, conflicts: 0, lastSyncAt: '2027-01-10T10:42:00Z', lastError: null, needsLogin: false, revoked: false, stale: false, clockWarning: false, ...over });
  it('rassure l’agent hors ligne et confirme que la liste est à jour en ligne', () => {
    const off = mount(StatusBar, { props: { poste: 'Poste fictif A', agent: 'Agent fictif', online: false, pending: 3, sync: sync() } });
    expect(off.get('[data-testid=bandeau-reseau]').text()).toContain('Vous continuez à travailler');
    expect(off.text()).toContain('Hors ligne'); expect(off.text()).not.toContain('En ligne'); expect(off.find('[data-testid=liste-a-jour]').exists()).toBe(true);
    const on = mount(StatusBar, { props: { poste: 'Poste fictif A', agent: 'Agent fictif', online: true, pending: 0, sync: sync() } });
    expect(on.find('[data-testid=bandeau-reseau]').exists()).toBe(false);
    expect(on.get('[data-testid=liste-a-jour]').text()).toContain('Liste des visiteurs à jour');
    const old = mount(StatusBar, { props: { poste: 'Poste fictif A', agent: 'Agent fictif', online: true, pending: 0, sync: sync({ stale: true }) } });
    expect(old.find('[data-testid=liste-a-jour]').exists()).toBe(false); expect(old.find('[data-testid=liste-ancienne]').exists()).toBe(true);
  });
});

describe('seuils réglables du poste', () => {
  it('signale « peu de badges » selon les réglages d’Administration, pas selon des constantes', async () => {
    const few = await desk([visitor('v1', 'Koné Mariam')]); // 3 badges au poste, réglages par défaut : 10 % ou 5 badges
    expect((await few.repo.deskSummary()).lowBadges).toBe(true);
    const relaxed = await desk([visitor('v1', 'Koné Mariam')], { low_badges_min: 0, low_badges_percent: 10 });
    const s = await relaxed.repo.deskSummary();
    expect(s.lowBadges).toBe(false); expect(s.offlineCallMinutes).toBe(15);
    const strict = await desk([visitor('v1', 'Koné Mariam')], { low_badges_min: 3, offline_call_minutes: 7 });
    expect(await strict.repo.deskSummary()).toMatchObject({ lowBadges: true, offlineCallMinutes: 7 });
  });

  it('invite l’agent à prévenir le superviseur quand le réseau manque depuis le délai réglé', () => {
    const props = { poste: 'Poste fictif A', agent: 'Agent fictif', online: false, pending: 0 };
    expect(mount(StatusBar, { props: { ...props, offlineMinutes: 14, callAfter: 15 } }).find('[data-testid=appel-superviseur]').exists()).toBe(false);
    const w = mount(StatusBar, { props: { ...props, offlineMinutes: 15, callAfter: 15 } });
    expect(w.get('[data-testid=appel-superviseur]').text()).toContain('Sans réseau depuis 15 minutes : prévenez le superviseur.');
    expect(w.text()).not.toContain('En ligne'); // le texte « Hors ligne » reste celui de la pastille
    expect(mount(StatusBar, { props: { ...props, online: true, offlineMinutes: null } }).find('[data-testid=appel-superviseur]').exists()).toBe(false);
  });
});

describe('tablette restée trop longtemps sans réseau (audit global F2)', () => {
  async function tablet(hoursAgo: number, maxHours: number) {
    const offline = (async () => { throw new TypeError('hors réseau'); }) as unknown as typeof fetch;
    const runtime = new PosteRuntime(undefined, { dbName: `f2-${Math.random()}`, iterations: 1000, fetcher: offline });
    await runtime.vault.enrol({ token: 'd'.repeat(64), secret: toB64(randomBytes(32)), pin: '482915', agent: { id: 'u1', name: 'Agent fictif' }, poste: { id: 'p1', name: 'Poste A' }, eventEndsAt: null, settings: { inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15, offline_max_hours: maxHours } });
    await runtime.store.saveSnapshot(snapshot([visitor('v1', 'Koné Mariam')]));
    await setMeta(runtime.db, 'lastSyncAt', new Date(Date.now() - hoursAgo * 3_600_000).toISOString());
    runtime.lock();
    return runtime;
  }

  it('refuse de rouvrir sans réseau après le délai réglé, garde le PIN valable et ne perd rien', async () => {
    const runtime = await tablet(20, 12);
    expect(await runtime.syncRequiredHours()).toBe(12);
    await expect(runtime.unlock('482915')).rejects.toMatchObject({ code: 'synchronisation_requise', message: expect.stringContaining('plus de 12 heures') });
    expect(runtime.vault.unlocked).toBe(false);
    expect(await runtime.state()).toBe('verrouille'); // verrouillée, pas effacée : les opérations en attente sont intactes
  });

  it('rouvre normalement dans le délai, et le réglage à 0 désactive le blocage', async () => {
    const recent = await tablet(1, 12);
    expect(await recent.syncRequiredHours()).toBeNull();
    await recent.unlock('482915'); expect(recent.vault.unlocked).toBe(true); recent.lock();
    const disabled = await tablet(500, 0);
    expect(await disabled.syncRequiredHours()).toBeNull();
    await disabled.unlock('482915'); expect(disabled.vault.unlocked).toBe(true); disabled.lock();
  });
});
