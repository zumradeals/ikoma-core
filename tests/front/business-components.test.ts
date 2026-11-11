import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi } from 'vitest';
import BadgeState from '../../resources/js/components/BadgeState.vue';
import CategorySwatch from '../../resources/js/components/CategorySwatch.vue';
import CategoryForm from '../../resources/js/components/CategoryForm.vue';
vi.mock('@inertiajs/vue3', () => ({ useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn() }) }));
describe('administration L2', () => {
 it.each(['Disponible', 'Associé', 'Restitué', 'Annulé', 'Clos'])('identifie le statut %s avec un mot et un symbole', status => {
  const wrapper = mount(BadgeState, { props: { status } }); expect(wrapper.text()).toContain(status); expect(wrapper.get('[aria-hidden="true"]').text().length).toBeGreaterThan(0);
 });
 it('garde le libellé et la priorité même sans couleur valide', () => {
  const wrapper = mount(CategorySwatch, { props: { name: 'VIP', color: 'invalide', priority: true } }); expect(wrapper.text()).toContain('VIP'); expect(wrapper.text()).toContain('Prioritaire'); expect(wrapper.attributes('style')).toContain('border-color');
 });
 it('propose toutes les zones du site, quelle que soit l’activité choisie', async () => {
  const wrapper = mount(CategoryForm, { props: { events: [{ id: 'a', name: 'Événement A' }, { id: 'b', name: 'Événement B' }], zones: [{ id: 'za', name: 'Hall du site' }, { id: 'zs', name: 'Parking du site' }] } });
  expect(wrapper.text()).toContain('Hall du site'); expect(wrapper.text()).toContain('Parking du site');
  await wrapper.find('input[value="za"]').setValue(true); await wrapper.get('select').setValue('b'); expect(wrapper.text()).toContain('Hall du site');
 });
});

it('ne permet pas d’activer la clôture réservée au superviseur tant que son parcours n’existe pas, mais permet de la retirer (S02)', () => {
  const base = { events: [{ id: 'a', name: 'Événement A' }], zones: [] as { id: string; name: string }[] };
  const blocked = mount(CategoryForm, { props: base });
  expect(blocked.get('[data-testid="cloture-reservee"]').attributes('disabled')).toBeDefined();
  expect(blocked.get('[data-testid="cloture-reservee-indisponible"]').text()).toContain('rendrait toute sortie impossible');
  const available = mount(CategoryForm, { props: { ...base, closureAvailable: true } });
  expect(available.get('[data-testid="cloture-reservee"]').attributes('disabled')).toBeUndefined();
  expect(available.find('[data-testid="cloture-reservee-indisponible"]').exists()).toBe(false);
  // un état hérité reste modifiable : l'administrateur peut le retirer
  const legacy = mount(CategoryForm, { props: { ...base, category: { id: 'c', event_id: 'a', name: 'VIP', color: '#C9962B', priority_circuit: true, closure_supervisor_only: true, zone_ids: [] } } });
  expect(legacy.get('[data-testid="cloture-reservee"]').attributes('disabled')).toBeUndefined();
});
