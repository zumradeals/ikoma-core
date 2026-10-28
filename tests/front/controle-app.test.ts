import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ControleApp from '../../resources/js/components/poste/ControleApp.vue';
import type { SitePosteRepository } from '../../resources/js/poste/repository';
import type { ControlResult, ExitResult } from '../../resources/js/poste/types';

const scans: { emit: (text: string) => void }[] = [];
vi.mock('../../resources/js/poste/media', () => ({
  PHOTO_MAX_SIDE: 640, openCamera: vi.fn(), closeCamera: vi.fn(), captureJpeg: vi.fn(),
  startScan: vi.fn(async (_video: HTMLVideoElement, onCode: (text: string) => void) => { scans.push({ emit: onCode }); return { stop: vi.fn() }; }),
}));

const valid: ControlResult = { valid: true, reason: null, message: 'Badge valide.', badge_code: 'B-001', zone_ok: false, point: 'Espace VIP', person: { name: 'Koné Mariam' }, organisation: 'Organisation fictive', category: { id: 'c1', name: 'Visiteur', color: '#0F5AA6', priority_circuit: false }, zones: ['Hall', 'Salle'], source: 'local', known_at: '2027-01-10T09:30:00Z' };
const refused: ControlResult = { ...valid, valid: false, reason: 'annule', message: 'Badge annulé : accès refusé.', person: null, zone_ok: null, known_at: null, source: 'serveur' };
const left: ExitResult = { ok: true, badge_code: 'B-001', badge_status: 'Clos', person: { name: 'Koné Mariam' }, presents: 3, source: 'serveur' };

function repo(over: Partial<SitePosteRepository> = {}) {
  const calls: string[] = [];
  const base = {
    checkpoints: async () => [{ type: 'zone' as const, id: 'z1', name: 'Hall' }, { type: 'poste' as const, id: 'p1', name: 'Poste A' }],
    control: vi.fn(async (_op: string, code: string) => { calls.push('controle:' + code); return code.toUpperCase() === 'B-404' ? refused : valid; }),
    exit: vi.fn(async (_op: string, code: string) => { calls.push('sortie:' + code); return left; }),
    presentCount: vi.fn(async () => 7), pendingCount: async () => 0, calls,
    ...over,
  };
  return base as unknown as SitePosteRepository & { calls: string[] };
}
const mountApp = (r: SitePosteRepository, sync = null) => mount(ControleApp, { props: { agent: 'Agent fictif', repository: r, sync } });

describe('écran de contrôle', () => {
  beforeEach(() => { window.localStorage.clear(); scans.length = 0; });

  it('demande le point de contrôle, le retient, puis affiche le compteur de présents', async () => {
    const wrapper = mountApp(repo());
    await flushPromises();
    expect(wrapper.find('[data-testid="choix-point"]').text()).toContain('Où effectuez-vous les contrôles');
    await wrapper.findAll('button').find(b => b.text().includes('Hall'))!.trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="point"]').text()).toBe('Hall');
    expect(wrapper.get('[data-testid="presents"]').text()).toContain('Présents : 7');
    expect(scans).toHaveLength(1); // la caméra s'ouvre dès le choix du point
    const again = mountApp(repo());
    await flushPromises();
    expect(again.get('[data-testid="point"]').text()).toBe('Hall'); // retenu sur l'appareil
  });

  it('affiche un verdict plein écran avec icône et mot, la zone non autorisée et l’heure de la situation connue', async () => {
    const r = repo();
    const wrapper = mountApp(r);
    await flushPromises();
    await wrapper.findAll('button').find(b => b.text().includes('Hall'))!.trigger('click');
    await flushPromises();
    scans[0].emit('IKOMA1:' + 'ab'.repeat(32));
    await flushPromises();
    const overlay = wrapper.get('[data-testid="resultat"]');
    expect(overlay.attributes('data-verdict')).toBe('valide');
    expect(wrapper.get('[data-testid="verdict"]').text()).toContain('✓');
    expect(wrapper.get('[data-testid="verdict"]').text()).toContain('VALIDE');
    expect(wrapper.get('[data-testid="nom"]').text()).toBe('Koné Mariam');
    expect(wrapper.get('[data-testid="zones"]').text()).toContain('Hall, Salle');
    expect(wrapper.get('[data-testid="zone-non-autorisee"]').text()).toContain('ZONE NON AUTORISÉE : Espace VIP');
    expect(wrapper.get('[data-testid="connu-a"]').text()).toContain('situation connue à');
    expect(Number(overlay.attributes('data-latency-ms'))).toBeLessThan(1000);
    // tant que le résultat est affiché, une autre lecture est ignorée
    scans[0].emit('B-002'); await flushPromises();
    expect(r.control).toHaveBeenCalledTimes(1);
    await wrapper.get('[data-testid="suivant"]').trigger('click');
    expect(wrapper.find('[data-testid="resultat"]').exists()).toBe(false);
  });

  it('affiche NON VALIDE en rouge avec la raison, et la sortie avec le compteur', async () => {
    const wrapper = mountApp(repo());
    await flushPromises();
    await wrapper.findAll('button').find(b => b.text().includes('Poste A'))!.trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="code-manuel"]').setValue('b-404');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[data-testid="resultat"]').attributes('data-verdict')).toBe('refuse');
    expect(wrapper.get('[data-testid="resultat"]').classes()).toContain('bg-red-700');
    expect(wrapper.get('[data-testid="verdict"]').text()).toContain('✕');
    expect(wrapper.get('[data-testid="verdict"]').text()).toContain('NON VALIDE');
    expect(wrapper.get('[data-testid="raison"]').text()).toBe('Badge annulé');
    await wrapper.get('[data-testid="suivant"]').trigger('click');
    await wrapper.get('[data-testid="mode-sortie"]').trigger('click');
    await wrapper.get('[data-testid="code-manuel"]').setValue('B-001');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[data-testid="verdict"]').text()).toContain('SORTIE ENREGISTRÉE');
    expect(wrapper.text()).toContain('Personnes présentes : 3');
    expect(wrapper.text()).toContain('clos (usage unique)');
  });

  it('affiche une erreur claire si le contrôle échoue, sans résultat trompeur', async () => {
    const wrapper = mountApp(repo({ control: vi.fn(async () => { throw new Error('boom'); }) as never }));
    await flushPromises();
    await wrapper.findAll('button').find(b => b.text().includes('Hall'))!.trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="code-manuel"]').setValue('B-001');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[data-testid="resultat"]').exists()).toBe(false);
    expect(wrapper.get('[data-testid="erreur"]').text()).toContain('Une erreur est survenue');
  });
});
