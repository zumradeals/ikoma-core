<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import type { PosteRepository } from '../../poste/repository';
import { cardLabel, type PermanentResult } from '../../poste/local/permanent';

/** Recherche d'une personne permanente à l'accueil : information seulement, aucune action de pointage ni d'accès. */
const props = defineProps<{ repository: PosteRepository }>();
const query = ref('');
const results = ref<PermanentResult[]>([]);
const searched = ref(false);
let timer: number | undefined; let sequence = 0;

async function run(value: string): Promise<void> {
  const mine = ++sequence;
  const found = (await props.repository.searchPermanent?.(value.trim())) ?? [];
  if (mine === sequence) { results.value = found; searched.value = true; }
}
watch(query, value => {
  window.clearTimeout(timer); ++sequence; results.value = []; searched.value = false;
  if (value.trim().length < 2) return;
  timer = window.setTimeout(() => { void run(value); }, 150);
});
onBeforeUnmount(() => { window.clearTimeout(timer); ++sequence; });
const alert = (state: string) => state !== 'active';
</script>

<template>
  <section aria-labelledby="permanents-titre" class="space-y-4 rounded-2xl border-2 border-slate-300 bg-white p-5" data-testid="recherche-permanents">
    <h2 id="permanents-titre" class="text-2xl font-bold">Une personne permanente ?</h2>
    <p class="text-base text-slate-700">Retrouvez son état et ses accès. Cet écran informe seulement : l’entrée se contrôle avec la carte, au point d’accès.</p>
    <label class="block text-xl font-semibold">Nom, identifiant ou organisation
      <input v-model="query" type="search" autocomplete="off" autocapitalize="off" spellcheck="false" class="field !min-h-16 !text-2xl" placeholder="Tapez au moins 2 lettres" data-testid="chercher-permanent">
    </label>
    <p v-if="searched && results.length === 0" role="status" data-testid="aucun-permanent">Aucune personne permanente ne correspond à « {{ query.trim() }} ».</p>
    <ul class="space-y-3" aria-label="Personnes permanentes trouvées">
      <li v-for="r in results" :key="r.id" class="space-y-2 rounded-xl border-2 border-slate-300 p-4" :data-testid="'permanent-' + r.identifier">
        <p class="flex flex-wrap items-center justify-between gap-3"><strong class="text-2xl">{{ r.name }}</strong><CategorySwatch v-if="r.category" :name="r.category.name" :color="r.category.color" :priority="false" /></p>
        <p class="text-base"><span class="font-mono">{{ r.identifier }}</span><span v-if="r.organisation"> · {{ r.organisation }}</span></p>
        <p class="font-bold" :class="alert(r.card) ? 'text-red-800' : 'text-green-900'" :data-testid="'carte-' + r.identifier"><span aria-hidden="true">{{ alert(r.card) ? '⚠' : '✓' }}</span> {{ cardLabel(r.card) }}</p>
        <p class="text-base" :data-testid="'acces-' + r.identifier">{{ r.zones.length ? 'Accès en cours : ' + r.zones.join(', ') : 'Aucun accès en cours pour cet événement' }}</p>
      </li>
    </ul>
  </section>
</template>
