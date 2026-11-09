import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));
const page = vi.hoisted(() => ({ props: { auth: { name: 'Administrateur fictif', permissions: ['badges.gerer', 'listes.importer'] as string[] }, institution: { name: 'AILCT', fullName: 'Institution fictive' }, badgesCartes: null as unknown } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, router: { get: vi.fn(), reload }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import BadgesCartes from '../../resources/js/pages/Admin/BadgesCartes.vue';

import type { SupportRow } from '../../resources/js/components/admin/badge-api';
const rows: SupportRow[] = [
  { id: 'b1', code: 'B-000842', type: 'carte_permanente', state: 'actif', state_label: 'Actif', holder_name: 'Aminata Fictive', category: 'Personnel', event_name: 'Contexte permanent', printed: true },
  { id: 'b2', code: 'B-000844', type: 'badge_temporaire', state: 'restitue', state_label: 'Restitué', holder_name: 'Ibrahim Fictif', category: 'Stagiaire', event_name: 'Stage fictif', printed: false },
];
const registre = { data: rows, meta: { current_page: 1, last_page: 2, total: 9, per_page: 8 } };
const states = { actif: 'Actif', restitue: 'Restitué' };
const events = [{ id: 'e1', name: 'Stage fictif', kind: 'stage', starts_at: '2027-01-01T08:00:00Z', ends_at: '2027-03-01T18:00:00Z' }];
const base = { registre, filters: { q: '', type: '', etat: '' }, states, canPrepare: true, events, max: 100, preset: null };
const view = { name: 'Aminata Fictive', role: 'Agent', identifier: 'B-000842', label: 'Personnel', badge_code: 'B-000842', qr: '<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>', portrait: null, mention: 'PERSONNEL', validity: null, provisional: false,
  institution: { name: 'AILCT', fullName: 'Institution fictive', logo: null }, tone: { color: '#2E7D32', ink: '#ffffff' } };

const calls: { url: string; init?: RequestInit }[] = [];
function mockFetch(responses: Record<string, unknown>, ok = true) {
  calls.length = 0;
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, init });
    const key = Object.keys(responses).find(k => url.includes(k));
    return { ok, status: ok ? 200 : 422, json: async () => (key ? responses[key] : {}) };
  }));
}
const mountPage = (props: object = {}) => mount(BadgesCartes, { props: { ...base, ...props } });

