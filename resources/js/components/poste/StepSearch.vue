<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import type { PosteRepository } from '../../poste/repository';
import { ApiError, type SearchResult } from '../../poste/types';
const props = defineProps<{ repository: PosteRepository }>();
const emit = defineEmits<{ select: [result: SearchResult]; started: []; unlisted: []; error: [error: ApiError] }>();
const query = ref(''); const results = ref<SearchResult[]>([]); const searched = ref(false); const loading = ref(false);
let timer: number | undefined; let sequence = 0;
watch(query, value => {
  window.clearTimeout(timer);
  if (value.length > 0) emit('started');
  if (value.trim().length < 2) { results.value = []; searched.value = false; return; }
  timer = window.setTimeout(async () => {
    const mine = ++sequence; loading.value = true;
    try { const found = await props.repository.search(value.trim()); if (mine === sequence) { results.value = found; searched.value = true; } }
    catch (e) { if (e instanceof ApiError) emit('error', e); }
    finally { if (mine === sequence) loading.value = false; }
  }, 150);
});
onBeforeUnmount(() => window.clearTimeout(timer));
</script>
<template>
  <section aria-labelledby="recherche-titre" class="space-y-5">
    <h1 id="recherche-titre" class="text-display font-bold">Qui se présente ?</h1>
    <label class="block text-xl font-semibold">Nom du visiteur (n’importe quel mot du nom)
      <input v-model="query" type="search" autocomplete="off" autocapitalize="off" spellcheck="false" class="field !min-h-16 !text-2xl" placeholder="Tapez au moins 2 lettres" autofocus>
    </label>
    <p v-if="loading" role="status">Recherche en cours…</p>
    <ul class="space-y-3" aria-label="Résultats de la recherche">
      <li v-for="result in results" :key="result.id">
        <button type="button" class="w-full rounded-xl border-2 bg-white p-4 text-left" :class="result.category.priority_circuit ? 'border-ikoma-gold bg-amber-50' : 'border-slate-300'" @click="$emit('select', result)">
          <span v-if="result.category.priority_circuit" class="mb-2 block font-bold text-amber-900"><span aria-hidden="true">★</span> Personnalité VIP : circuit prioritaire, sans file d’attente</span>
          <span class="flex flex-wrap items-center justify-between gap-3"><strong class="text-2xl">{{ result.full_name }}</strong><CategorySwatch :name="result.category.name" :color="result.category.color" :priority="result.category.priority_circuit" /></span>
          <span class="mt-1 block">{{ result.organisation || 'Organisation non indiquée' }} · reçu par {{ result.host || 'non indiqué' }}</span>
          <span v-if="result.homonyms > 1" class="mt-1 block font-semibold text-red-800"><span aria-hidden="true">⚠</span> Homonyme : {{ result.homonyms }} personnes portent ce nom</span>
        </button>
      </li>
    </ul>
    <p v-if="searched && !results.length && !loading" role="status">Aucun visiteur ne correspond. Vérifiez l’orthographe ou enregistrez un visiteur non listé.</p>
    <button type="button" class="btn-soft" @click="$emit('unlisted')"><span aria-hidden="true">＋</span> Visiteur non listé</button>
  </section>
</template>
