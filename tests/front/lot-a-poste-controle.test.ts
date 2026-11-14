import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ControleApp from '../../resources/js/components/poste/ControleApp.vue';
import { LocalList, type Snapshot } from '../../resources/js/poste/local/list';
import { OP_POLICY, posteGate } from '../../resources/js/poste/local/capabilities';
import { checkpointsOf, ownPointOf } from '../../resources/js/poste/local/site';
import type { SitePosteRepository } from '../../resources/js/poste/repository';
import type { ControlResult } from '../../resources/js/poste/types';

vi.mock('../../resources/js/poste/media', () => ({
  PHOTO_MAX_SIDE: 640, openCamera: vi.fn(), closeCamera: vi.fn(), captureJpeg: vi.fn(),
  startScan: vi.fn(async () => ({ stop: vi.fn() })),
}));

const T0 = Date.parse('2027-01-10T10:00:00Z');
const snap = (over: Partial<Snapshot['poste']> = {}, withEvent = false): Snapshot => ({
  version: 'v', server_time: new Date(T0).toISOString(), poste: { id: 'p1', name: 'Tablette 1', station: 'Poste 1', event_id: withEvent ? 'e1' : null, point_code: 'poste_1', capabilities: ['scan'], ...over },
  event: withEvent ? { id: 'e1', name: 'Activité fictive', starts_at: new Date(T0 - 3_600_000).toISOString(), ends_at: new Date(T0 + 3_600_000).toISOString() } : null,
  settings: { offline_trust_hours: 12, inactivity_minutes: 5, clock_skew_seconds: 300, pin_max_failures: 15 }, categories: [], zones: withEvent ? [{ id: 'z1', name: 'Hall', est_accueil: false }] : [], category_zone: [], hosts: [],
  postes: withEvent ? [{ id: 'p1', name: 'Poste 1' }, { id: 'p2', name: 'Poste 2' }] : [], visitors: [], badges: [],
} as never);

describe('point de contrôle officiel de la tablette (lot A)', () => {
  it('sans activité, la copie ne porte ni zone ni autre poste : le seul point est le poste de la tablette (cause du « Où effectuez-vous les contrôles ? » vide)', () => {
    const list = new LocalList(snap());
    expect(list.snapshot.zones).toEqual([]); expect(list.snapshot.postes).toEqual([]);
    expect(checkpointsOf(list)).toEqual([{ type: 'poste', id: 'p1', name: 'Poste 1' }]);
    expect(ownPointOf(list)).toEqual({ type: 'poste', id: 'p1', name: 'Poste 1' });
  });

  it('une tablette sans affectation valide n’a aucun point', () => {
    const list = new LocalList(snap({ point_code: null, capabilities: [] }));
    expect(ownPointOf(list)).toBeNull(); expect(checkpointsOf(list)).toEqual([]);
  });

  it('avec activité, la liste des zones et des postes de l’activité reste celle de la copie', () => {
    const list = new LocalList(snap({}, true));
    expect(checkpointsOf(list).map(c => c.type + ':' + c.name)).toEqual(['zone:Hall', 'poste:Poste 1', 'poste:Poste 2']);
    expect(ownPointOf(list)?.id).toBe('p1');
  });
});

describe('capacité de scan aux cinq postes, différences conservées (configuration livrée par la migration)', () => {
  const FIVE: [string, string[], boolean][] = [['poste_1', ['scan'], false], ['poste_2', ['accueil', 'scan', 'sortie'], false], ['poste_3', ['scan'], false], ['poste_4', ['scan'], false], ['soute', ['scan'], true]];
  it.each(FIVE)('%s : le scan est permis, l’accueil et la sortie seulement là où la capacité existe', (code, caps) => {
    const list = new LocalList(snap({ point_code: code, capabilities: caps }));
    expect(posteGate(list, OP_POLICY.controle, T0, new Date(T0).toISOString(), { offline: false })).toBeNull();
    expect(posteGate(list, OP_POLICY.sortie, T0, null, { offline: false })?.reason).toBe(caps.includes('sortie') ? undefined : 'poste_non_habilite');
    expect(posteGate(list, OP_POLICY.valider, T0, null, { offline: false })?.reason).toBe(caps.includes('accueil') ? undefined : 'poste_non_habilite');
  });
  it('une tablette sans poste (hôtel) ne scanne toujours pas', () => {
    const list = new LocalList(snap({ point_code: 'hotel', capabilities: [] }));
    expect(posteGate(list, OP_POLICY.controle, T0, null, { offline: false })?.reason).toBe('poste_non_habilite');
    expect(posteGate(new LocalList(snap({ point_code: null, capabilities: [] })), OP_POLICY.controle, T0, null, { offline: false })?.reason).toBe('poste_non_identifie');
  });
});

