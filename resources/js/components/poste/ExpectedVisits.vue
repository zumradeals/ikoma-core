<script setup lang="ts">
import { onMounted, ref } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import type { PosteRepository } from '../../poste/repository';
import { ApiError, type ExpectedVisit } from '../../poste/types';

/** Visites annoncées pas encore arrivées, pour retrouver vite la bonne fiche. En ligne uniquement ; ouvrir une visite ouvre la fiche d'accueil habituelle. */
const props = defineProps<{ repository: PosteRepository }>();
const emit = defineEmits<{ open: [id: string] }>();
const rows = ref<ExpectedVisit[]>([]);
const state = ref<'chargement' | 'pret' | 'erreur'>('chargement');
const error = ref('');
const hm = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

async function load(): Promise<void> {
  state.value = 'chargement'; error.value = '';
  try { rows.value = (await props.repository.expectedVisits?.()) ?? []; state.value = 'pret'; }
  catch (e) { error.value = e instanceof ApiError ? e.message : 'La liste n’a pas pu être chargée. Réessayez.'; state.value = 'erreur'; }
}
onMounted(() => { void load(); });
</script>

<template>
  <section class="space-y-3 rounded-2xl border-2 border-slate-300 bg-white p-5" aria-labelledby="attendus-titre" data-testid="attendus-poste">
    <h2 id="attendus-titre" class="text-2xl font-bold">Visites attendues</h2>
    <p class="text-base text-slate-700">Personnes annoncées qui ne sont pas encore arrivées. Une annonce n’est pas une présence : l’entrée se fait par la fiche d’accueil.</p>
    <p v-if="state === 'chargement'" role="status" data-testid="attendus-chargement">Chargement des visites attendues…</p>
    <div v-else-if="state === 'erreur'" role="alert" class="space-y-2 rounded-xl border-2 border-red-700 bg-red-50 p-3 text-red-900" data-testid="attendus-erreur"><p class="font-semibold"><span aria-hidden="true">⚠</span> {{ error }}</p><button type="button" class="btn-soft" data-testid="attendus-reessayer" @click="load">Réessayer</button></div>
    <template v-else>
      <p v-if="!rows.length" role="status" data-testid="aucune-attendue">Aucune visite annoncée en attente.</p>
      <ul class="space-y-3" aria-label="Visites attendues">
        <li v-for="v in rows" :key="v.id" class="space-y-1 rounded-xl border-2 p-4" :class="v.state === 'en_retard' ? 'border-orange-600 bg-orange-50' : 'border-slate-300'" :data-testid="'attendue-' + v.state">
          <p class="flex flex-wrap items-center justify-between gap-3"><strong class="text-2xl">{{ v.full_name }}</strong><CategorySwatch :name="v.category.name" :color="v.category.color" :priority="v.category.priority_circuit" /></p>
          <p>{{ v.organisation || 'Organisation non indiquée' }} · reçu par {{ v.host || 'non indiqué' }}</p>
          <p class="font-semibold" :class="v.state === 'en_retard' ? 'text-orange-900' : ''">{{ v.state === 'en_retard' ? 'En retard · attendue à ' : 'Attendue à ' }}{{ hm(v.expected_at) }}<template v-if="v.plates"> · véhicule {{ v.plates }}</template></p>
          <button type="button" class="btn-main" :data-testid="'ouvrir-attendue-' + v.id" @click="emit('open', v.id)">Ouvrir la fiche</button>
        </li>
      </ul>
    </template>
  </section>
</template>
