import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BaseButton from '../../resources/js/components/BaseButton.vue';
import CategoryLabel from '../../resources/js/components/CategoryLabel.vue';
import StatusBanner from '../../resources/js/components/StatusBanner.vue';
describe('composants accessibles', () => {
  it('explique pourquoi une action est désactivée', () => {
    const wrapper = mount(BaseButton, { props: { disabled: true, reason: 'En préparation' }, slots: { default: 'Continuer' } });
    expect(wrapper.get('button').attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('En préparation');
  });
  it('double la couleur par un symbole et un mot', () => {
    const wrapper = mount(CategoryLabel, { props: { label: 'Exemple', symbol: '★', tone: 'vip' } });
    expect(wrapper.text()).toContain('★'); expect(wrapper.text()).toContain('Exemple');
    expect(mount(StatusBanner, { props: { message: 'En préparation' } }).get('[role="status"]').text()).toContain('En préparation');
  });
});
