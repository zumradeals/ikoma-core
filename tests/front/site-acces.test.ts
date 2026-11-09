import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { errors: {} as Record<string, string> } }));
const router = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, router, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import SiteAcces from '../../resources/js/pages/Admin/SiteAcces.vue';

const catalogue = { accueil: 'Accueil : identité, admission et attribution de badge', scan: 'Contrôle d’un badge (entrée ou passage)', sortie: 'Contrôle de sortie et restitution' };
const tablet = (id: string, checkpoint: string | null) => ({ id, name: 'Tablette ' + id, station: null, checkpoint_id: checkpoint, state: 'enrole' as const, last_synced_at: null, event_name: 'Activité fictive' });
const posts = [
  { id: 'c1', code: 'poste_1', name: 'Poste 1', function_label: 'Entrée principale', capabilities: [], direction_required: false, active: true, version: 1, tablets: [], zones: [] },
  { id: 'c2', code: 'poste_2', name: 'Poste 2', function_label: 'Accès Académie', capabilities: ['accueil', 'scan', 'sortie'], direction_required: false, active: true, version: 3, tablets: [tablet('t1', 'c2'), tablet('t2', 'c2')], zones: [{ mapping_id: 'm1', zone_id: 'z1', event_id: 'e1', name: 'Hall', event_name: 'Activité fictive' }] },
  { id: 'c5', code: 'soute', name: 'Point 5', function_label: 'La Soute', capabilities: ['scan'], direction_required: true, active: false, version: 2, tablets: [], zones: [] },
];
const props = { site: { id: 's1', name: 'Site fictif' }, checkpoints: posts, catalogue, unassigned: [tablet('t3', null)], zones: [{ id: 'z1', name: 'Hall', event_id: 'e1', event_name: 'Activité fictive' }, { id: 'z2', name: 'Salle', event_id: 'e1', event_name: 'Activité fictive' }], history: [{ id: 'h1', device: 'Tablette t1', checkpoint: 'Poste 2', assigned_at: '2027-01-10T10:00:00Z', ended_at: null, reason: 'Mise en service', by: 'Administrateur fictif' }] };
const render = (over: object = {}) => mount(SiteAcces, { props: { ...props, ...over } as never, global: { stubs: { AdminLayout: { template: '<main><slot /></main>' } } } });

beforeEach(() => { router.put.mockReset(); router.post.mockReset(); page.props.errors = {}; });

