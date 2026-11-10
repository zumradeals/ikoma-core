<script setup lang="ts">
import AdminIcon from '../admin/AdminIcon.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import PermanentSearch from './PermanentSearch.vue';
import AccueilOperations from './AccueilOperations.vue';
import type { PosteRepository } from '../../poste/repository';
import { ApiError, type Fiche, type SearchResult } from '../../poste/types';
const props = defineProps<{ repository: PosteRepository; refreshKey?: string | null; eventName?: string }>();
const emit = defineEmits<{ select: [result: SearchResult]; open: [id: string]; started: []; unlisted: []; error: [error: ApiError] }>();
const query = ref(''); const results = ref<SearchResult[]>([]); const searched = ref(false); const loading = ref(false);
const showPermanent = ref(false);
// Onglets de catégories et aperçu : filtrage sur les résultats affichés (la recherche ne renvoie que dix personnes), aperçu lu dans la fiche de la tablette.
const tab = ref('');
const chosen = ref<SearchResult | null>(null); const preview = ref<Fiche | null>(null); let previewSeq = 0;
const tabs = computed(() => { const seen = new Map<string, string>(); for (const r of results.value) seen.set(r.category.id, r.category.name); return [...seen].map(([id, name]) => ({ id, name, count: results.value.filter(r => r.category.id === id).length })); });
const shown = computed(() => (tab.value ? results.value.filter(r => r.category.id === tab.value) : results.value));
async function choose(result: SearchResult) {
  chosen.value = result; preview.value = null;
  const mine = ++previewSeq;
  try { const f = await props.repository.visitor(result.id); if (mine === previewSeq) preview.value = f; } catch { /* l'aperçu est un confort : la sélection reste possible */ }
}
const day = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
const period = computed(() => (preview.value ? (day(preview.value.valid_from) === day(preview.value.valid_until) ? day(preview.value.valid_from) : `${day(preview.value.valid_from)} – ${day(preview.value.valid_until)}`) : '—'));
const incidentReasons: Record<string, string> = { inconnu: 'Badge inconnu', annule: 'Badge annulé', restitue: 'Badge restitué', non_associe: 'Badge non associé', hors_periode: 'Hors période', autre_evenement: 'Autre événement', zone_non_autorisee: 'Zone non autorisée', poste_non_habilite: 'Opération non permise à ce poste' };
let timer: number | undefined; let sequence = 0;

