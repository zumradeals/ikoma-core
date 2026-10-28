import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Guests from '../../resources/js/pages/Hote/Guests.vue';
import GuestForm from '../../resources/js/components/hote/GuestForm.vue';
import HostNotificationCard from '../../resources/js/components/hote/HostNotificationCard.vue';
import HostAccountLink from '../../resources/js/components/hote/HostAccountLink.vue';
const { post,put }=vi.hoisted(()=>({post:vi.fn(),put:vi.fn()}));
vi.mock('@inertiajs/vue3',()=>({Link:{template:'<a><slot /></a>'},useForm:(data:object)=>reactive({...data,errors:{},processing:false,post,put})}));
const host={id:'h1',name:'Hôte fictif',event_id:'e1',event_name:'Événement fictif',starts_at:'2027-01-10T08:00:00Z',ends_at:'2027-01-10T20:00:00Z'};
const category={id:'c1',event_id:'e1',name:'Visiteur'};
const notice={id:'n1',type:'non_liste' as const,message:'Une demande attend votre réponse.',full_name:'Invité fictif',organisation:'Organisation fictive',agent:'Agent fictif',created_at:'2027-01-10T10:00:00Z',read_at:null,decision:null,decided_at:null};
beforeEach(()=>{post.mockClear();put.mockClear();});
describe('espace hôte',()=>{
 it('prépare la période de l’événement et propose uniquement ses catégories',()=>{const w=mount(GuestForm,{props:{hosts:[host],categories:[category,{id:'c2',event_id:'e2',name:'Autre événement'}]}});expect(w.text()).toContain('Visiteur');expect(w.text()).not.toContain('Autre événement');expect((w.get('input[type="datetime-local"]').element as HTMLInputElement).value).toBe('2027-01-10T08:00');});
 it('verrouille les champs après l’arrivée',()=>{const w=mount(GuestForm,{props:{hosts:[host],categories:[category],locked:true}});expect(w.get('fieldset').attributes('disabled')).toBeDefined();expect(w.get('button[type="submit"]').attributes('disabled')).toBeDefined();expect(w.text()).toContain('déjà arrivé');});
 it('accepte une demande sans saisie inutile et distingue le refus par mot et symbole',async()=>{const w=mount(HostNotificationCard,{props:{notice}});await w.findAll('button').find(b=>b.text().includes('J’accepte'))!.trigger('click');expect(post).toHaveBeenCalledWith('/hote/notifications/n1/decision');expect(w.text()).toContain('✕ Je refuse');expect(w.text()).toContain('zone d’accueil uniquement');});
 it('permet un refus avec motif facultatif et ne laisse pas décider une seconde fois',async()=>{const w=mount(HostNotificationCard,{props:{notice}});await w.findAll('button').find(b=>b.text().includes('Je refuse'))!.trigger('click');expect(w.text()).toContain('Motif du refus (facultatif)');await w.get('form').trigger('submit.prevent');expect(post).toHaveBeenCalledWith('/hote/notifications/n1/decision');await w.setProps({notice:{...notice,decision:'refuse',decided_at:'2027-01-10T10:01:00Z'}});expect(w.text()).toContain('Vous avez refusé');expect(w.text()).toContain('Demande traitée.');expect(w.text()).not.toContain('attend votre réponse');expect(w.text()).not.toContain('J’accepte');});
 it('n’affiche pas de décision sur une simple notification d’arrivée',()=>{const w=mount(HostNotificationCard,{props:{notice:{...notice,type:'arrivee'}}});expect(w.text()).toContain('Marquer comme lue');expect(w.text()).not.toContain('J’accepte');});
 it('interdit de cocher une fiche rattachée à un autre compte',()=>{const w=mount(HostAccountLink,{props:{user:{id:'u1',name:'Compte fictif'},hosts:[{id:'h1',name:'Hôte personnel',event_name:'Événement fictif',user_id:'u1'},{id:'h2',name:'Hôte voisin',event_name:'Événement fictif',user_id:'u2'}]}});expect((w.get('input[value="h1"]').element as HTMLInputElement).checked).toBe(true);expect(w.get('input[value="h2"]').attributes('disabled')).toBeDefined();});
});

it('revient à la liste après un ajout réussi et conserve le formulaire en cas d’erreur',async()=>{
 post.mockImplementation((_url:string,opts?:{onSuccess?:()=>void})=>opts?.onSuccess?.());
 const w=mount(Guests,{props:{hosts:[host],categories:[category],guests:{data:[],prev_page_url:null,next_page_url:null},feedback:null},global:{stubs:{BaseLayout:{template:'<main><slot /></main>'}}}});
 await w.findAll('button').find(b=>b.text()==='Ajouter un invité')!.trigger('click');expect(w.find('form').exists()).toBe(true);await w.get('form').trigger('submit.prevent');expect(w.find('form').exists()).toBe(false);post.mockReset();await w.findAll('button').find(b=>b.text()==='Ajouter un invité')!.trigger('click');await w.get('form').trigger('submit.prevent');expect(w.find('form').exists()).toBe(true);
});