describe('écran Site et accès', () => {
  it('présente chaque poste réel avec son état, sans plan ni donnée inventée', () => {
    const wrapper = render();
    expect(wrapper.findAll('[data-testid^="poste-"]').map(a => a.attributes('data-testid'))).toEqual(['poste-poste_1', 'poste-poste_2', 'poste-soute']);
    expect(wrapper.get('[data-testid="etat-poste_2"]').text()).toBe('Actif');
    expect(wrapper.get('[data-testid="etat-soute"]').text()).toBe('Désactivé');
    expect(wrapper.text()).toContain('Poste 2 — Accès Académie');
    expect(wrapper.find('img, svg, canvas, iframe').exists()).toBe(false);
  });

  it('affiche plusieurs tablettes pour un même poste, les zones gardées et l’historique', () => {
    const wrapper = render();
    expect(wrapper.get('[data-testid="tablettes-poste_2"]').findAll('li')).toHaveLength(2);
    expect(wrapper.get('[data-testid="zones-poste_2"]').text()).toContain('Hall');
    expect(wrapper.get('[data-testid="historique-affectations"]').text()).toContain('Mise en service');
    expect(wrapper.get('[data-testid="tablettes-poste_1"]').text()).toContain('Aucune tablette affectée');
    expect(wrapper.get('[data-testid="zones-poste_1"]').text()).toContain('ne juge aucune zone');
  });

  it('coche les capacités du catalogue et n’autorise le sens obligatoire qu’avec le contrôle de badge', async () => {
    const wrapper = render();
    expect((wrapper.get('[data-testid="capacite-poste_2-scan"]').element as HTMLInputElement).checked).toBe(true);
    expect((wrapper.get('[data-testid="capacite-poste_1-scan"]').element as HTMLInputElement).checked).toBe(false);
    expect(wrapper.get('[data-testid="sens-poste_1"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid="capacite-poste_1-scan"]').setValue(true);
    expect(wrapper.get('[data-testid="sens-poste_1"]').attributes('disabled')).toBeUndefined();
  });

  it('enregistre la configuration avec la version lue, pour que le serveur refuse un conflit', async () => {
    const wrapper = render();
    await wrapper.get('[data-testid="capacite-poste_1-scan"]').setValue(true);
    await wrapper.get('[data-testid="enregistrer-poste_1"]').trigger('submit');
    expect(router.put).toHaveBeenCalledWith('/administration/site/postes/c1', expect.objectContaining({ capabilities: ['scan'], version: 1, name: 'Poste 1' }), expect.anything());
  });

  it('désactive et réactive un poste avec sa version', async () => {
    const wrapper = render();
    await wrapper.get('[data-testid="activation-poste_2"]').trigger('click');
    expect(router.post).toHaveBeenCalledWith('/administration/site/postes/c2/activation', { active: false, version: 3 }, expect.anything());
    await wrapper.get('[data-testid="activation-soute"]').trigger('click');
    expect(router.post).toHaveBeenLastCalledWith('/administration/site/postes/c5/activation', { active: true, version: 2 }, expect.anything());
  });

  it('affecte une tablette sans poste et en change une autre de poste, sans proposer les postes désactivés', async () => {
    const wrapper = render();
    expect(wrapper.get('[data-testid="cible-t3"]').findAll('option').map(o => o.text())).toEqual(['Choisir un poste…', 'Poste 1', 'Poste 2']);
    await wrapper.get('[data-testid="cible-t3"]').setValue('c1');
    await wrapper.get('[data-testid="affecter-t3"]').trigger('click');
    expect(router.post).toHaveBeenCalledWith('/administration/site/tablettes/t3/affectation', { checkpoint_id: 'c1' }, expect.anything());
    await wrapper.get('[data-testid="cible-t1"]').setValue('c1');
    await wrapper.get('[data-testid="affecter-t1"]').trigger('click');
    expect(router.post).toHaveBeenLastCalledWith('/administration/site/tablettes/t1/affectation', { checkpoint_id: 'c1' }, expect.anything());
  });

  it('associe et retire une zone par les routes existantes des accès', async () => {
    const wrapper = render();
    await wrapper.get('[data-testid="zone-choix-poste_1"]').setValue('z2');
    await wrapper.get('[data-testid="zone-ajouter-poste_1"]').trigger('click');
    expect(router.post).toHaveBeenCalledWith('/administration/acces/correspondances', { event_id: 'e1', checkpoint_id: 'c1', zone_id: 'z2' }, expect.anything());
    await wrapper.get('[data-testid="zone-m1"] button').trigger('click');
    expect(router.post).toHaveBeenLastCalledWith('/administration/acces/correspondances/m1/retirer', {}, expect.anything());
  });

  it('crée un poste avec ses capacités', async () => {
    const wrapper = render();
    await wrapper.get('[data-testid="nouveau-code"]').setValue('poste_6');
    await wrapper.get('[data-testid="nouveau-nom"]').setValue('Poste 6');
    await wrapper.get('[data-testid="nouvelle-capacite-sortie"]').setValue(true);
    await wrapper.get('[data-testid="creer-poste"]').trigger('submit');
    expect(router.post).toHaveBeenCalledWith('/administration/site/postes', expect.objectContaining({ code: 'poste_6', name: 'Poste 6', capabilities: ['sortie'] }), expect.anything());
  });

  it('affiche les refus du serveur et les états vides explicitement', () => {
    page.props.errors = { version: 'Ce poste a été modifié entre-temps par un autre administrateur : rechargez la page avant de réessayer.' };
    const wrapper = render({ checkpoints: [], unassigned: [], history: [], site: null });
    expect(wrapper.get('[data-testid="erreurs-site"]').text()).toContain('modifié entre-temps');
    expect(wrapper.get('[data-testid="aucun-poste"]').text()).toContain('Aucun poste');
    expect(wrapper.find('[data-testid="aucun-site"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="tablettes-sans-poste"]').text()).toContain('Toutes les tablettes sont affectées');
    expect(wrapper.get('[data-testid="historique-affectations"]').text()).toContain('Aucune affectation');
  });
});
