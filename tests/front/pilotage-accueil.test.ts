import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
  router: { post: vi.fn(), reload: vi.fn(), get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  usePage: () => ({ props: { errors: {} } }),
}));
import Index from '../../resources/js/pages/Pilotage/Index.vue';

const event = { id: 'e1', name: 'Événement fictif' };
const counters = { attendus: 200, arrives: 50, presents: 40, refuses: 2, as_of: '2027-01-10T10:00:00.000000Z' };
const overview = { critiques: 0, hautes: 0, litiges: 0, cars: { total: 4, partis: 3, arrives: 1, ecarts: 0 }, postes_silencieux: 0 };
const alert = { id: 'a1', type: 'refus', severity: 'haute' as const, message: 'Accès refusé fictif', created_at: '2027-01-10T10:00:00Z', acknowledged_at: null, resolved_at: null, link: '/pilotage/litiges' };
const mountIndex = (over: Record<string, unknown> = {}) => mount(Index, { props: { event, events: [event], openAlerts: 0, counters, alerts: [], overview, ...over } as never });

describe('accueil du superviseur', () => {
  it('annonce le calme en mots quand rien n’est à traiter', () => {
    const w = mountIndex();
    expect(w.get('[data-testid=situation]').attributes('data-level')).toBe('calme');
    expect(w.get('[data-testid=situation-titre]').text()).toBe('Tout est calme');
    expect(w.find('[data-testid=aucune-alerte]').exists()).toBe(true);
    expect(w.get('[data-testid=avancement-arrivees]').text()).toBe('50 arrivés sur 200 attendus (25 %)');
    expect(w.get('[role=progressbar]').attributes('aria-valuenow')).toBe('25');
    expect(w.get('[data-testid=terrain-cars]').text()).toContain('3 partis sur 4 · 1 arrivé');
    expect(w.get('[data-testid=terrain-postes]').text()).toContain('Toutes les tablettes donnent des nouvelles');
  });

  it('met l’alerte critique en premier, avec son nombre en toutes lettres', () => {
    const w = mountIndex({ openAlerts: 3, alerts: [alert], overview: { ...overview, critiques: 1, hautes: 2 } });
    expect(w.get('[data-testid=situation]').attributes('data-level')).toBe('critique');
    expect(w.get('[data-testid=situation-titre]').text()).toBe('3 alertes à traiter');
    expect(w.get('[data-testid=situation]').text()).toContain('1 est critique');
    expect(w.get('[data-testid=alerte-refus]').text()).toContain('Haute');
  });

  it('signale les cas à décider, les cars en écart et les tablettes silencieuses avec une pastille « À voir »', () => {
    const w = mountIndex({ overview: { ...overview, litiges: 2, cars: { total: 2, partis: 2, arrives: 0, ecarts: 1 }, postes_silencieux: 2 } });
    expect(w.get('[data-testid=situation-titre]').text()).toBe('2 cas à décider');
    for (const key of ['litiges', 'cars', 'postes']) {
      expect(w.get(`[data-testid=terrain-${key}]`).attributes('data-warn')).toBe('true');
      expect(w.get(`[data-testid=terrain-${key}]`).text()).toContain('À voir');
    }
    expect(w.get('[data-testid=terrain-postes]').text()).toContain('2 tablettes sans nouvelles');
    expect(w.get('[data-testid=terrain-litiges] a').attributes('href')).toBe('/pilotage/litiges');
  });

  it('garde les quatre compteurs attendus par les parcours existants', () => {
    const w = mountIndex();
    for (const [key, value] of [['attendus', '200'], ['arrives', '50'], ['presents', '40'], ['refuses', '2']]) expect(w.get(`[data-testid=compteur-${key}] [data-testid=valeur]`).text()).toBe(value);
  });
});

describe('alertes du contrôle d’accès', () => {
  it('nomme chaque nouveau type d’alerte en français et garde la gravité en mot et en symbole', async () => {
    const { default: AlertCard } = await import('../../resources/js/components/pilotage/AlertCard.vue');
    const row = (type: string, severity: 'critique' | 'haute' | 'normale') => ({ id: type, type, severity, message: 'Badge B-014 annulé présenté au point « Porte nord ».', created_at: '2027-01-10T10:00:00Z', acknowledged_at: null, resolved_at: null, link: null });
    for (const [type, label] of [['controle_refuse', 'Badge refusé au contrôle'], ['zone_interdite', 'Zone non autorisée'], ['badge_double', 'Badge présenté deux fois'], ['controle_rafale', 'Refus répétés']] as const) {
      const w = mount(AlertCard, { props: { alert: row(type, 'haute') } });
      expect(w.text()).toContain(label); expect(w.text()).toContain('Haute'); expect(w.text()).toContain('▲');
    }
    const critical = mount(AlertCard, { props: { alert: row('controle_refuse', 'critique') } });
    expect(critical.text()).toContain('Critique'); expect(critical.text()).toContain('⛔');
  });
});
