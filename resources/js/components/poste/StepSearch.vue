<script setup lang="ts">
import AdminIcon from '../admin/AdminIcon.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import PermanentSearch from './PermanentSearch.vue';
import AccueilOperations from './AccueilOperations.vue';
import PortraitThumb from './PortraitThumb.vue';
import type { PosteRepository } from '../../poste/repository';
import { ApiError, type Access, type Category, type Fiche, type SearchResult } from '../../poste/types';
const props = defineProps<{ repository: PosteRepository; refreshKey?: string | null; eventName?: string; categories?: Category[]; total?: number | null; period?: string | null }>();
const emit = defineEmits<{ select: [result: SearchResult]; open: [id: string]; started: []; unlisted: []; error: [error: ApiError] }>();
const query = ref(''); const results = ref<SearchResult[]>([]); const searched = ref(false); const loading = ref(false);
const showPermanent = ref(false);
const incidentReasons: Record<string, string> = { inconnu: 'Badge inconnu', annule: 'Badge annulé', restitue: 'Badge restitué', non_associe: 'Badge non associé', hors_periode: 'Hors période', autre_evenement: 'Autre événement', zone_non_autorisee: 'Zone non autorisée', poste_non_habilite: 'Opération non permise à ce poste' };
let timer: number | undefined; let sequence = 0;

// Onglets : « Tous » puis chaque catégorie de l'événement avec le nombre de personnes de la liste ; le filtre est appliqué par la recherche elle-même (serveur ou copie de la tablette).
const tab = ref('');
const tabs = computed(() => (props.categories ?? []).map(c => ({ id: c.id, name: c.name, count: c.visitor_count ?? null })));
const chosen = ref<SearchResult | null>(null); const preview = ref<Fiche | null>(null); let previewSeq = 0;
const accessInfo: Record<Access, { label: string; dot: string; chip: string }> = {
  autorise: { label: 'Autorisé', dot: 'bg-green-600', chip: 'poste-chip-ok' },
  hors_periode: { label: 'Hors période', dot: 'bg-amber-500', chip: 'poste-chip-warn' },
  refuse: { label: 'Refusé', dot: 'bg-red-600', chip: 'poste-chip-bad' },
  present: { label: 'Déjà entré', dot: 'bg-sky-600', chip: 'poste-chip-info' },
  sorti: { label: 'Sorti', dot: 'bg-slate-500', chip: 'poste-chip-info' },
};
const accessOf = (r: SearchResult) => accessInfo[r.access ?? 'autorise'];
async function choose(result: SearchResult) {
  chosen.value = result; preview.value = null;
  const mine = ++previewSeq;
  try { const f = await props.repository.visitor(result.id); if (mine === previewSeq) preview.value = f; } catch { /* l'aperçu est un confort : la sélection reste possible */ }
}
const day = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
const period = computed(() => (preview.value ? (day(preview.value.valid_from) === day(preview.value.valid_until) ? day(preview.value.valid_from) : `${day(preview.value.valid_from)} – ${day(preview.value.valid_until)}`) : (props.period ?? '—')));

