import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ props: { errors: {} as Record<string, string> } }));
const router = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page, router, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));

import SiteAcces from '../../resources/js/pages/Admin/SiteAcces.vue';

const catalogue = { accueil: 'Accueil : identité, admission et attribution de badge', scan: 'Contrôle d’un badge (entrée ou passage)', sortie: 'Sortie du site (la restitution du badge reste une opération distincte, permise par la même capacité)' };
const tablet = (id: string, checkpoint: string | null) => ({ id, name: 'Tablette ' + id, station: null, checkpoint_id: checkpoint, state: 'enrole' as const, last_synced_at: null, event_name: 'Activité fictive' });
const posts = [
  { id: 'c1', code: 'poste_1', name: 'Poste 1', function_label: 'Entrée principale', capabilities: [], direction_required: false, active: true, version: 1, tablets: [], zones: [] },
  { id: 'c2', code: 'poste_2', name: 'Poste 2', function_label: 'Accès Académie', capabilities: ['accueil', 'scan', 'sortie'], direction_required: false, active: true, version: 3, site_zones: [{ mapping_id: 'sm1', zone_id: 'sz1', name: 'Hall du site' }], tablets: [tablet('t1', 'c2'), tablet('t2', 'c2')], zones: [{ mapping_id: 'm1', zone_id: 'z1', event_id: 'e1', name: 'Hall', event_name: 'Activité fictive' }] },
  { id: 'c5', code: 'soute', name: 'Point 5', function_label: 'La Soute', capabilities: ['scan'], direction_required: true, active: false, version: 2, tablets: [], zones: [] },
];
const props = { site: { id: 's1', name: 'Site fictif' }, site_zones: [{ id: 'sz1', name: 'Hall du site' }, { id: 'sz2', name: 'Parking du site' }], checkpoints: posts, catalogue, unassigned: [tablet('t3', null)], zones: [{ id: 'z1', name: 'Hall', event_id: 'e1', event_name: 'Activité fictive' }, { id: 'z2', name: 'Salle', event_id: 'e1', event_name: 'Activité fictive' }], history: [{ id: 'h1', device: 'Tablette t1', checkpoint: 'Poste 2', assigned_at: '2027-01-10T10:00:00Z', ended_at: null, reason: 'Mise en service', by: 'Administrateur fictif' }] };
const render = (over: object = {}) => mount(SiteAcces, { props: { ...props, ...over } as never, global: { stubs: { AdminLayout: { template: '<main><slot /></main>' } } } });
/** Ouvre la configuration détaillée d'un poste (elle n'est affichée que sur sélection). */
async function open(wrapper: ReturnType<typeof render>, code: string) { await wrapper.get(`[data-testid="selectionner-${code}"]`).trigger('click'); return wrapper; }

beforeEach(() => { router.put.mockReset(); router.post.mockReset(); page.props.errors = {}; });

