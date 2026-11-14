<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { PosteRepository } from '../../poste/repository';
import ExpectedVisits from './ExpectedVisits.vue';
import IncidentsPanel from './IncidentsPanel.vue';
import VehicleSearch from './VehicleSearch.vue';

/**
 * Autres opérations du poste d'accueil, depuis l'écran de recherche : scan et contrôle, sortie, restitution (distincte de la sortie), véhicules, visites attendues,
 * refus et incidents. Chaque entrée n'apparaît que si la capacité du poste la permet (le serveur la revérifie) ; aucune ne duplique une fonction existante.
 */
const props = defineProps<{ repository: PosteRepository; reasons?: Record<string, string> }>();
const emit = defineEmits<{ open: [id: string] }>();
const panel = ref<'attendus' | 'incidents' | null>(null);
const online = ref(navigator.onLine);
const setOnline = () => { online.value = navigator.onLine; };
onMounted(() => { window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline); });
onBeforeUnmount(() => { window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); });

const caps = computed(() => props.repository.capabilities?.() ?? []);
const can = (capability: string) => caps.value.includes(capability);
const toggle = (next: 'attendus' | 'incidents') => { panel.value = panel.value === next ? null : next; };
</script>

<template>
  <section class="space-y-4" aria-labelledby="operations-titre" data-testid="operations-accueil">
    <h2 id="operations-titre" class="text-2xl font-bold">Autres opérations</h2>
    <p v-if="!online" role="status" class="rounded-xl border-2 border-amber-700 bg-amber-50 p-3 font-semibold text-amber-950" data-testid="operations-hors-ligne"><span aria-hidden="true">⚠</span> Sans réseau : changer d’écran, consulter les visites attendues ou les incidents nécessite le réseau.</p>
    <div class="flex flex-wrap gap-3">
      <button v-if="repository.expectedVisits && can('accueil')" type="button" class="btn-soft" :aria-expanded="panel === 'attendus'" :disabled="!online" data-testid="ouvrir-attendus" @click="toggle('attendus')">{{ panel === 'attendus' ? 'Fermer les visites attendues' : 'Visites attendues' }}</button>
      <button v-if="repository.incidents" type="button" class="btn-soft" :aria-expanded="panel === 'incidents'" :disabled="!online" data-testid="ouvrir-incidents" @click="toggle('incidents')">{{ panel === 'incidents' ? 'Fermer les refus et incidents' : 'Refus et incidents' }}</button>
    </div>
    <ExpectedVisits v-if="panel === 'attendus'" :repository="repository" @open="id => emit('open', id)" />
    <IncidentsPanel v-if="panel === 'incidents'" :repository="repository" :reasons="reasons" />
    <VehicleSearch v-if="repository.searchVehicles && can('accueil')" :repository="repository" />
  </section>
</template>
