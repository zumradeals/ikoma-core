import 'fake-indexeddb/auto';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toB64 } from '../../resources/js/poste/local/crypto';
import { LocalEvacuation, WIPE_AFTER_EVENT_MS, type Evacuation } from '../../resources/js/pilotage/evacuation';
import { PinError } from '../../resources/js/poste/local/vault';

const post = vi.fn(); const reload = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
  router: { post: (...a: unknown[]) => post(...a), reload: (...a: unknown[]) => reload(...a), get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  usePage: () => ({ props: { errors: {} } }),
}));
import AlertCard from '../../resources/js/components/pilotage/AlertCard.vue';
import CancelBadge from '../../resources/js/components/pilotage/CancelBadge.vue';
import { usePolling } from '../../resources/js/pilotage/usePolling';

const PIN = '583614';
let n = 0;
const data = (over: Partial<Evacuation> = {}): Evacuation => ({
  event: { id: 'e1', name: 'Événement fictif', ends_at: new Date(Date.now() + 86_400_000).toISOString() }, total: 1, as_of: '2027-01-10T10:00:00.000000Z',
  zones: [{ name: 'Accueil', count: 1, people: [{ id: 'v1', full_name: 'Koné Secret Fictif', organisation: 'Organisation Cachée Fictive', group: null, zone: 'Accueil', entered_at: '2027-01-10T09:30:00Z', category: { id: 'c1', name: 'Visiteur', color: '#0F5AA6', priority_circuit: false } }] }], ...over,
});

describe('copie locale chiffrée de la liste d’évacuation', () => {
  const local = () => new LocalEvacuation({ name: `evac-${++n}`, iterations: 1000 });

  it('conserve la liste chiffrée, la relit avec le PIN et rien sans lui', async () => {
    const evac = local();
    expect(await evac.state()).toBe('vide');
    await evac.create(PIN, { id: 'u1', name: 'Superviseur fictif' }, data().event.ends_at);
    await evac.save(data());
    evac.lock();
    expect(await evac.state()).toBe('verrouille');
    await expect(evac.load()).rejects.toBeInstanceOf(PinError); // verrouillé : rien n'est lisible
    await expect(evac.unlock('000111')).rejects.toMatchObject({ code: 'pin_incorrect' });
    await evac.unlock(PIN);
    const stored = await evac.load();
    expect(stored?.data.zones[0].people[0].full_name).toBe('Koné Secret Fictif');
    expect(stored?.knownAt).toBe('2027-01-10T10:00:00.000000Z');
    // aucune donnée lisible dans la base
    const all = JSON.stringify(await evac.db.chunks.toArray().then(rows => rows.map(r => toB64(r.data) + r.id))) + JSON.stringify(await evac.db.meta.toArray().then(rows => rows.map(r => ({ ...r, value: r.value instanceof Uint8Array ? toB64(r.value) : typeof r.value === 'object' && r.value !== null && !('algorithm' in r.value) ? r.value : 'x' }))));
    for (const secret of ['Koné', 'Koné Secret', 'Organisation Cachée', PIN, 'Superviseur fictif']) expect(all, secret).not.toContain(secret);
  });

  it('efface après 15 PIN faux et à la déconnexion, et reconnaît un événement terminé depuis plus de 24 h', async () => {
    const evac = local();
    await evac.create(PIN, { id: 'u1', name: 'Superviseur fictif' }, data().event.ends_at);
    await evac.save(data());
    expect(await evac.expired()).toBe(false);
    expect(await evac.expired(Date.parse(data().event.ends_at) + WIPE_AFTER_EVENT_MS - 1000)).toBe(false);
    expect(await evac.expired(Date.parse(data().event.ends_at) + WIPE_AFTER_EVENT_MS + 1000)).toBe(true);
    evac.lock();
    const clock = { t: Date.now() };
    const timed = new LocalEvacuation({ name: `evac-t-${++n}`, iterations: 1000, now: () => clock.t });
    await timed.create(PIN, { id: 'u1', name: 'S' }, null); await timed.save(data()); timed.lock();
    for (let i = 1; i <= 14; i++) { await expect(timed.unlock('999111')).rejects.toBeDefined(); clock.t += 301_000; }
    await expect(timed.unlock('999111')).rejects.toMatchObject({ code: 'efface' });
    expect(await timed.state()).toBe('vide'); expect(await timed.db.chunks.count()).toBe(0);
    await evac.wipe();
    expect(await evac.state()).toBe('vide');
  });
});