describe('écran Site et accès', () => {
  it('présente un registre synthétique des postes réels, sans plan ni donnée inventée ni configuration ouverte', () => {
    const wrapper = render();
    expect(wrapper.findAll('[data-testid^="poste-"]').map(a => a.attributes('data-testid'))).toEqual(['poste-poste_1', 'poste-poste_2', 'poste-soute']);
    expect(wrapper.get('[data-testid="etat-poste_2"]').text()).toBe('Actif');
    expect(wrapper.get('[data-testid="etat-soute"]').text()).toBe('Désactivé');
    expect(wrapper.get('[data-testid="poste-poste_2"]').text()).toContain('Accès Académie');
    expect(wrapper.get('[data-testid="operations-poste_2"]').text()).toContain('Accueil');
    expect(wrapper.get('[data-testid="operations-poste_2"]').text()).toContain('Contrôle d’un badge');
    expect(wrapper.get('[data-testid="operations-poste_1"]').text()).toContain('Aucune opération');
    expect(wrapper.get('[data-testid="nombre-tablettes-poste_2"]').text()).toContain('2');
    expect(wrapper.find('img, canvas, iframe').exists()).toBe(false);
    // aucune configuration détaillée tant qu'aucun poste n'est sélectionné
    expect(wrapper.find('[data-testid="detail-poste"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="nom-poste_2"]').exists()).toBe(false);
  });

  it('compose la vue d’ensemble : registre, informations du site, zones et règles réelles, sans règle inventée', () => {
    const wrapper = render();
    for (const id of ['registre-postes', 'infos-site', 'resume-zones', 'resume-regles']) expect(wrapper.find(`[data-testid="${id}"]`).exists(), id).toBe(true);
    expect(wrapper.get('[data-testid="infos-site"]').text()).toContain('Site fictif');
    // le résumé présente les zones PERMANENTES du site ; les zones d'activité sont comptées à part
    expect(wrapper.findAll('[data-testid^="zone-resume-"]').map(a => a.attributes('data-testid'))).toEqual(['zone-resume-sz1', 'zone-resume-sz2']);
    expect(wrapper.get('[data-testid="zone-resume-sz1"]').text()).toContain('Poste 2');
    expect(wrapper.get('[data-testid="zone-resume-sz2"]').text()).toContain('aucun poste');
    expect(wrapper.get('[data-testid="resume-zones"] h2').text()).toBe('Zones du site (2)');
    expect(wrapper.get('[data-testid="resume-zones-activites"]').text()).toContain('Zones des activités : 2');
    // une seule correspondance poste–zone existe dans les données : une seule règle
    expect(wrapper.findAll('[data-testid^="regle-resume-"]')).toHaveLength(1);
    expect(wrapper.get('[data-testid="resume-regles"]').text()).toContain('Règles d’accès (1)');
  });

  it('crée une zone du site depuis le résumé, sans demander de choisir un événement, et la compte aussitôt', async () => {
    const wrapper = render({ site_zones: [] });
    expect(wrapper.get('[data-testid="resume-zones"] h2').text()).toBe('Zones du site (0)');
    expect(wrapper.get('[data-testid="aucune-zone-resume"]').text()).toContain('Aucune zone du site');
    expect(wrapper.find('[data-testid="resume-zones"] select').exists()).toBe(false); // aucun choix d'événement
    await wrapper.get('[data-testid="resume-nom-zone-site"]').setValue('Parking VIP');
    await wrapper.get('[aria-label="Ajouter une zone au site"]').trigger('submit');
    expect(router.post).toHaveBeenCalledWith('/administration/site/zones', { name: 'Parking VIP' }, expect.objectContaining({ preserveScroll: true }));
    const after = render({ site_zones: [{ id: 'sz9', name: 'Parking VIP' }] });
    expect(after.get('[data-testid="resume-zones"] h2').text()).toBe('Zones du site (1)');
    expect(after.get('[data-testid="kpi-zones"]').text()).toBe('1');
  });

  it('ouvre la configuration dans un panneau modal qui se referme avec Échap ou le fond', async () => {
    const wrapper = render();
    await open(wrapper, 'poste_2');
    const panel = wrapper.get('[data-testid="detail-poste"]');
    expect(panel.attributes('role')).toBe('dialog');
    expect(panel.attributes('aria-modal')).toBe('true');
    await panel.trigger('keydown', { key: 'Escape' });
    expect(wrapper.find('[data-testid="detail-poste"]').exists()).toBe(false);
    await open(wrapper, 'poste_2');
    await wrapper.get('[data-testid="fond-panneau"]').trigger('click');
    expect(wrapper.find('[data-testid="detail-poste"]').exists()).toBe(false);
  });

  it('affiche des indicateurs tirés des données réelles', () => {
    const wrapper = render();
    expect(wrapper.get('[data-testid="kpi-postes"]').text()).toBe('3');
    expect(wrapper.get('[data-testid="kpi-zones"]').text()).toBe('2'); // zones du site ; les 2 zones d'activité sont indiquées en dessous
    expect(wrapper.get('[data-testid="kpi-tablettes"]').text()).toBe('2');
    expect(wrapper.get('[data-testid="kpi-correspondances"]').text()).toBe('1');
  });

  it('ouvre la configuration d’un seul poste à la fois, seulement sur sélection, et la referme', async () => {
    const wrapper = render();
    await open(wrapper, 'poste_2');
    expect(wrapper.get('[data-testid="detail-poste"]').text()).toContain('Poste 2');
    expect(wrapper.find('[data-testid="nom-poste_2"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="nom-poste_1"]').exists()).toBe(false);
    await open(wrapper, 'poste_1');
    expect(wrapper.find('[data-testid="nom-poste_2"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="nom-poste_1"]').exists()).toBe(true);
    await wrapper.get('[data-testid="fermer-detail"]').trigger('click');
    expect(wrapper.find('[data-testid="detail-poste"]').exists()).toBe(false);
  });

  it('affiche plusieurs tablettes pour un même poste, les zones gardées et l’historique', async () => {
    const wrapper = render();
    await open(wrapper, 'poste_2');
    expect(wrapper.get('[data-testid="tablettes-poste_2"]').findAll('li')).toHaveLength(2);
    expect(wrapper.get('[data-testid="zones-poste_2"]').text()).toContain('Hall');
    await open(wrapper, 'poste_1');
    expect(wrapper.get('[data-testid="tablettes-poste_1"]').text()).toContain('Aucune tablette affectée');
    expect(wrapper.get('[data-testid="zones-poste_1"]').text()).toContain('ne juge aucune zone');
    await wrapper.get('[data-testid="tab-parametres"]').trigger('click');
    expect(wrapper.get('[data-testid="historique-affectations"]').text()).toContain('Tablette t1');
  });

  it('coche les capacités du catalogue et n’autorise le sens obligatoire qu’avec le contrôle de badge', async () => {
    const wrapper = render();
    await open(wrapper, 'poste_2');
    expect((wrapper.get('[data-testid="capacite-poste_2-scan"]').element as HTMLInputElement).checked).toBe(true);
    await open(wrapper, 'poste_1');
    expect((wrapper.get('[data-testid="capacite-poste_1-scan"]').element as HTMLInputElement).checked).toBe(false);
    expect(wrapper.get('[data-testid="sens-poste_1"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid="capacite-poste_1-scan"]').setValue(true);
    expect(wrapper.get('[data-testid="sens-poste_1"]').attributes('disabled')).toBeUndefined();
  });

  it('enregistre la configuration avec la version lue, pour que le serveur refuse un conflit', async () => {
    const wrapper = render();
    await open(wrapper, 'poste_1');
    await wrapper.get('[data-testid="capacite-poste_1-scan"]').setValue(true);
    await wrapper.get('[data-testid="enregistrer-poste_1"]').trigger('submit');
    expect(router.put).toHaveBeenCalledWith('/administration/site/postes/c1', expect.objectContaining({ capabilities: ['scan'], version: 1, name: 'Poste 1' }), expect.anything());
  });

  it('désactive et réactive un poste avec sa version', async () => {
    const wrapper = render();
    await open(wrapper, 'poste_2');
    await wrapper.get('[data-testid="activation-poste_2"]').trigger('click');
    expect(router.post).toHaveBeenCalledWith('/administration/site/postes/c2/activation', { active: false, version: 3 }, expect.anything());
    await open(wrapper, 'soute');
    await wrapper.get('[data-testid="activation-soute"]').trigger('click');
    expect(router.post).toHaveBeenLastCalledWith('/administration/site/postes/c5/activation', { active: true, version: 2 }, expect.anything());
  });

  it('affecte une tablette sans poste et en change une autre de poste, sans proposer les postes désactivés', async () => {
    const wrapper = render();
    await wrapper.get('[data-testid="tab-tablettes"]').trigger('click');
    expect(wrapper.findAll('[data-testid^="tablette-"]').filter(a => /^tablette-t\d$/.test(a.attributes('data-testid')!))).toHaveLength(3);
    expect(wrapper.get('[data-testid="poste-de-t3"]').text()).toBe('sans poste');
    expect(wrapper.get('[data-testid="cible-t3"]').findAll('option').map(o => o.text())).toEqual(['Choisir un poste…', 'Poste 1', 'Poste 2']);
    await wrapper.get('[data-testid="cible-t3"]').setValue('c1');
    await wrapper.get('[data-testid="affecter-t3"]').trigger('click');
    expect(router.post).toHaveBeenCalledWith('/administration/site/tablettes/t3/affectation', { checkpoint_id: 'c1' }, expect.anything());
    // une tablette déjà affectée à un poste se déplace vers un autre ; son propre poste n'est pas proposé
    expect(wrapper.get('[data-testid="cible-t1"]').findAll('option').map(o => o.text())).toEqual(['Changer de poste…', 'Poste 1']);
    await wrapper.get('[data-testid="cible-t1"]').setValue('c1');
    await wrapper.get('[data-testid="affecter-t1"]').trigger('click');
    expect(router.post).toHaveBeenLastCalledWith('/administration/site/tablettes/t1/affectation', { checkpoint_id: 'c1' }, expect.anything());
  });

  it('déplace aussi une tablette depuis la configuration de son poste', async () => {
    const wrapper = render();
    await open(wrapper, 'poste_2');
    await wrapper.get('[data-testid="cible-t2"]').setValue('c1');
    await wrapper.get('[data-testid="affecter-t2"]').trigger('click');
    expect(router.post).toHaveBeenCalledWith('/administration/site/tablettes/t2/affectation', { checkpoint_id: 'c1' }, expect.anything());
  });

  it('associe et retire une zone par les routes existantes des accès', async () => {
    const wrapper = render();
    await open(wrapper, 'poste_1');
    await wrapper.get('[data-testid="zone-choix-poste_1"]').setValue('z2');
    await wrapper.get('[data-testid="zone-ajouter-poste_1"]').trigger('click');
    expect(router.post).toHaveBeenCalledWith('/administration/acces/correspondances', { event_id: 'e1', checkpoint_id: 'c1', zone_id: 'z2' }, expect.anything());
    await open(wrapper, 'poste_2');
    await wrapper.get('[data-testid="zone-m1"] button').trigger('click');
    expect(router.post).toHaveBeenLastCalledWith('/administration/acces/correspondances/m1/retirer', {}, expect.anything());
  });

  it('crée un poste avec ses capacités depuis « Ajouter un poste »', async () => {
    const wrapper = render();
    expect(wrapper.find('[data-testid="creer-poste"]').exists()).toBe(false);
    await wrapper.get('[data-testid="ajouter-poste"]').trigger('click');
    await wrapper.get('[data-testid="nouveau-code"]').setValue('poste_6');
    await wrapper.get('[data-testid="nouveau-nom"]').setValue('Poste 6');
    await wrapper.get('[data-testid="nouvelle-capacite-sortie"]').setValue(true);
    await wrapper.get('[data-testid="creer-poste"]').trigger('submit');
    expect(router.post).toHaveBeenCalledWith('/administration/site/postes', expect.objectContaining({ code: 'poste_6', name: 'Poste 6', capabilities: ['sortie'] }), expect.anything());
  });

  it('range zones, tablettes, règles et paramètres en sections accessibles, sans fonctionnalité inventée', async () => {
    const wrapper = render();
    expect(wrapper.findAll('[role="tab"]').map(t => t.text())).toEqual(['Postes de contrôle', 'Zones', 'Tablettes', 'Règles d’accès', 'Paramètres']);
    await wrapper.get('[data-testid="tab-zones"]').trigger('click');
    expect(wrapper.get('[data-testid="panneau-zones"] h2').text()).toBe('Zones du site (2)');
    expect(wrapper.get('[data-testid="zone-liste-z1"]').text()).toContain('Gardée par : Poste 2');
    expect(wrapper.get('[data-testid="zone-liste-z2"]').text()).toContain('aucun poste');
    expect(wrapper.get('[data-testid="gerer-zones"]').attributes('href')).toBe('/administration/zones');
    await wrapper.get('[data-testid="tab-regles"]').trigger('click');
    expect(wrapper.get('[data-testid="regle-poste_2"]').text()).toContain('Hall');
    expect(wrapper.get('[data-testid="regle-soute"]').text()).toContain('sens Entrée / Sortie obligatoire');
    await wrapper.get('[data-testid="regle-poste_1"] button').trigger('click');
    expect(wrapper.get('[data-testid="detail-poste"]').text()).toContain('Poste 1');
    await wrapper.get('[data-testid="tab-parametres"]').trigger('click');
    await wrapper.get('[data-testid="site-nom"]').setValue('Autre nom');
    await wrapper.get('[data-testid="enregistrer-site"]').trigger('submit');
    expect(router.put).toHaveBeenCalledWith('/administration/site', { name: 'Autre nom' }, expect.anything());
  });

  it('garde la sortie du site et la restitution du badge comme deux opérations distinctes dans le libellé', () => {
    expect(catalogue.sortie).toContain('restitution du badge reste une opération distincte');
    expect(catalogue.sortie).not.toMatch(/Contrôle de sortie et restitution/);
  });

  it('affiche les refus du serveur et les états vides explicitement', async () => {
    page.props.errors = { version: 'Ce poste a été modifié entre-temps par un autre administrateur : rechargez la page avant de réessayer.' };
    const wrapper = render({ checkpoints: [], unassigned: [], history: [], site: null, zones: [] });
    expect(wrapper.get('[data-testid="erreurs-site"]').text()).toContain('modifié entre-temps');
    expect(wrapper.get('[data-testid="aucun-poste"]').text()).toContain('Aucun poste');
    expect(wrapper.find('[data-testid="aucun-site"]').exists()).toBe(true);
    await wrapper.get('[data-testid="tab-tablettes"]').trigger('click');
    expect(wrapper.find('[data-testid="aucune-tablette"]').exists()).toBe(true);
    await wrapper.get('[data-testid="tab-zones"]').trigger('click');
    expect(wrapper.find('[data-testid="aucune-zone"]').exists()).toBe(true);
    await wrapper.get('[data-testid="tab-parametres"]').trigger('click');
    expect(wrapper.get('[data-testid="historique-affectations"]').text()).toContain('Aucune affectation');
  });
});
