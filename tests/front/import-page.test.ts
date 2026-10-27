import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Imports from '../../resources/js/pages/Admin/Imports.vue';
const { post }=vi.hoisted(()=>({post:vi.fn()}));
vi.mock('@inertiajs/vue3',()=>({Link:{template:'<a><slot /></a>'},router:{get:vi.fn()},useForm:(data:object)=>reactive({...data,errors:{},processing:false,post})}));
const props={events:[{id:'event-fictif',name:'Événement fictif'}],selectedEvent:null,preview:null,result:null,results:[]};
beforeEach(()=>post.mockClear());
describe('parcours d’import',()=>{
 it('propose une option explicite désactivée et un véritable bouton de soumission',()=>{const w=mount(Imports,{props,global:{stubs:{BaseLayout:{template:'<main><slot /></main>'}}}});expect((w.get('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false);expect(w.get('form button').attributes('type')).toBe('submit');expect(post).not.toHaveBeenCalled();});
 it('exige un récapitulatif séparé avant de confirmer',async()=>{const w=mount(Imports,{props:{...props,preview:{id:'preview-fictif',event_id:'event-fictif',filename:'liste.csv',create_hosts:false,summary:{read:10,importable:8,blocked:2,warnings:1},anomalies:[],sample:[]}},global:{stubs:{BaseLayout:{template:'<main><slot /></main>'}}}});await w.findAll('button').find(b=>b.text()==='Continuer vers la confirmation')!.trigger('click');expect(w.text()).toContain('Vous allez ajouter 8 visiteurs');expect(post).not.toHaveBeenCalled();await w.findAll('button').find(b=>b.text()==='Importer les lignes valides')!.trigger('click');expect(post).toHaveBeenCalledWith('/administration/imports/confirmer',expect.any(Object));});
});