const valid: ControlResult = { valid: true, reason: null, message: 'ok', badge_code: 'ID-1', zone_ok: null, point: 'Poste 1', person: { name: 'Personne fictive' }, organisation: null, category: null, zones: [], source: 'serveur' };
function repo(over: Partial<SitePosteRepository> = {}) {
  const control = vi.fn(async () => valid);
  const base = { checkpoints: async () => [{ type: 'poste' as const, id: 'p1', name: 'Poste 1' }], control, exit: vi.fn(), presentCount: vi.fn(async () => 0), pendingCount: async () => 0, capabilities: () => ['scan'],
    ownPoint: () => ({ type: 'poste' as const, id: 'p1', name: 'Poste 1' }), ...over };
  return base as unknown as SitePosteRepository & { control: typeof control };
}

describe('écran de contrôle : plus de choix redondant du poste', () => {
  beforeEach(() => { window.localStorage.clear(); });

  it('ouvre directement le contrôle sur le poste de la tablette et le juge comme « ce poste » (point null)', async () => {
    const r = repo();
    const w = mount(ControleApp, { props: { agent: 'Agent fictif', repository: r } });
    await flushPromises();
    expect(w.find('[data-testid=choix-point]').exists()).toBe(false);
    expect(w.get('[data-testid=point]').text()).toBe('Poste 1');
    await w.get('[data-testid=code-manuel]').setValue('ID-1');
    await w.get('form[aria-label="Saisir le code du badge"]').trigger('submit');
    await flushPromises();
    expect(r.control).toHaveBeenCalledWith(expect.any(String), 'ID-1', null, undefined);
    expect(w.get('[data-testid=verdict]').text()).toContain('ACCÈS AUTORISÉ');
    expect(window.localStorage.length).toBe(0); // rien n'est retenu dans le navigateur
  });

  it('refuse tout contrôle et explique quoi faire quand la tablette n’a aucune affectation valide', async () => {
    const r = repo({ ownPoint: () => null, checkpoints: async () => [] });
    const w = mount(ControleApp, { props: { agent: 'Agent fictif', repository: r } });
    await flushPromises();
    expect(w.get('[data-testid=tablette-sans-poste]').text()).toContain('n’est affectée à aucun poste');
    expect(w.find('[data-testid=code-manuel]').exists()).toBe(false);
    expect(w.find('[data-testid=choix-point]').exists()).toBe(false);
    expect(r.control).not.toHaveBeenCalled();
  });

  it('ignore un ancien point retenu par le navigateur : le poste officiel prime', async () => {
    window.localStorage.setItem('ikoma.controle.point', JSON.stringify({ type: 'zone', id: 'z-ancien' }));
    const r = repo({ checkpoints: async () => [{ type: 'zone' as const, id: 'z-ancien', name: 'Ancienne zone' }, { type: 'poste' as const, id: 'p1', name: 'Poste 1' }] });
    const w = mount(ControleApp, { props: { agent: 'Agent fictif', repository: r } });
    await flushPromises();
    expect(w.get('[data-testid=point]').text()).toBe('Poste 1');
  });

  it('autre zone de l’activité : durée de la session seulement, puis retour au poste de la tablette', async () => {
    const r = repo({ checkpoints: async () => [{ type: 'zone' as const, id: 'z1', name: 'Hall' }, { type: 'poste' as const, id: 'p1', name: 'Poste 1' }] });
    const w = mount(ControleApp, { props: { agent: 'Agent fictif', repository: r } });
    await flushPromises();
    await w.get('[data-testid=changer-point]').trigger('click');
    await w.findAll('button').find(b => b.text().includes('Hall'))!.trigger('click');
    await flushPromises();
    expect(w.get('[data-testid=point]').text()).toBe('Hall');
    expect(window.localStorage.length).toBe(0);
    await w.get('[data-testid=code-manuel]').setValue('ID-1'); await w.get('form[aria-label="Saisir le code du badge"]').trigger('submit'); await flushPromises();
    expect(r.control).toHaveBeenLastCalledWith(expect.any(String), 'ID-1', { type: 'zone', id: 'z1' }, undefined);
    await w.get('[data-testid=resultat]').trigger('click');
    await w.get('[data-testid=revenir-poste]').trigger('click');
    expect(w.get('[data-testid=point]').text()).toBe('Poste 1');
  });
});