describe('composants du pilotage', () => {
  beforeEach(() => { post.mockClear(); reload.mockClear(); });

  it('affiche gravité en mot et symbole, lien vers l’élément, et prend l’alerte en compte', async () => {
    const wrapper = mount(AlertCard, { props: { alert: { id: 'a1', type: 'badge_annule', severity: 'haute', message: 'Badge annulé : la personne est à rebadger par l’accueil.', created_at: '2027-01-10T10:00:00Z', acknowledged_at: null, resolved_at: null, link: '/pilotage/visiteurs/v1' } } });
    expect(wrapper.text()).toContain('Haute'); expect(wrapper.text()).toContain('▲'); expect(wrapper.text()).toContain('Badge annulé');
    expect(wrapper.get('a').attributes('href')).toBe('/pilotage/visiteurs/v1');
    await wrapper.get('[data-testid="prendre-en-compte"]').trigger('click');
    expect(post).toHaveBeenCalledWith('/pilotage/alertes/a1/prendre-en-compte', {}, expect.objectContaining({ preserveScroll: true }));
    const done = mount(AlertCard, { props: { alert: { id: 'a2', type: 'refus', severity: 'critique', message: 'x', created_at: '2027-01-10T10:00:00Z', acknowledged_at: '2027-01-10T10:01:00Z', resolved_at: null, link: null } } });
    expect(done.find('[data-testid="prendre-en-compte"]').exists()).toBe(false); expect(done.text()).toContain('prise en compte'); expect(done.text()).toContain('Critique');
  });

  it('exige un motif puis une confirmation explicite avant d’annuler un badge', async () => {
    const wrapper = mount(CancelBadge, { props: { visitorId: 'v1' } });
    await wrapper.get('[data-testid="annuler-debut"]').trigger('click');
    expect(wrapper.get('[data-testid="annuler-suite"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid="annuler-motif"]').setValue('Perdu');
    await wrapper.get('[data-testid="annuler-suite"]').trigger('click');
    expect(post).not.toHaveBeenCalled(); // la confirmation n'est pas encore donnée
    expect(wrapper.text()).toContain('définitive');
    await wrapper.get('[data-testid="annuler-confirmer"]').trigger('click');
    expect(post).toHaveBeenCalledWith('/pilotage/badges/annuler', expect.objectContaining({ visitor_id: 'v1', motif: 'Perdu', confirmation: true }), expect.anything());
    const byCode = mount(CancelBadge, { props: { eventId: 'e1' } });
    await byCode.get('[data-testid="annuler-debut"]').trigger('click');
    await byCode.get('[data-testid="annuler-motif"]').setValue('Défectueux');
    expect(byCode.get('[data-testid="annuler-suite"]').attributes('disabled')).toBeDefined(); // il faut aussi le code
    await byCode.get('[data-testid="annuler-code"]').setValue('b-014');
    expect(byCode.get('[data-testid="annuler-suite"]').attributes('disabled')).toBeUndefined();
  });

  it('recharge seulement les propriétés demandées, s’arrête quand l’onglet est caché et reprend au retour', async () => {
    vi.useFakeTimers();
    const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(false);
    const wrapper = mount({ template: '<p>x</p>', setup() { usePolling(['counters', 'alerts'], 3000); return {}; } });
    reload.mockImplementation((options: { onFinish?: () => void }) => options.onFinish?.());
    await vi.advanceTimersByTimeAsync(3100);
    expect(reload).toHaveBeenCalledTimes(1); expect(reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['counters', 'alerts'] }));
    hidden.mockReturnValue(true); document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(10_000);
    expect(reload).toHaveBeenCalledTimes(1); // caché : plus aucune requête
    hidden.mockReturnValue(false); document.dispatchEvent(new Event('visibilitychange'));
    expect(reload).toHaveBeenCalledTimes(2); // retour : rechargement immédiat
    await vi.advanceTimersByTimeAsync(3100);
    expect(reload).toHaveBeenCalledTimes(3);
    wrapper.unmount();
    await vi.advanceTimersByTimeAsync(10_000);
    expect(reload).toHaveBeenCalledTimes(3);
    vi.useRealTimers(); hidden.mockRestore(); await flushPromises();
  });
});
