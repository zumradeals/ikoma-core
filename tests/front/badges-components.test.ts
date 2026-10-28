import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi } from 'vitest';
import BadgeLotForm from '../../resources/js/components/BadgeLotForm.vue';
import BadgeReservationForm from '../../resources/js/components/BadgeReservationForm.vue';
import BadgeSettingsForm from '../../resources/js/components/BadgeSettingsForm.vue';
vi.mock('@inertiajs/vue3', () => ({ useForm: (data: object) => reactive({ ...data, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), get: vi.fn(), reset: vi.fn() }) }));
const events = [{ id: 'a', name: 'Événement A' }, { id: 'b', name: 'Événement B' }];
describe('administration des badges L4', () => {
  const categories = [{ id: 'ca', event_id: 'a', name: 'VIP' }, { id: 'cb', event_id: 'b', name: 'Stagiaire' }];
  it('annonce la quantité fabriquée avec la marge arrondie au supérieur', async () => {
    const wrapper = mount(BadgeLotForm, { props: { events, categories, margin: 10 } });
    expect(wrapper.text()).toContain('Badges à fabriquer : 110');
    await wrapper.get('input[type="number"]').setValue(15);
    expect(wrapper.text()).toContain('Badges à fabriquer : 17'); expect(wrapper.text()).toContain('15 attendus + 2 de marge à 10 %');
    await wrapper.get('input[type="number"]').setValue(1);
    expect(wrapper.text()).toContain('Badges à fabriquer : 2');
  });
  it('ne propose que les catégories de l’événement choisi et bloque l’envoi sans catégorie', async () => {
    const wrapper = mount(BadgeLotForm, { props: { events, categories, margin: 10 } });
    expect(wrapper.text()).toContain('VIP'); expect(wrapper.text()).not.toContain('Stagiaire');
    expect(wrapper.get('button').attributes('disabled')).toBeDefined();
    await wrapper.findAll('select')[0].setValue('b');
    expect(wrapper.text()).toContain('Stagiaire'); expect(wrapper.text()).not.toContain('VIP');
    await wrapper.findAll('select')[1].setValue('cb');
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
  });
  it('signale un événement sans catégorie', () => {
    const wrapper = mount(BadgeLotForm, { props: { events, categories: [], margin: 10 } });
    expect(wrapper.text()).toContain('Aucune catégorie pour cet événement');
  });
  const batches = [{ id: 'l1', name: 'Lot VIP n°1', event_id: 'a', first_number: 401, last_number: 420 }, { id: 'l2', name: 'Lot Stagiaire n°1', event_id: 'b', first_number: 1, last_number: 40 }];
  const devices = [{ id: 'd1', event_id: 'a', name: 'Tablette A', station: 'Poste A' }, { id: 'd2', event_id: 'b', name: 'Tablette B', station: null }];
  const groups = [{ id: 'g1', event_id: 'a', name: 'Car A' }, { id: 'g2', event_id: 'b', name: 'Car B' }];
  it('limite les postes et groupes à l’événement du lot et rappelle les numéros du lot', async () => {
    const wrapper = mount(BadgeReservationForm, { props: { batches, devices, groups } });
    expect(wrapper.text()).toContain('du 401 au 420'); expect(wrapper.text()).toContain('Poste A'); expect(wrapper.text()).not.toContain('Tablette B');
    await wrapper.findAll('select')[0].setValue('l2');
    expect(wrapper.text()).toContain('du 1 au 40'); expect(wrapper.text()).toContain('Tablette B'); expect(wrapper.text()).not.toContain('Poste A');
    await wrapper.get('input[value="groupe"]').setValue(true);
    expect(wrapper.text()).toContain('Car B'); expect(wrapper.text()).not.toContain('Car A'); expect(wrapper.text()).not.toContain('Tablette B');
  });
  it('remet la cible à zéro quand le lot ou le type de cible change', async () => {
    const wrapper = mount(BadgeReservationForm, { props: { batches, devices, groups } });
    const target = () => wrapper.findAll('select')[1].element as HTMLSelectElement;
    expect(target().value).toBe('');
    await wrapper.findAll('select')[1].setValue('d1'); expect(target().value).toBe('d1');
    await wrapper.get('input[value="groupe"]').setValue(true); expect(target().value).toBe('');
    await wrapper.findAll('select')[1].setValue('g1'); expect(target().value).toBe('g1');
    await wrapper.findAll('select')[0].setValue('l2'); expect(target().value).toBe('');
  });
  it('affiche les deux modes de réutilisation avec le mode actuel sélectionné', () => {
    const unique = mount(BadgeSettingsForm, { props: { settings: { margin: 10, reusable: false } } });
    expect((unique.get('input[value="non"]').element as HTMLInputElement).checked).toBe(true); expect((unique.get('input[value="oui"]').element as HTMLInputElement).checked).toBe(false);
    expect(unique.text()).toContain('à confirmer avec le client');
    const reusable = mount(BadgeSettingsForm, { props: { settings: { margin: 25, reusable: true } } });
    expect((reusable.get('input[value="oui"]').element as HTMLInputElement).checked).toBe(true); expect((reusable.get('input[type="number"]').element as HTMLInputElement).value).toBe('25');
  });
});
