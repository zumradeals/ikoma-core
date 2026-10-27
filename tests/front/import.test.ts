import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import ImportReport from '../../resources/js/components/ImportReport.vue';
const summary={ read:1000, importable:985, blocked:15, warnings:8 };
describe('rapport d’import',()=>{
 it('présente les nombres et explique les lignes exclues',()=>{const w=mount(ImportReport,{props:{summary,anomalies:[]}});expect(w.text()).toContain('1000');expect(w.text()).toContain('985');expect(w.text()).toContain('15');expect(w.text()).toContain('8');expect(w.text()).toContain('ne seront pas importées');});
 it('distingue blocage et avertissement avec mots et symboles, puis donne le second critère',()=>{const w=mount(ImportReport,{props:{summary,anomalies:[{line:2,name:'Fiche fictive',blocking:true,messages:['Doublon'],related:[{line:1,source:'dans le fichier'}]},{line:3,name:'Fiche fictive',blocking:false,messages:['Homonyme'],related:[{id:'uuid-fictif',organisation:'Organisation fictive',host:'Hôte fictif',source:'déjà enregistré'}]}]}});expect(w.text()).toContain('⛔ Bloquée');expect(w.text()).toContain('⚠ Avertissement');expect(w.text()).toContain('Ligne 3');expect(w.text()).toContain('Organisation fictive');expect(w.text()).toContain('Hôte fictif');expect(w.text()).toContain('uuid-fictif');});
 it('échappe les contenus du fichier',()=>{const w=mount(ImportReport,{props:{summary,anomalies:[{line:2,name:'<script>fictif</script>',blocking:true,messages:['Erreur'],related:[]}]}});expect(w.find('script').exists()).toBe(false);expect(w.text()).toContain('<script>fictif</script>');});
});