/** Sans recherche : les dix premiers noms de la liste du poste, y compris hors ligne. */
async function load(value: string): Promise<void> {
  const mine = ++sequence;
  loading.value = true; searched.value = false; results.value = [];
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
  results.value = [];
  if (value.length > 0) emit('started');
  if (value.trim().length < 2) { void load(''); return; }
  timer = window.setTimeout(() => { void load(value); }, 150);
});
watch(results, list => { if (chosen.value && !list.some(r => r.id === chosen.value!.id)) { chosen.value = null; preview.value = null; } if (tab.value && !list.some(r => r.category.id === tab.value)) tab.value = ''; });
watch(() => props.refreshKey, () => { window.clearTimeout(timer); void load(query.value); });
onMounted(() => { void load(''); });
onBeforeUnmount(() => { window.clearTimeout(timer); ++sequence; });
</script>
<template>
  <section aria-labelledby="recherche-titre" class="poste-search" data-testid="poste-recherche">
    <div class="poste-search-main">
      <h2 id="recherche-titre" class="poste-h"><AdminIcon name="search" :size="26" /> 1. Recherche d’une personne</h2>
      <p class="text-base text-slate-700">Recherchez une personne dans la liste des participants, visiteurs ou publics autorisés.</p>
      <label class="block"><span class="sr-only">Nom du visiteur (n’importe quel mot du nom)</span>
        <input v-model="query" type="search" autocomplete="off" autocapitalize="off" spellcheck="false" class="field !min-h-14 !text-xl" placeholder="Tapez au moins 2 lettres (nom, prénom, organisation…)" autofocus>
      </label>
      <p v-if="query.trim().length < 2" class="text-base text-slate-700" data-testid="apercu-liste">
        Dix premières personnes de la liste. Tapez au moins 2 lettres pour rechercher un autre nom.
      </p>
      <p v-else class="text-base text-slate-700">Résultats correspondant à « {{ query.trim() }} » (10 maximum).</p>
      <div v-if="tabs.length > 1" class="poste-tabs" role="group" aria-label="Filtrer par catégorie" data-testid="onglets-categories">
        <button type="button" :aria-pressed="tab === ''" :class="{ 'is-on': tab === '' }" @click="tab = ''">Tous ({{ results.length }})</button>
        <button v-for="t in tabs" :key="t.id" type="button" :aria-pressed="tab === t.id" :class="{ 'is-on': tab === t.id }" @click="tab = t.id">{{ t.name }} ({{ t.count }})</button>
      </div>
      <p v-if="loading" role="status">Chargement de la liste…</p>
      <p v-if="results.length" class="poste-table-head" aria-hidden="true"><span>Nom et prénoms</span><span>Organisation / reçu par</span><span>Catégorie</span><span>Action</span></p>
      <ul class="agent-search-results poste-rows" aria-label="Résultats de la recherche">
        <li v-for="result in shown" :key="result.id" class="poste-row" :class="{ 'is-chosen': chosen?.id === result.id, 'is-vip': result.category.priority_circuit }" :data-testid="'ligne-' + result.id" @click="choose(result)">
          <span class="poste-row-name"><span class="agent-search-initial" aria-hidden="true">{{ result.full_name.charAt(0) }}</span><span class="min-w-0"><strong class="block">{{ result.full_name }}</strong><span v-if="result.category.priority_circuit" class="block text-sm font-bold text-amber-900"><span aria-hidden="true">★</span> Personnalité VIP : circuit prioritaire, sans file d’attente</span><span v-if="result.homonyms > 1" class="block text-sm font-semibold text-red-800"><span aria-hidden="true">⚠</span> Homonyme : {{ result.homonyms }} personnes portent ce nom</span></span></span>
          <span class="poste-row-org min-w-0"><span class="block truncate">{{ result.organisation || 'Organisation non indiquée' }}</span><span class="block truncate text-sm text-slate-600">reçu par {{ result.host || 'non indiqué' }}</span></span>
          <span class="poste-row-cat"><CategorySwatch :name="result.category.name" :color="result.category.color" :priority="result.category.priority_circuit" /></span>
          <button type="button" class="poste-select" :aria-label="'Sélectionner ' + result.full_name" @click.stop="$emit('select', result)">Sélectionner <span aria-hidden="true">→</span></button>
        </li>
      </ul>
      <p v-if="searched && !results.length && !loading && query.trim().length >= 2" role="status">Aucun visiteur ne correspond. Vérifiez l’orthographe ou enregistrez un visiteur non listé.</p>
      <p v-if="searched && !results.length && !loading && query.trim().length < 2" role="status">Aucune personne enregistrée pour cet événement. Vérifiez la liste ou signalez un visiteur non listé.</p>
      <div class="flex flex-wrap gap-3">
        <button type="button" class="btn-soft" @click="$emit('unlisted')"><AdminIcon name="plus" /> Visiteur non listé</button>
        <button v-if="repository.searchPermanent" type="button" class="btn-soft" :aria-expanded="showPermanent" data-testid="ouvrir-permanents" @click="showPermanent = !showPermanent">{{ showPermanent ? 'Fermer la recherche de personnes permanentes' : 'Chercher une personne permanente' }}</button>
      </div>
      <PermanentSearch v-if="showPermanent && repository.searchPermanent" :repository="repository" />
      <AccueilOperations v-if="repository.capabilities || repository.searchVehicles" :repository="repository" :reasons="incidentReasons" @open="id => emit('open', id)" />
    </div>
    <aside class="poste-search-side" aria-labelledby="apercu-titre" data-testid="apercu-personne">
      <h2 id="apercu-titre" class="poste-h"><AdminIcon name="user" :size="24" /> Aperçu de la personne</h2>
      <p v-if="!chosen" class="rounded-lg border border-dashed p-4 text-slate-600" data-testid="apercu-vide">Touchez une ligne pour voir un aperçu de la personne avant de l’enregistrer.</p>
      <template v-else>
        <div class="flex items-center gap-3">
          <span class="agent-search-initial" aria-hidden="true">{{ chosen.full_name.charAt(0) }}</span>
          <div class="min-w-0"><p class="truncate text-xl font-bold" data-testid="apercu-nom">{{ chosen.full_name }}</p><p class="truncate text-slate-700">{{ chosen.organisation || 'Organisation non indiquée' }}</p></div>
        </div>
        <p class="mt-2 flex flex-wrap gap-2"><CategorySwatch :name="chosen.category.name" :color="chosen.category.color" :priority="chosen.category.priority_circuit" />
          <span v-if="preview" class="poste-chip" :class="preview.hors_periode || preview.host_validation === 'refuse' ? 'poste-chip-warn' : 'poste-chip-ok'" data-testid="apercu-statut">{{ preview.host_validation === 'refuse' ? 'Refusé par l’hôte' : preview.hors_periode ? 'Hors période' : 'Dans la période' }}</span></p>
        <dl class="poste-facts">
          <div><dt>Catégorie</dt><dd>{{ chosen.category.name }}</dd></div>
          <div><dt>Reçu par</dt><dd>{{ chosen.host || '—' }}</dd></div>
          <div><dt>Période</dt><dd data-testid="apercu-periode">{{ period }}</dd></div>
          <div><dt>Événement</dt><dd>{{ eventName || '—' }}</dd></div>
          <div v-if="preview?.zones?.length"><dt>Zones</dt><dd>{{ preview.zones.join(', ') }}</dd></div>
        </dl>
      </template>
      <button type="button" class="btn-main w-full" :disabled="!chosen" data-testid="continuer-enregistrement" @click="chosen && $emit('select', chosen)">Continuer l’enregistrement <span aria-hidden="true">→</span></button>
    </aside>
  </section>
</template>
