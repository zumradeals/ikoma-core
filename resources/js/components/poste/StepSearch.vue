<script setup lang="ts">
import AdminIcon from '../admin/AdminIcon.vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import type { PosteRepository } from '../../poste/repository';
import { ApiError, type SearchResult } from '../../poste/types';
const props = defineProps<{ repository: PosteRepository; refreshKey?: string | null }>();
const emit = defineEmits<{ select: [result: SearchResult]; started: []; unlisted: []; error: [error: ApiError] }>();
const query = ref(''); const results = ref<SearchResult[]>([]); const searched = ref(false); const loading = ref(false);
let timer: number | undefined; let sequence = 0;

/** Sans recherche : les dix premiers noms de la liste du poste, y compris hors ligne. */
async function load(value: string): Promise<void> {
  const mine = ++sequence;
  loading.value = true; searched.value = false;
  try {
    const found = await props.repository.search(value.trim().length >= 2 ? value.trim() : '');
    if (mine === sequence) { results.value = found; searched.value = true; }
  } catch (e) {
    if (mine === sequence) { results.value = []; if (e instanceof ApiError) emit('error', e); }
  } finally {
    if (mine === sequence) loading.value = false;
  }
}

watch(query, value => {
  window.clearTimeout(timer);
  ++sequence; // annule immédiatement une ancienne réponse asynchrone
  if (value.length > 0) emit('started');
  if (value.trim().length < 2) { void load(''); return; }
  timer = window.setTimeout(() => { void load(value); }, 150);
});
watch(() => props.refreshKey, () => { window.clearTimeout(timer); void load(query.value); });
onMounted(() => { void load(''); });
onBeforeUnmount(() => { window.clearTimeout(timer); ++sequence; });
</script>
<template>
  <section aria-labelledby="recherche-titre" class="space-y-5">
    <h1 id="recherche-titre" class="text-display font-bold">Qui se présente ?</h1>
    <label class="block text-xl font-semibold">Nom du visiteur (n’importe quel mot du nom)
      <input v-model="query" type="search" autocomplete="off" autocapitalize="off" spellcheck="false" class="field !min-h-16 !text-2xl" placeholder="Tapez au moins 2 lettres" autofocus>
    </label>
    <p v-if="query.trim().length < 2" class="text-base text-slate-700" data-testid="apercu-liste">
      Dix premières personnes de la liste. Tapez au moins 2 lettres pour rechercher un autre nom.
    </p>
    <p v-else class="text-base text-slate-700">Résultats correspondant à « {{ query.trim() }} » (10 maximum).</p>
    <p v-if="loading" role="status">Chargement de la liste…</p>
    <ul class="agent-search-results" aria-label="Résultats de la recherche">
      <li v-for="result in results" :key="result.id">
        <button type="button" class="w-full rounded-xl border-2 bg-white p-4 text-left" :class="result.category.priority_circuit ? 'border-ikoma-gold bg-amber-50' : 'border-slate-300'" @click="$emit('select', result)">
<span class="agent-search-initial" aria-hidden="true">{{ result.full_name.charAt(0) }}</span><span class="agent-search-person">
          <span v-if="result.category.priority_circuit" class="mb-2 block font-bold text-amber-900"><span aria-hidden="true">★</span> Personnalité VIP : circuit prioritaire, sans file d’attente</span>
          <span class="flex flex-wrap items-center justify-between gap-3"><strong class="text-2xl">{{ result.full_name }}</strong><CategorySwatch :name="result.category.name" :color="result.category.color" :priority="result.category.priority_circuit" /></span>
          <span class="mt-1 block">{{ result.organisation || 'Organisation non indiquée' }} · reçu par {{ result.host || 'non indiqué' }}</span>
          <span v-if="result.homonyms > 1" class="mt-1 block font-semibold text-red-800"><span aria-hidden="true">⚠</span> Homonyme : {{ result.homonyms }} personnes portent ce nom</span>
        </span><span aria-hidden="true">›</span>
</button>
      </li>
    </ul>
    <p v-if="searched && !results.length && !loading && query.trim().length >= 2" role="status">Aucun visiteur ne correspond. Vérifiez l’orthographe ou enregistrez un visiteur non listé.</p>
    <p v-if="searched && !results.length && !loading && query.trim().length < 2" role="status">Aucune personne enregistrée pour cet événement. Vérifiez la liste ou signalez un visiteur non listé.</p>
    <button type="button" class="btn-soft" @click="$emit('unlisted')"><AdminIcon name="plus" /> Visiteur non listé</button>
  </section>
</template>
