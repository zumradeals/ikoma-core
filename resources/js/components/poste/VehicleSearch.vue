<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import type { PosteRepository } from '../../poste/repository';
import { ApiError, type VehicleResult } from '../../poste/types';

/**
 * Recherche d'un véhicule par sa plaque, puis enregistrement de son entrée ou de sa sortie (en ligne uniquement).
 * Le mouvement d'un véhicule n'est jamais la présence de la personne : l'entrée de la personne se fait par son propre parcours d'accueil.
 */
const props = defineProps<{ repository: PosteRepository }>();
const open = ref(false); const query = ref('');
const results = ref<VehicleResult[]>([]); const searched = ref(false);
const busy = ref(false); const message = ref<string | null>(null); const error = ref<string | null>(null);
const kinds: Record<string, string> = { voiture: 'Voiture', moto: 'Moto', minibus: 'Minibus', camion: 'Camion', autre: 'Autre' };
let timer: number | undefined; let sequence = 0;

async function run(value: string): Promise<void> {
  const mine = ++sequence;
  try {
    const found = (await props.repository.searchVehicles?.(value.trim())) ?? [];
    if (mine === sequence) { results.value = found; searched.value = true; error.value = null; }
  } catch (e) {
    if (mine === sequence) { results.value = []; searched.value = false; error.value = e instanceof ApiError ? e.message : 'La recherche a échoué. Réessayez.'; }
  }
}
watch(query, value => {
  window.clearTimeout(timer); ++sequence; results.value = []; searched.value = false; message.value = null;
  if (value.trim().length < 3) return;
  timer = window.setTimeout(() => { void run(value); }, 200);
});
onBeforeUnmount(() => { window.clearTimeout(timer); ++sequence; });

async function move(v: VehicleResult): Promise<void> {
  if (busy.value) return;
  busy.value = true; error.value = null; message.value = null;
  try {
    if (v.status === 'annonce') await props.repository.vehicleEnter?.(crypto.randomUUID(), v.id);
    else await props.repository.vehicleExit?.(crypto.randomUUID(), v.id);
    message.value = v.status === 'annonce' ? `Entrée du véhicule ${v.plate} enregistrée.` : `Sortie du véhicule ${v.plate} enregistrée.`;
    await run(query.value);
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'L’opération a échoué. Réessayez ou appelez le superviseur.';
  } finally { busy.value = false; }
}
</script>

<template>
  <section class="space-y-3" data-testid="recherche-vehicules">
    <button type="button" class="btn-soft" :aria-expanded="open" data-testid="ouvrir-vehicules" @click="open = !open">{{ open ? 'Fermer la recherche de véhicule' : 'Chercher un véhicule par sa plaque' }}</button>
    <div v-if="open" class="space-y-4 rounded-2xl border-2 border-slate-300 bg-white p-5">
      <p class="text-base text-slate-700">L’entrée ou la sortie d’un véhicule n’est pas la présence de la personne : elle se fait à l’accueil, avec son badge.</p>
      <label class="block text-xl font-semibold">Plaque d’immatriculation
        <input v-model="query" type="search" autocomplete="off" autocapitalize="characters" spellcheck="false" class="field !min-h-16 !text-2xl uppercase" placeholder="Au moins 3 caractères" data-testid="chercher-plaque">
      </label>
      <p v-if="error" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-vehicule"><span aria-hidden="true">⚠</span> {{ error }}</p>
      <p v-if="message" role="status" class="rounded-xl border-2 border-green-700 bg-green-50 p-3 font-semibold text-green-950" data-testid="vehicule-ok"><span aria-hidden="true">✓</span> {{ message }}</p>
      <p v-if="searched && !results.length" role="status" data-testid="aucun-vehicule">Aucun véhicule annoncé ou présent ne correspond à « {{ query.trim() }} ».</p>
      <ul class="space-y-3" aria-label="Véhicules trouvés">
        <li v-for="v in results" :key="v.id" class="space-y-2 rounded-xl border-2 border-slate-300 p-4" :data-testid="'vehicule-' + v.id">
          <p class="text-2xl font-bold">{{ v.plate }} <span class="text-base font-normal">· {{ kinds[v.kind] ?? v.kind }}</span></p>
          <p>{{ v.full_name }} · <strong>{{ v.status === 'present' ? 'Véhicule présent sur le site' : 'Véhicule annoncé' }}</strong></p>
          <button type="button" class="btn-main" :disabled="busy" :data-testid="(v.status === 'annonce' ? 'entree-' : 'sortie-') + 'vehicule'" @click="move(v)">{{ v.status === 'annonce' ? 'Enregistrer l’entrée du véhicule' : 'Enregistrer la sortie du véhicule' }}</button>
        </li>
      </ul>
    </div>
  </section>
</template>
