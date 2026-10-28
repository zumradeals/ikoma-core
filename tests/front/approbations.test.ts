import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn(); let flash: string | null = null;
vi.mock('@inertiajs/vue3', () => ({
  router: { post: (...a: unknown[]) => post(...a), reload: vi.fn(), get: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: () => ({ errors: {}, processing: false }),
  usePage: () => ({ props: { flash: { status: flash }, errors: {} } }),
}));
import Approbations from '../../resources/js/pages/Admin/Approbations.vue';
import BaseLayout from '../../resources/js/layouts/BaseLayout.vue';

const mine = { id: 'a1', summary: 'Créer le compte « Nouveau fictif » avec le rôle superviseur.', requester: 'Admin A', created_at: '2027-01-10T10:00:00Z', expires_at: '2027-01-11T10:00:00Z', mine: true };
const other = { ...mine, id: 'a2', requester: 'Admin B', mine: false };

describe('validations d’un second administrateur', () => {
  beforeEach(() => { post.mockClear(); flash = null; });

  it('ne propose pas de valider sa propre demande, mais seulement celle des autres', async () => {
    const w = mount(Approbations, { props: { pending: [mine, other], soleAdministrator: false } });
    const items = w.findAll('[data-testid=liste-approbations] > li');
    expect(items).toHaveLength(2);
    expect(items[0].find('[data-testid=ma-demande]').text()).toContain('en attente d’un autre administrateur');
    expect(items[0].find('[data-testid=approuver]').exists()).toBe(false); // l'auteur ne peut pas se valider lui-même
    await items[1].get('[data-testid=approuver]').trigger('click');
    expect(post).toHaveBeenCalledWith('/administration/approbations/a2/approuver', {}, expect.objectContaining({ preserveScroll: true }));
  });

  it('demande confirmation avant de refuser', async () => {
    const w = mount(Approbations, { props: { pending: [other], soleAdministrator: false } });
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    await w.get('[data-testid=refuser]').trigger('click');
    expect(post).not.toHaveBeenCalled();
    confirm.mockReturnValue(true);
    await w.get('[data-testid=refuser]').trigger('click');
    expect(post).toHaveBeenCalledWith('/administration/approbations/a2/refuser', {}, expect.anything());
  });

  it('signale qu’il n’y a qu’un administrateur et affiche l’état vide', () => {
    const w = mount(Approbations, { props: { pending: [], soleAdministrator: true } });
    expect(w.get('[data-testid=administrateur-unique]').text()).toContain('Créez un second administrateur');
    expect(w.get('[data-testid=aucune-approbation]').text()).toBe('Aucune demande en attente.');
  });
});

describe('message de statut', () => {
  it('s’affiche en mots quand le serveur en envoie un, et pas sinon', () => {
    flash = 'Demande envoyée : un second administrateur doit la valider.';
    expect(mount(BaseLayout, { slots: { default: '<p>contenu</p>' } }).get('[data-testid=statut]').text()).toContain('Demande envoyée');
    flash = null;
    expect(mount(BaseLayout, { slots: { default: '<p>contenu</p>' } }).find('[data-testid=statut]').exists()).toBe(false);
  });
});