/** Sans recherche : les dix premiers noms de la liste du poste, y compris hors ligne. */
async function load(value: string): Promise<void> {
  const mine = ++sequence;
  loading.value = true; searched.value = false; results.value = [];
  try {
    const found = await props.repository.search(value.trim().length >= 2 ? value.trim() : '', tab.value || null);
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
watch(tab, () => { window.clearTimeout(timer); void load(query.value); });
watch(results, list => { if (chosen.value && !list.some(r => r.id === chosen.value!.id)) { chosen.value = null; preview.value = null; } });
watch(() => props.refreshKey, () => { window.clearTimeout(timer); void load(query.value); });
onMounted(() => { void load(''); });
onBeforeUnmount(() => { window.clearTimeout(timer); ++sequence; });
</script>
<template>
  <section aria-labelledby="recherche-titre" class="poste-search" data-testid="poste-recherche">
    <div class="poste-search-main">
      <h2 id="recherche-titre" class="poste-h"><AdminIcon name="search" :size="26" /> 1. Recherche d’une personne</h2>
      <p class="text-base text-slate-700">Recherchez une personne dans la liste des participants, visiteurs ou publics autorisés.</p>
      <label class="poste-searchbox"><span class="sr-only">Nom du visiteur (n’importe quel mot du nom)</span>
        <AdminIcon name="search" :size="22" class="poste-searchbox-icon" />
        <input v-model="query" type="search" autocomplete="off" autocapitalize="off" spellcheck="false" class="field !min-h-14 !text-xl" placeholder="Tapez au moins 2 lettres (nom, prénom, organisation…)" autofocus>
      </label>
      <p v-if="query.trim().length < 2" class="text-base text-slate-700" data-testid="apercu-liste">
        Dix premières personnes de la liste. Tapez au moins 2 lettres pour rechercher un autre nom.
      </p>
      <p v-else class="text-base text-slate-700">Résultats correspondant à « {{ query.trim() }} » (10 maximum).</p>
      <div v-if="tabs.length" class="poste-tabs" role="group" aria-label="Filtrer par catégorie" data-testid="onglets-categories">
        <button type="button" :aria-pressed="tab === ''" :class="{ 'is-on': tab === '' }" data-testid="onglet-tous" @click="tab = ''"><AdminIcon name="users" :size="18" /> Tous<template v-if="total != null"> ({{ total }})</template></button>
        <button v-for="t in tabs" :key="t.id" type="button" :aria-pressed="tab === t.id" :class="{ 'is-on': tab === t.id }" :data-testid="'onglet-categorie-' + t.id" @click="tab = t.id">{{ t.name }}<template v-if="t.count !== null"> ({{ t.count }})</template></button>
      </div>
      <p v-if="loading" role="status">Chargement de la liste…</p>
      <p v-if="results.length" class="poste-table-head" aria-hidden="true"><span /><span>Nom et prénoms</span><span>Organisation / Fonction</span><span>Catégorie</span><span>Statut</span><span>Action</span></p>
      <ul class="agent-search-results poste-rows" aria-label="Résultats de la recherche">
        <li v-for="result in results" :key="result.id" class="poste-row" :class="{ 'is-chosen': chosen?.id === result.id, 'is-vip': result.category.priority_circuit }" :data-testid="'ligne-' + result.id" @click="choose(result)">
          <span class="poste-row-check" aria-hidden="true"><span :class="{ 'is-on': chosen?.id === result.id }">{{ chosen?.id === result.id ? '✓' : '' }}</span></span>
          <span class="poste-row-name"><PortraitThumb :repository="repository" :visitor-id="result.id" :name="result.full_name" :has-portrait="result.has_portrait" class="poste-thumb" /><span class="min-w-0"><strong class="block">{{ result.full_name }}</strong><span v-if="result.category.priority_circuit" class="block text-sm font-bold text-amber-900"><span aria-hidden="true">★</span> Personnalité VIP : circuit prioritaire, sans file d’attente</span><span v-if="result.homonyms > 1" class="block text-sm font-semibold text-red-800"><span aria-hidden="true">⚠</span> Homonyme : {{ result.homonyms }} personnes portent ce nom</span></span></span>
          <span class="poste-row-org min-w-0"><span class="block truncate">{{ result.organisation || 'Organisation non indiquée' }}</span><span class="block truncate text-sm text-slate-600">{{ result.job_title || '—' }} · reçu par {{ result.host || 'non indiqué' }}</span></span>
          <span class="poste-row-cat"><CategorySwatch :name="result.category.name" :color="result.category.color" :priority="result.category.priority_circuit" /></span>
          <span class="poste-row-status" :data-testid="'statut-' + result.id"><span class="inline-block size-2.5 rounded-full" :class="accessOf(result).dot" aria-hidden="true" /> {{ accessOf(result).label }}</span>
          <button type="button" class="poste-select" :class="{ 'is-primary': chosen?.id === result.id }" :aria-label="'Sélectionner ' + result.full_name" @click.stop="$emit('select', result)">Sélectionner <span aria-hidden="true">→</span></button>
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
      <div class="poste-side-card">
        <h2 id="apercu-titre" class="poste-h"><AdminIcon name="user" :size="24" /> Aperçu de la personne</h2>
        <p v-if="!chosen" class="rounded-lg border border-dashed p-4 text-slate-600" data-testid="apercu-vide">Touchez une ligne pour voir un aperçu de la personne avant de l’enregistrer.</p>
        <template v-else>
          <div class="flex items-start gap-3">
            <PortraitThumb :repository="repository" :visitor-id="chosen.id" :name="chosen.full_name" :has-portrait="chosen.has_portrait" class="poste-portrait" />
            <div class="min-w-0 flex-1"><p class="text-xl font-bold" data-testid="apercu-nom">{{ chosen.full_name }}</p><p class="truncate text-slate-700">{{ chosen.organisation || 'Organisation non indiquée' }}</p>
              <p class="mt-2 flex flex-wrap items-center gap-2"><CategorySwatch :name="chosen.category.name" :color="chosen.category.color" :priority="chosen.category.priority_circuit" /><span class="poste-chip" :class="accessOf(chosen).chip" data-testid="apercu-statut"><span aria-hidden="true">{{ chosen.access === 'autorise' || !chosen.access ? '✓' : '•' }}</span> {{ accessOf(chosen).label }}</span></p></div>
          </div>
          <dl class="poste-facts">
            <div><dt><AdminIcon name="users" :size="20" /> Type</dt><dd>{{ chosen.category.name }}</dd></div>
            <div><dt><AdminIcon name="user" :size="20" /> Fonction</dt><dd data-testid="apercu-fonction">{{ chosen.job_title || '—' }}</dd></div>
            <div><dt><AdminIcon name="info" :size="20" /> Motif</dt><dd data-testid="apercu-motif">{{ preview?.visit_purpose || '—' }}</dd></div>
            <div><dt><AdminIcon name="calendar" :size="20" /> Période</dt><dd data-testid="apercu-periode">{{ period }}</dd></div>
            <div><dt><AdminIcon name="calendar" :size="20" /> Événement</dt><dd>{{ eventName || '—' }}</dd></div>
          </dl>
        </template>
      </div>
      <button type="button" class="btn-main w-full" :disabled="!chosen" data-testid="continuer-enregistrement" @click="chosen && $emit('select', chosen)">Continuer l’enregistrement <span aria-hidden="true">→</span></button>
    </aside>
  </section>
</template>
