import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { auth: { permissions: ['badges.gerer', 'listes.importer', 'evenements.gerer'], name: 'Administrateur fictif' }, badgesCartes: null as unknown } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, router: { get: vi.fn() }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import BadgePreparer from '../../resources/js/components/admin/BadgePreparerPanel.vue';

const events = [{ id: 'e1', name: 'Stage fictif', kind: 'stage', starts_at: '2027-01-01T08:00:00Z', ends_at: '2027-03-01T18:00:00Z' }];
const people = [
  { id: 'p1', name: 'Aminata Fictive', organisation: null, category: 'Stagiaire', state: 'a_generer', code: null, reason: null },
  { id: 'p2', name: 'Ibrahim Fictif', organisation: 'Org', category: 'Stagiaire', state: 'existant', code: 'B-007', reason: null },
  { id: 'p3', name: 'Fatou Fictive', organisation: null, category: 'Stagiaire', state: 'non_eligible', code: null, reason: 'La participation de cette personne est clôturée : aucun badge ne peut lui être préparé.' },
];
const plan = { a_generer: ['p1'], existants: [{ id: 'p2', code: 'B-007' }], non_eligibles: [{ id: 'p3', code: 'participation_cloturee', message: 'La participation de cette personne est clôturée.' }], doublons_ignores: 0 };
const calls: { url: string; init?: RequestInit }[] = [];
function mockFetch(responses: Record<string, unknown>) {
  calls.length = 0;
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, init });
    const key = Object.keys(responses).find(k => url.includes(k));
    return { ok: true, json: async () => (key ? responses[key] : {}) };
  }));
}
const mountPage = () => mount(BadgePreparer, { props: { events, max: 100 } });

describe('Préparer un badge : parcours unique', () => {
  beforeEach(() => { vi.unstubAllGlobals(); });

  it('parcourt personne → détails → génération → impression, sans lot ni réservation, avec un résultat honnête', async () => {
    mockFetch({ '/preparer/personnes': { people }, '/preparer/recapitulatif': { plan }, '/preparer/generer': { complete: true, created: [{ badge_id: 'b1', code: 'B-009', visitor_id: 'p1', holder_id: null }], existing: [], failed: [], printable: ['b1'] } });
    const w = mountPage();
    expect(w.text()).toContain('aucune habilitation');
    expect(w.text()).not.toMatch(/réserver un lot/);
    await w.get('form[aria-label="Rechercher des personnes"]').trigger('submit');
    await flushPromises();
    expect(calls[0].url).toContain('support=badge_temporaire');
    expect(calls[0].url).toContain('event=e1');
    expect(w.get('[data-testid=etat-p1]').text()).toBe('À générer');
    expect(w.get('[data-testid=etat-p2]').text()).toContain('B-007');
    expect(w.get('[data-testid=choisir-p3]').attributes('disabled')).toBeDefined();
    await w.get('[data-testid=tout-eligible]').trigger('click');
    expect(w.get('[data-testid=compte-selection]').text()).toContain('1 sélectionnée');
    await w.get('[data-testid=verifier]').trigger('click');
    await flushPromises();
    expect(JSON.parse(String(calls[1].init?.body))).toEqual({ support: 'badge_temporaire', ids: ['p1'] });
    expect(w.get('[data-testid=nb-a-generer]').text()).toBe('1');
    expect(w.get('[data-testid=nb-existants]').text()).toBe('1');
    expect(w.get('[data-testid=nb-non-eligibles]').text()).toBe('1');
    await w.get('[data-testid=continuer]').trigger('click');
    expect(w.get('[data-testid=nb-confirmation]').text()).toBe('1');
    await w.get('[data-testid=generer]').trigger('click');
    await flushPromises();
    const body = JSON.parse(String(calls[2].init?.body));
    expect(body).toMatchObject({ support: 'badge_temporaire', ids: ['p1'], confirm: true });
    expect(body.operation).toMatch(/^[0-9a-f-]{36}$/);
    expect(w.get('[data-testid=resultat-complet]').text()).toContain('1 support généré');
    expect(w.get('[data-testid=imprimer]').text()).toContain('(1)');
  });

  it('n’annonce jamais une réussite totale quand certains supports n’ont pas été créés', async () => {
    mockFetch({ '/preparer/personnes': { people }, '/preparer/recapitulatif': { plan }, '/preparer/generer': { complete: false, created: [{ badge_id: 'b1', code: 'B-009', visitor_id: 'p1', holder_id: null }], existing: [], failed: [{ id: 'p4', code: 'participation_cloturee', message: 'Clôturée.' }], printable: ['b1'] } });
    const w = mountPage();
    await w.get('form[aria-label="Rechercher des personnes"]').trigger('submit');
    await flushPromises();
    await w.get('[data-testid=tout-eligible]').trigger('click');
    await w.get('[data-testid=verifier]').trigger('click');
    await flushPromises();
    await w.get('[data-testid=continuer]').trigger('click');
    await w.get('[data-testid=generer]').trigger('click');
    await flushPromises();
    expect(w.find('[data-testid=resultat-complet]').exists()).toBe(false);
    expect(w.get('[data-testid=resultat-partiel]').text()).toContain('1 réussite(s), 1 échec(s)');
    expect(w.get('[data-testid=liste-echecs]').text()).toContain('Clôturée.');
  });

  it('propose les titulaires pour la carte permanente, sans choix d’activité, et affiche les erreurs du serveur', async () => {
    mockFetch({ '/preparer/personnes': { people: [] } });
    const w = mountPage();
    await w.get('[data-testid=support-carte]').trigger('change');
    expect(w.find('[data-testid=choix-activite]').exists()).toBe(false);
    await w.get('form[aria-label="Rechercher des personnes"]').trigger('submit');
    await flushPromises();
    expect(calls[0].url).toContain('support=carte_permanente');
    expect(calls[0].url).not.toContain('event=');
    expect(w.find('[data-testid=aucune-personne]').exists()).toBe(true);
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({ errors: { event: ['Choisissez une activité.'] } }) })));
    await w.get('form[aria-label="Rechercher des personnes"]').trigger('submit');
    await flushPromises();
    expect(w.get('[data-testid=erreur-preparation]').text()).toContain('Choisissez une activité.');
  });
});

describe('Préparer un badge : venue d’une fiche d’activité', () => {
  it('ouvre la recherche de l’activité et présélectionne les personnes éligibles transmises', async () => {
    mockFetch({ '/preparer/personnes': { people } });
    const w = mount(BadgePreparer, { props: { events, max: 100, preset: { event: 'e1', ids: ['p1', 'p3', 'inconnu'] } } });
    await flushPromises();
    expect(calls[0].url).toContain('event=e1');
    expect(w.get('[data-testid=compte-selection]').text()).toContain('1 sélectionnée');
  });
});