describe('espace de travail « Badges et cartes »', () => {
  beforeEach(() => { vi.unstubAllGlobals(); page.props.auth.permissions = ['badges.gerer', 'listes.importer']; reload.mockClear(); });

  it('affiche d’emblée les trois colonnes : registre, préparation et aperçu, sans l’ancien atelier de lots', () => {
    const w = mountPage();
    expect(w.get('[data-testid=titre-badges-cartes]').text()).toBe('Badges et cartes');
    expect(w.find('[data-testid=colonne-registre]').exists()).toBe(true);
    expect(w.find('[data-testid=page-preparer-badge]').exists()).toBe(true);
    expect(w.find('[data-testid=colonne-apercu]').exists()).toBe(true);
    expect(w.text()).not.toMatch(/Fabriquer|Affecter à un poste/);
    expect(w.get('[data-testid=registre-total]').text()).toBe('(9)');
    expect(w.get('[data-testid=lien-lots]').attributes('href')).toBe('/administration/badges/lots');
    expect(w.get('[data-testid=lien-lots]').text()).toContain('stock existant');
  });

  it('liste cartes et badges dans un même registre, avec type, état et impression, et filtre côté serveur', async () => {
    mockFetch({ '/administration/badges/supports': { ...registre, data: [rows[1]], meta: { ...registre.meta, total: 1, last_page: 1 } } });
    const w = mountPage();
    expect(w.get('[data-testid="support-B-000842"]').text()).toContain('Permanent');
    expect(w.get('[data-testid="support-B-000842"]').text()).toContain('imprimé');
    expect(w.get('[data-testid="support-B-000844"]').text()).toContain('Temporaire');
    expect(w.get('[data-testid="support-B-000844"]').text()).toContain('non imprimé');
    await w.get('[data-testid=registre-type]').setValue('badge_temporaire');
    await flushPromises();
    expect(calls[0].url).toContain('type=badge_temporaire');
    expect(w.findAll('[data-testid^="support-B-"]')).toHaveLength(1);
    await w.get('[data-testid=registre-recherche]').setValue('Fictif');
    await w.get('[data-testid=registre-filtrer]').trigger('click');
    await w.get('form[aria-label="Rechercher un support"]').trigger('submit');
    await flushPromises();
    expect(calls.at(-1)?.url).toContain('q=Fictif');
  });

  it('affiche un état vide explicite et une erreur de registre sans rien inventer', async () => {
    const w = mountPage({ registre: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 8 } } });
    expect(w.get('[data-testid=registre-vide]').text()).toContain('Aucun support');
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) })));
    await w.get('form[aria-label="Rechercher un support"]').trigger('submit');
    await flushPromises();
    expect(w.find('[data-testid=registre-erreur]').exists()).toBe(true);
  });

  it('sélectionner un support affiche son rendu RÉEL (numéro et QR du serveur) et propose la consultation', async () => {
    mockFetch({ '/impression/donnees': { supports: [view] } });
    const w = mountPage();
    await w.get('[data-testid="support-B-000842"]').trigger('click');
    await flushPromises();
    expect(JSON.parse(String(calls[0].init?.body))).toEqual({ badges: ['b1'] });
    expect(w.get('[data-testid=apercu-reel]').text()).toContain('numéro et QR réels');
    expect(w.get('[data-testid=apercu-numero]').text()).toBe('B-000842');
    expect(w.get('[data-testid=apercu-qr]').attributes('src')).toMatch(/^data:image\/svg\+xml;base64,/);
    expect(w.get('[data-testid=consulter-support]').attributes('href')).toBe('/administration/badges/b1');
  });

  it('avant génération, l’aperçu est INDICATIF : ni numéro, ni QR, et aucun support créé', async () => {
    mockFetch({ '/preparer/personnes': { people: [{ id: 'p1', name: 'Aminata Fictive', organisation: null, category: 'Stagiaire', state: 'a_generer', code: null, reason: null, model: { code: 'AILCT-01', label: 'Personnel', color: '#2E7D32', mention: 'PERSONNEL', version: 1, validated: false } }] } });
    const w = mountPage();
    expect(w.find('[data-testid=apercu-vide]').exists()).toBe(true);
    await w.get('form[aria-label="Rechercher des personnes"]').trigger('submit');
    await flushPromises();
    await w.get('[data-testid=choisir-p1]').setValue(true);
    expect(w.get('[data-testid=apercu-indicatif]').text()).toContain('rien n’est encore généré');
    expect(w.find('[data-testid=apercu-qr]').exists()).toBe(false);
    expect(w.get('[data-testid=apercu-qr-absent]').text()).toContain('QR généré à l’émission');
    expect(w.find('[data-testid=apercu-numero]').exists()).toBe(false);
    expect(w.get('[data-testid=info-numero]').text()).toBe('Attribué à la génération');
    expect(calls.every(c => !c.url.includes('/generer'))).toBe(true);
  });

  it('après génération : registre et compteurs rafraîchis, aperçu réel du support créé', async () => {
    const generated = { complete: true, created: [{ badge_id: 'b9', code: 'B-000900', visitor_id: 'p1', holder_id: null }], existing: [], failed: [], printable: ['b9'] };
    mockFetch({
      '/preparer/personnes': { people: [{ id: 'p1', name: 'Aminata Fictive', organisation: null, category: 'Stagiaire', state: 'a_generer', code: null, reason: null, model: null }] },
      '/preparer/recapitulatif': { plan: { a_generer: ['p1'], existants: [], non_eligibles: [], doublons_ignores: 0 } }, '/preparer/generer': generated,
      '/impression/donnees': { supports: [{ ...view, badge_code: 'B-000900' }] }, '/administration/badges/supports': registre,
    });
    const w = mountPage();
    await w.get('form[aria-label="Rechercher des personnes"]').trigger('submit'); await flushPromises();
    await w.get('[data-testid=tout-eligible]').trigger('click');
    await w.get('[data-testid=verifier]').trigger('click'); await flushPromises();
    await w.get('[data-testid=continuer]').trigger('click');
    await w.get('[data-testid=generer]').trigger('click'); await flushPromises();
    expect(reload).toHaveBeenCalledWith({ only: ['badgesCartes'] });
    expect(calls.some(c => c.url.includes('/administration/badges/supports'))).toBe(true);
    expect(w.get('[data-testid=apercu-numero]').text()).toBe('B-000900');
    expect(w.find('[data-testid=resultat-complet]').exists()).toBe(true);
  });

  it('réimpression : le motif est obligatoire et le serveur décide si le support est imprimable', async () => {
    mockFetch({ '/impression/donnees': { supports: [view] }, '/impression/recapitulatif': { supports: [{ printable: true, kind: 'reimpression', refusal: null }] } });
    const w = mountPage();
    await w.get('[data-testid="support-B-000842"]').trigger('click'); await flushPromises();
    await w.get('[data-testid=verifier-impression]').trigger('click'); await flushPromises();
    expect(w.get('[data-testid=impression-genre]').text()).toContain('le numéro et le QR du support sont conservés');
    expect(w.get('[data-testid=lancer-impression]').attributes('disabled')).toBeDefined();
    await w.get('[data-testid=motif-reimpression]').setValue('Carte abîmée');
    expect(w.get('[data-testid=lancer-impression]').attributes('disabled')).toBeUndefined();
  });

  it('un support non imprimable (perdu, révoqué, remplacé) affiche le refus du serveur, sans bouton d’impression', async () => {
    mockFetch({ '/impression/donnees': { supports: [view] }, '/impression/recapitulatif': { supports: [{ printable: false, kind: 'emission', refusal: 'Cette carte est révoquée, perdue, volée ou remplacée : elle ne se réimprime pas.' }] } });
    const w = mountPage();
    await w.get('[data-testid="support-B-000842"]').trigger('click'); await flushPromises();
    await w.get('[data-testid=verifier-impression]').trigger('click'); await flushPromises();
    expect(w.get('[data-testid=impression-refusee]').text()).toContain('ne se réimprime pas');
    expect(w.find('[data-testid=lancer-impression]').exists()).toBe(false);
  });

  it('sans le droit d’importer des listes : registre consultable, préparation et aperçu réel non proposés', async () => {
    page.props.auth.permissions = ['badges.gerer'];
    mockFetch({});
    const w = mountPage({ canPrepare: false, events: [] });
    expect(w.get('[data-testid=preparer-indisponible]').text()).toContain('importer des listes');
    expect(w.find('[data-testid=page-preparer-badge]').exists()).toBe(false);
    await w.get('[data-testid="support-B-000842"]').trigger('click'); await flushPromises();
    expect(calls).toHaveLength(0);
    expect(w.find('[data-testid=verifier-impression]').exists()).toBe(false);
    expect(w.find('[data-testid=consulter-support]').exists()).toBe(true);
    expect(w.find('[data-testid=lien-cartes-permanentes]').exists()).toBe(false);
  });

  it('adapte la disposition aux petits écrans : un volet à la fois, trois volets accessibles', async () => {
    const w = mountPage();
    expect(w.findAll('[data-testid^=volet-]').map(b => b.text())).toEqual(['Registre', 'Préparer', 'Aperçu']);
    await w.get('[data-testid=volet-preparer]').trigger('click');
    expect(w.get('[data-testid=volet-preparer]').attributes('aria-selected')).toBe('true');
    expect(w.get('[data-testid=colonne-registre]').element.parentElement?.className).toContain('hidden');
  });

  it('ouvre directement la préparation quand elle vient d’une fiche d’activité', () => {
    const w = mountPage({ preset: { event: 'e1', ids: ['p1'] } });
    expect(w.get('[data-testid=volet-preparer]').attributes('aria-selected')).toBe('true');
  });
});
