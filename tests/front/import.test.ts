import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import ImportReport from '../../resources/js/components/ImportReport.vue';
const summary={ read:1000, importable:985, blocked:15, warnings:8 };
describe('rapport d’import',()=>{
 it('présente les nombres et explique les lignes exclues',()=>{const w=mount(ImportReport,{props:{summary,anomalies:[]}});expect(w.text()).toContain('1000');expect(w.text()).toContain('985');expect(w.text()).toContain('15');expect(w.text()).toContain('8');expect(w.text()).toContain('ne seront pas importées');});
 it('distingue blocage et avertissement avec mots et symboles, puis donne le second critère',()=>{const w=mount(ImportReport,{props:{summary,anomalies:[{line:2,name:'Fiche fictive',blocking:true,messages:['Doublon'],related:[{line:1,source:'dans le fichier'}]},{line:3,name:'Fiche fictive',blocking:false,messages:['Homonyme'],related:[{id:'uuid-fictif',organisation:'Organisation fictive',host:'Hôte fictif',source:'déjà enregistré'}]}]}});expect(w.text()).toContain('⛔ Bloquée');expect(w.text()).toContain('⚠ Avertissement');expect(w.text()).toContain('Ligne 3');expect(w.text()).toContain('Organisation fictive');expect(w.text()).toContain('Hôte fictif');expect(w.text()).toContain('uuid-fictif');});
 it('échappe les contenus du fichier',()=>{const w=mount(ImportReport,{props:{summary,anomalies:[{line:2,name:'<script>fictif</script>',blocking:true,messages:['Erreur'],related:[]}]}});expect(w.find('script').exists()).toBe(false);expect(w.text()).toContain('<script>fictif</script>');});
});
it('regroupe les fiches homonymes avec leurs seconds critères et un renvoi depuis la ligne',()=>{
 const w=mount(ImportReport,{props:{summary,anomalies:[{line:2,name:'Homonyme fictif',blocking:false,messages:['Homonyme'],related:[],group:1}],groups:[{number:1,name:'Homonyme fictif',references:[{line:2,organisation:'Atelier fictif',source:'dans le fichier'},{id:'uuid-fictif',host:'Hôte fictif',source:'déjà enregistré'}]}]}});
 expect(w.get('a').attributes('href')).toBe('#homonymes-1');expect(w.get('#homonymes-1').text()).toContain('Atelier fictif');expect(w.get('#homonymes-1').text()).toContain('Hôte fictif');expect(w.get('#homonymes-1').text()).toContain('non renseignée');
});
