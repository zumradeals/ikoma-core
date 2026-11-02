import { mount } from '@vue/test-utils';
import { flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { searchPermanentDirectory, cardLabel, type PermanentSnapshot } from '../../resources/js/poste/local/permanent';
import PermanentSearch from '../../resources/js/components/poste/PermanentSearch.vue';

const NOW = Date.parse('2027-01-10T10:00:00Z');
const ZONES = [{ id: 'z1', name: 'Zone principale' }, { id: 'z2', name: 'Zone VIP' }];
const snap: PermanentSnapshot = {
  trust_minutes: 0, cards: [], policies: [],
  directory: [
    { id: 'h1', identifier: 'ID-000001-8', name: 'Aminata Koné', organisation: 'Organisation fictive', category: { id: 'c1', name: 'Personnel', color: '#2E7D32', priority_circuit: false }, card: 'active' },
    { id: 'h2', identifier: 'ID-000002-6', name: 'Moussa Traoré', organisation: null, category: { id: 'c2', name: 'Partenaires', color: '#EF6C00', priority_circuit: false }, card: 'non_activee' },
    { id: 'h3', identifier: 'ID-000003-4', name: 'Awa Diabaté', organisation: null, category: null, card: 'perdue' },
  ],
  holders: [{ id: 'h1', identifier: 'ID-000001-8', name: 'Aminata Koné', organisation: null, status: 'actif', starts_on: null, ends_on: null,
    entitlements: [{ zone_id: 'z1', valid_from: '2027-01-01T00:00:00Z', valid_until: '2027-02-01T00:00:00Z' }, { zone_id: 'z2', valid_from: '2026-01-01T00:00:00Z', valid_until: '2026-02-01T00:00:00Z' }] }],
};

describe('recherche d’une personne permanente à l’accueil', () => {
  it('trouve par mot du nom, sans tenir compte des accents ni de la casse, ou par identifiant', () => {
    expect(searchPermanentDirectory(snap, ZONES, 'kone', NOW).map(r => r.id)).toEqual(['h1']);
    expect(searchPermanentDirectory(snap, ZONES, 'TRAORE moussa', NOW).map(r => r.id)).toEqual(['h2']);
    expect(searchPermanentDirectory(snap, ZONES, 'ID-000003', NOW).map(r => r.id)).toEqual(['h3']);
    expect(searchPermanentDirectory(snap, ZONES, 'partenaires', NOW).map(r => r.id)).toEqual(['h2']);
    expect(searchPermanentDirectory(snap, ZONES, '  ', NOW)).toEqual([]);
    expect(searchPermanentDirectory(undefined, ZONES, 'kone', NOW)).toEqual([]);
  });

  it('n’indique que les accès EN COURS et reste en lecture seule (aucune décision, aucun jeton)', () => {
    const [r] = searchPermanentDirectory(snap, ZONES, 'kone', NOW);
    expect(r.zones).toEqual(['Zone principale']);
    expect(Object.keys(r).sort()).toEqual(['card', 'category', 'id', 'identifier', 'name', 'organisation', 'zones']);
    expect(JSON.stringify(searchPermanentDirectory(snap, ZONES, 'a', NOW))).not.toMatch(/token|hash|photo/i);
  });

  it('nomme l’état de la carte en français', () => {
    expect(cardLabel('non_activee')).toBe('Carte pas encore remise');
    expect(cardLabel('perdue')).toBe('Carte perdue');
    expect(cardLabel('aucune')).toBe('Aucune carte');
  });

  it('affiche les résultats avec l’état de la carte et les accès, sans bouton d’action', async () => {
    const repository = { searchPermanent: vi.fn(async (q: string) => searchPermanentDirectory(snap, ZONES, q, NOW)) };
    const wrapper = mount(PermanentSearch, { props: { repository: repository as never } });
    await wrapper.get('[data-testid=chercher-permanent]').setValue('moussa');
    await new Promise(r => setTimeout(r, 250)); await flushPromises();
    expect(wrapper.get('[data-testid="carte-ID-000002-6"]').text()).toContain('Carte pas encore remise');
    expect(wrapper.get('[data-testid="acces-ID-000002-6"]').text()).toContain('Aucun accès en cours');
    expect(wrapper.find('button').exists()).toBe(false);
    await wrapper.get('[data-testid=chercher-permanent]').setValue('zzzz');
    await new Promise(r => setTimeout(r, 250)); await flushPromises();
    expect(wrapper.find('[data-testid=aucun-permanent]').exists()).toBe(true);
  });
});
