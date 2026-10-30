import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { expect, it, vi } from 'vitest';
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { auth: { name: 'Compte fictif', permissions: ['comptes.gerer'] } } }), Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }) }));
import DataManagement from '../../resources/js/pages/Admin/DataManagement.vue';
const base = { preview: null, counts: { evenements_ouverts: 2, personnes_retirees: 3 }, badges: { Disponible: 12 }, durations: { visiteurs: 30 }, approved: true, events: [], history: [], requests: [], pendingFiles: 0, maintenanceReport: null };
it('explique un refus sans proposer une confirmation destructive', () => {
  const wrapper = mount(DataManagement, { props: { ...base, preview: { entity: 'personne', id: 'fictive', label: 'Personne fictive', allowed: false, reasons: ['Historique protégé'], counts: { visitors: 1 }, retained: ['Journal d’audit'] } } });
  expect(wrapper.text()).toContain('Historique protégé');
  expect(wrapper.text()).not.toContain('Confirmer la suppression définitive');
});
it('distingue une demande en attente d’une opération terminée et conserve les compteurs réels', () => {
  const wrapper = mount(DataManagement, { props: { ...base, requests: [{ id: 'demande-fictive', operation: 'demo', status: 'en_attente', result: null, created_at: '2030-01-01' }] } });
  expect(wrapper.text()).toContain('En attente du service de maintenance');
  expect(wrapper.text()).toContain('Une demande en attente n’est pas une opération terminée');
  expect(wrapper.find('a[href="/administration/evenements?archives=1"]').exists()).toBe(true);
});
it('exige la référence et le mot de passe pour une fiche admissible', () => {
  const wrapper = mount(DataManagement, { props: { ...base, preview: { entity: 'personne', id: 'fictive', label: 'Personne fictive', allowed: true, reasons: [], counts: { visitors: 1 }, retained: [] } } });
  expect(wrapper.find('input[type="password"]').exists()).toBe(true);
  expect(wrapper.text()).toContain('Recopiez la référence');
  expect(wrapper.text()).toContain('irréversible');
});
