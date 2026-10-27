import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi } from 'vitest';
import BadgeState from '../../resources/js/components/BadgeState.vue';
import CategorySwatch from '../../resources/js/components/CategorySwatch.vue';
import CategoryForm from '../../resources/js/components/CategoryForm.vue';
import ZoneForm from '../../resources/js/components/ZoneForm.vue';
vi.mock('@inertiajs/vue3', () => ({ useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn() }) }));
describe('administration L2', () => {
 it.each(['Disponible', 'Associé', 'Restitué', 'Annulé', 'Clos'])('identifie le statut %s avec un mot et un symbole', status => {
  const wrapper = mount(BadgeState, { props: { status } }); expect(wrapper.text()).toContain(status); expect(wrapper.get('[aria-hidden="true"]').text().length).toBeGreaterThan(0);
 });
 it('garde le libellé et la priorité même sans couleur valide', () => {
  const wrapper = mount(CategorySwatch, { props: { name: 'VIP', color: 'invalide', priority: true } }); expect(wrapper.text()).toContain('VIP'); expect(wrapper.text()).toContain('Prioritaire'); expect(wrapper.attributes('style')).toContain('border-color');
 });
 it('ne propose que les zones de l’événement choisi et efface la sélection lors du changement', async () => {
  const wrapper = mount(CategoryForm, { props: { events: [{ id: 'a', name: 'Événement A' }, { id: 'b', name: 'Événement B' }], zones: [{ id: 'za', event_id: 'a', name: 'Zone A' }, { id: 'zb', event_id: 'b', name: 'Zone B' }] } });
  expect(wrapper.text()).toContain('Zone A'); expect(wrapper.text()).not.toContain('Zone B');
  await wrapper.find('input[value="za"]').setValue(true); await wrapper.get('select').setValue('b'); expect(wrapper.text()).toContain('Zone B'); expect(wrapper.text()).not.toContain('Zone A');
  await wrapper.get('select').setValue('a'); expect((wrapper.find('input[value="za"]').element as HTMLInputElement).checked).toBe(false);
 });
 it('interdit de déplacer une zone existante vers un autre événement', () => {
  const wrapper = mount(ZoneForm, { props: { events: [{ id: 'a', name: 'Événement A' }], zone: { id: 'z', name: 'Zone existante', event_id: 'a' } } }); expect(wrapper.get('select').attributes('disabled')).toBeDefined(); expect(wrapper.get('input').element.value).toBe('Zone existante');
 });
});
