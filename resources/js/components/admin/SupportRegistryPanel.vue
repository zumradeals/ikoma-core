<script setup lang="ts">
import { ref } from 'vue';
import AdminIcon from './AdminIcon.vue';
import { request, type Registre, type SupportRow } from './badge-api';

/**
 * Colonne « Registre commun » : cartes permanentes et badges temporaires dans une seule liste (lecture seule). Sélectionner un support le consulte : aucune action n'est lancée d'ici.
 * Le serveur reste l'autorité (permission, filtres validés) ; les états viennent des cycles de vie existants.
 */
const props = defineProps<{ initial: Registre; filters: { q: string; type: string; etat: string }; states: Record<string, string>; selectedId: string | null }>();
const emit = defineEmits<{ select: [row: SupportRow] }>();

const registre = ref<Registre>(props.initial);
const q = ref(props.filters.q);
const type = ref(props.filters.type);
const etat = ref(props.filters.etat);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(page = 1) {
  loading.value = true; error.value = null;
  const params = new URLSearchParams({ q: q.value.trim(), type: type.value, etat: etat.value, page: String(page) });
  const { data, errors } = await request<Registre>('/administration/badges/supports?' + params.toString());
  loading.value = false;
  if (data) registre.value = data; else error.value = errors[0] ?? 'Le registre est indisponible.';
}
const dot: Record<string, string> = { actif: 'bg-green-600', associe: 'bg-green-600', disponible: 'bg-sky-600', restitue: 'bg-slate-500', remplace: 'bg-slate-500', perdu: 'bg-red-600', vole: 'bg-red-600', revoque: 'bg-orange-600', annule: 'bg-slate-400', clos: 'bg-slate-400' };
defineExpose({ load });
</script>

<template>
  <section class="flex h-full min-w-0 flex-col rounded-xl border border-slate-200 bg-white p-3" aria-labelledby="registre-titre" data-testid="colonne-registre">
    <h2 id="registre-titre" class="mb-2 flex items-center gap-2 text-heading font-bold"><AdminIcon name="list" :size="20" />Registre des supports <span class="text-base font-semibold text-slate-600" data-testid="registre-total">({{ registre.meta.total }})</span></h2>
    <form class="space-y-2" role="search" aria-label="Rechercher un support" @submit.prevent="load(1)">
      <label class="relative block"><span class="sr-only">Rechercher par numéro de support, personne ou identifiant</span>
        <AdminIcon name="search" :size="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
        <input v-model="q" type="search" maxlength="160" class="field !mt-0 !pl-10" placeholder="Rechercher (n° de support, nom, identifiant…)" data-testid="registre-recherche">
      </label>
      <div class="grid grid-cols-2 gap-2">
        <label class="block"><span class="sr-only">Type de support</span>
          <select v-model="type" class="field !mt-0" data-testid="registre-type" @change="load(1)"><option value="">Tous les types</option><option value="carte_permanente">Cartes permanentes</option><option value="badge_temporaire">Badges temporaires</option></select>
        </label>
        <label class="block"><span class="sr-only">État</span>
          <select v-model="etat" class="field !mt-0" data-testid="registre-etat" @change="load(1)"><option value="">Tous les états</option><option v-for="(label, key) in states" :key="key" :value="key">{{ label }}</option></select>
        </label>
      </div>
      <button type="submit" class="site-btn site-btn-outline w-full" :disabled="loading" data-testid="registre-filtrer">Filtrer</button>
    </form>

    <p v-if="error" role="alert" class="mt-2 rounded-lg border border-red-300 bg-red-50 p-2 text-sm text-red-900" data-testid="registre-erreur">{{ error }}</p>
    <p v-else-if="!registre.data.length" class="mt-3 rounded-lg border border-dashed p-4 text-center text-slate-600" data-testid="registre-vide">{{ loading ? 'Chargement…' : 'Aucun support ne correspond. Préparez un badge pour en créer un.' }}</p>
    <ul v-else class="mt-3 divide-y rounded-xl border" data-testid="registre-liste" :aria-busy="loading">
      <li v-for="row in registre.data" :key="row.id">
        <button type="button" class="block w-full min-w-0 px-3 py-2 text-left hover:bg-slate-50 focus-visible:outline focus-visible:outline-2" :class="selectedId === row.id ? 'bg-blue-50' : ''" :aria-pressed="selectedId === row.id" :data-testid="'support-' + row.code" @click="emit('select', row)">
          <span class="flex items-center justify-between gap-2"><strong class="truncate">{{ row.code }}</strong>
            <span class="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold"><span class="inline-block size-2.5 rounded-full" :class="dot[row.state] ?? 'bg-slate-400'" aria-hidden="true" />{{ row.state_label }}</span></span>
          <span class="block truncate">{{ row.holder_name ?? 'Sans titulaire nominatif' }}</span>
          <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs">
            <span class="rounded-full px-2 py-0.5 font-bold" :class="row.type === 'carte_permanente' ? 'bg-green-100 text-green-900' : 'bg-slate-200 text-slate-800'">{{ row.type === 'carte_permanente' ? 'Permanent' : 'Temporaire' }}</span>
            <span v-if="row.category" class="truncate text-slate-600">{{ row.category }}</span>
            <span class="text-slate-600">{{ row.printed ? '· imprimé' : '· non imprimé' }}</span>
          </span>
        </button>
      </li>
    </ul>
    <nav v-if="registre.meta.last_page > 1" class="mt-3 flex items-center justify-between gap-2 text-sm" aria-label="Pages du registre" data-testid="registre-pages">
      <button type="button" class="site-btn site-btn-outline !min-h-10" :disabled="loading || registre.meta.current_page <= 1" data-testid="registre-precedent" @click="load(registre.meta.current_page - 1)">Précédent</button>
      <span>Page {{ registre.meta.current_page }} / {{ registre.meta.last_page }}</span>
      <button type="button" class="site-btn site-btn-outline !min-h-10" :disabled="loading || registre.meta.current_page >= registre.meta.last_page" data-testid="registre-suivant" @click="load(registre.meta.current_page + 1)">Suivant</button>
    </nav>
    <p class="mt-auto pt-3 text-xs text-slate-600" data-testid="registre-liens"><slot name="footer" /></p>
  </section>
</template>
