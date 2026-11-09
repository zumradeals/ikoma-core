<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import AdminIcon from './AdminIcon.vue';
import { newOperation, request, submitPrint, type Person, type Plan, type Result } from './badge-api';

/**
 * Colonne « Préparer un badge » : Personne → Détails → Génération → Impression, pour une personne ou pour une liste de personnes validées.
 * Aucune règle ici : l'éligibilité, la génération (idempotente, une opération par parcours) et l'impression sont celles du serveur (BadgeIssuance, SupportPrinting).
 * Un badge identifie une personne : il ne donne aucune habilitation et n'est ni remis ni activé par sa génération ou son impression.
 */
type Support = 'badge_temporaire' | 'carte_permanente';
const props = defineProps<{ events: { id: string; name: string; kind: string; starts_at: string; ends_at: string }[]; max: number; preset?: { event: string; ids: string[] } | null }>();
const emit = defineEmits<{ person: [person: Person | null]; supports: [badgeIds: string[]]; focus: [badgeId: string]; done: [] }>();

const step = ref<1 | 2 | 3 | 4>(1);
const support = ref<Support>('badge_temporaire');
const event = ref(props.preset?.event ?? props.events[0]?.id ?? '');
const query = ref('');
const people = ref<Person[]>([]);
const searched = ref(false);
const selected = ref<string[]>([]);
const plan = ref<Plan | null>(null);
const result = ref<Result | null>(null);
const errors = ref<string[]>([]);
const busy = ref(false);
const reason = ref('');
const operation = ref(newOperation());

async function call<T>(path: string, body?: unknown): Promise<T | null> {
  errors.value = []; busy.value = true;
  const { data, errors: e } = await request<T>(path, body);
  busy.value = false; errors.value = e;
  return data;
}

const eligible = computed(() => people.value.filter(p => p.state === 'a_generer'));
const names = computed(() => Object.fromEntries(people.value.map(p => [p.id, p.name])));
const supportLabel = computed(() => (support.value === 'badge_temporaire' ? 'badge temporaire' : 'carte permanente'));
const chosen = computed(() => people.value.filter(p => selected.value.includes(p.id)));
const models = computed(() => {
  const seen = new Map<string, string>();
  for (const p of chosen.value) seen.set(p.model?.code ?? 'neutre', p.model ? `${p.model.label} (v${p.model.version}${p.model.validated ? '' : ', à valider'})` : 'Modèle neutre provisoire (catégorie non rattachée)');
  return [...seen.values()];
});
const mode = computed(() => (selected.value.length > 1 ? 'groupée' : 'unitaire'));

function resetAll() { step.value = 1; selected.value = []; plan.value = null; result.value = null; errors.value = []; operation.value = newOperation(); reason.value = ''; emit('person', null); }
function changeSupport(value: Support) { support.value = value; people.value = []; selected.value = []; searched.value = false; plan.value = null; emit('person', null); }
async function search() {
  const params = new URLSearchParams({ support: support.value, q: query.value.trim() });
  if (support.value === 'badge_temporaire') params.set('event', event.value);
  const data = await call<{ people: Person[] }>('/administration/badges/preparer/personnes?' + params.toString());
  if (data) { people.value = data.people; searched.value = true; selected.value = selected.value.filter(id => data.people.some(p => p.id === id)); }
}
function toggle(p: Person) {
  const on = selected.value.includes(p.id);
  selected.value = on ? selected.value.filter(x => x !== p.id) : selected.value.length < props.max ? [...selected.value, p.id] : selected.value;
  emit('person', on ? (chosen.value.at(-1) ?? null) : p);
}
function selectEligible() { selected.value = eligible.value.slice(0, props.max).map(p => p.id); emit('person', chosen.value[0] ?? null); }
function clearSelection() { selected.value = []; emit('person', null); }
async function verify() {
  const data = await call<{ plan: Plan }>('/administration/badges/preparer/recapitulatif', { support: support.value, ids: selected.value });
  if (data) { plan.value = data.plan; step.value = 2; }
}
async function generate() {
  if (!plan.value || plan.value.a_generer.length === 0) return;
  const data = await call<Result>('/administration/badges/preparer/generer', { support: support.value, ids: plan.value.a_generer, operation: operation.value, confirm: true });
  if (data) { result.value = data; step.value = 4; emit('supports', data.printable); emit('done'); }
}
const printIds = computed(() => result.value?.printable ?? []);
const stateLabel: Record<Person['state'], string> = { a_generer: 'À générer', existant: 'Support déjà en cours', non_eligible: 'Non éligible' };
const stateClass: Record<Person['state'], string> = { a_generer: 'bg-green-100 text-green-900', existant: 'bg-sky-100 text-sky-900', non_eligible: 'bg-slate-200 text-slate-700' };
const steps = ['Personne', 'Détails', 'Génération', 'Impression'];
// Venue d'une fiche d'activité : mêmes parcours, personnes déjà choisies ; chaque étape est de nouveau vérifiée côté serveur.
onMounted(async () => {
  if (!props.preset) return;
  await search();
  selected.value = props.preset.ids.filter(id => people.value.some(p => p.id === id && p.state !== 'non_eligible')).slice(0, props.max);
  emit('person', chosen.value[0] ?? null);
});
</script>

<template>
  <section class="min-w-0 rounded-xl border border-slate-200 bg-white p-3" aria-labelledby="preparer-titre" data-testid="page-preparer-badge">
    <h2 id="preparer-titre" class="mb-3 flex items-center gap-2 text-heading font-bold"><AdminIcon name="plus" :size="20" />Préparer un badge</h2>

    <ol class="mb-3 grid grid-cols-4 gap-1 text-center text-xs" aria-label="Étapes" data-testid="etapes">
      <li v-for="(label, i) in steps" :key="label" class="rounded-lg border p-1.5 font-semibold" :class="step === i + 1 ? 'border-blue-600 bg-blue-50 text-blue-900' : step > i + 1 ? 'border-green-600 bg-green-50' : 'border-slate-300 bg-white'" :aria-current="step === i + 1 ? 'step' : undefined">
        <span class="block text-base">{{ step > i + 1 ? '✓' : i + 1 }}</span>{{ label }}
      </li>
    </ol>

    <p v-for="e in errors" :key="e" role="alert" class="mb-2 rounded-lg border border-red-300 bg-red-50 p-2 font-semibold text-red-900" data-testid="erreur-preparation">{{ e }}</p>

    <!-- 1. Personne -->
    <div v-if="step === 1" class="space-y-3" data-testid="etape-personnes">
      <fieldset class="grid gap-2 sm:grid-cols-2">
        <legend class="mb-1 font-semibold">Type de support <span aria-hidden="true">*</span></legend>
        <label class="flex min-h-14 cursor-pointer items-center gap-2 rounded-xl border-2 p-2" :class="support === 'carte_permanente' ? 'border-blue-600 bg-blue-50' : 'border-slate-300'">
          <input type="radio" name="support" value="carte_permanente" :checked="support === 'carte_permanente'" data-testid="support-carte" @change="changeSupport('carte_permanente')">
          <span><strong class="block">Carte permanente</strong><small>Personnel permanent : titulaires actifs</small></span>
        </label>
        <label class="flex min-h-14 cursor-pointer items-center gap-2 rounded-xl border-2 p-2" :class="support === 'badge_temporaire' ? 'border-blue-600 bg-blue-50' : 'border-slate-300'">
          <input type="radio" name="support" value="badge_temporaire" :checked="support === 'badge_temporaire'" data-testid="support-temporaire" @change="changeSupport('badge_temporaire')">
          <span><strong class="block">Badge temporaire</strong><small>Visite, événement, stage : participants d’une activité</small></span>
        </label>
      </fieldset>
      <form class="grid gap-2" role="search" aria-label="Rechercher des personnes" @submit.prevent="search">
        <label v-if="support === 'badge_temporaire'" class="block">Activité
          <select v-model="event" class="field" data-testid="choix-activite"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </label>
        <label class="block">Rechercher une personne (nom{{ support === 'carte_permanente' ? ', identifiant' : '' }})
          <input v-model="query" class="field" maxlength="160" placeholder="Laisser vide pour afficher la liste" data-testid="recherche-personnes">
        </label>
        <button type="submit" class="site-btn site-btn-blue" :disabled="busy || (support === 'badge_temporaire' && !event)" data-testid="lancer-recherche">Afficher les personnes</button>
      </form>
      <p class="text-xs text-slate-600">Un badge identifie une personne : il ne donne <strong>aucune habilitation</strong> et ne demande ni lot ni réservation.</p>
      <p v-if="support === 'badge_temporaire' && !events.length" class="text-sm text-amber-900" role="status">Aucune activité ouverte : créez ou rouvrez une activité dans « Activités ».</p>
      <p class="text-xs text-slate-600">La sélection porte sur l’identité enregistrée, jamais sur un nom seul. Une personne absente se crée d’abord dans « Personnes ».</p>

      <div v-if="searched" class="space-y-2">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-sm font-semibold" data-testid="compte-selection">{{ selected.length }} sélectionnée{{ selected.length > 1 ? 's' : '' }} · {{ eligible.length }} éligible{{ eligible.length > 1 ? 's' : '' }} sur {{ people.length }}</p>
          <span class="flex gap-2"><button type="button" class="site-btn site-btn-outline !min-h-10" data-testid="tout-eligible" @click="selectEligible">Tous les éligibles</button><button type="button" class="site-btn site-btn-outline !min-h-10" @click="clearSelection">Aucune</button></span>
        </div>
        <p v-if="!people.length" data-testid="aucune-personne">Aucune personne ne correspond.</p>
        <ul v-else class="max-h-80 divide-y overflow-y-auto rounded-xl border" data-testid="liste-personnes">
          <li v-for="p in people" :key="p.id" class="flex flex-wrap items-center gap-2 p-2" :data-testid="'personne-' + p.id">
            <input :id="'sel-' + p.id" type="checkbox" class="size-6" :checked="selected.includes(p.id)" :disabled="p.state === 'non_eligible'" :aria-label="'Sélectionner ' + p.name" :data-testid="'choisir-' + p.id" @change="toggle(p)">
            <label :for="'sel-' + p.id" class="min-w-0 grow"><strong class="block truncate">{{ p.name }}</strong><small class="text-slate-600">{{ p.organisation ?? '—' }}<template v-if="p.category"> · {{ p.category }}</template></small></label>
            <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="stateClass[p.state]" :data-testid="'etat-' + p.id">{{ stateLabel[p.state] }}<template v-if="p.code"> · {{ p.code }}</template></span>
            <small v-if="p.reason" class="w-full text-slate-700">{{ p.reason }}</small>
          </li>
        </ul>
        <button type="button" class="site-btn site-btn-accent w-full" :disabled="busy || selected.length === 0" data-testid="verifier" @click="verify"><AdminIcon name="arrow" :size="18" />Suivant : détails ({{ selected.length }})</button>
      </div>
    </div>

    <!-- 2. Détails -->
    <div v-if="step === 2 && plan" class="space-y-3" data-testid="etape-verification">
      <p class="font-semibold">Préparation {{ mode }} · {{ supportLabel }}</p>
      <dl class="grid grid-cols-2 gap-2">
        <div class="rounded-xl border bg-green-50 p-2"><dd class="text-2xl font-extrabold" data-testid="nb-a-generer">{{ plan.a_generer.length }}</dd><dt class="text-sm">Nouveaux supports à générer</dt></div>
        <div class="rounded-xl border bg-sky-50 p-2"><dd class="text-2xl font-extrabold" data-testid="nb-existants">{{ plan.existants.length }}</dd><dt class="text-sm">Déjà pourvus</dt></div>
        <div class="rounded-xl border bg-slate-100 p-2"><dd class="text-2xl font-extrabold" data-testid="nb-non-eligibles">{{ plan.non_eligibles.length }}</dd><dt class="text-sm">Non éligibles</dt></div>
        <div class="rounded-xl border bg-white p-2"><dd class="text-2xl font-extrabold">{{ plan.doublons_ignores }}</dd><dt class="text-sm">Doublons ignorés</dt></div>
      </dl>
      <div class="rounded-xl border p-2 text-sm" data-testid="modele-institutionnel"><strong class="block">Modèle institutionnel</strong><span v-for="m in models" :key="m" class="block">{{ m }}</span>
        <span class="block text-xs text-slate-600">Le modèle est celui de la catégorie institutionnelle de chaque personne (référentiel unique des modèles) ; il se modifie dans « Modèles institutionnels ».</span></div>
      <p class="rounded-xl border p-2 text-sm" data-testid="regle-photo"><strong class="block">Photo</strong>Facultative : reprise de la fiche de la personne si elle existe, et seulement si son impression est autorisée (un refus est respecté).</p>
      <ul v-if="plan.existants.length" class="space-y-1 text-sm" data-testid="liste-existants"><li v-for="x in plan.existants" :key="x.id">{{ names[x.id] ?? x.id }} : support {{ x.code }} déjà en cours — conservé (réimpression ou remplacement explicite possibles).</li></ul>
      <ul v-if="plan.non_eligibles.length" class="space-y-1 text-sm text-amber-900" data-testid="liste-non-eligibles"><li v-for="x in plan.non_eligibles" :key="x.id">{{ names[x.id] ?? x.id }} : {{ x.message }}</li></ul>
      <div class="flex flex-wrap justify-between gap-2">
        <button type="button" class="site-btn site-btn-outline" data-testid="retour-personnes" @click="step = 1">Annuler / modifier</button>
        <button type="button" class="site-btn site-btn-accent" :disabled="plan.a_generer.length === 0" data-testid="continuer" @click="step = 3">Suivant</button>
      </div>
    </div>

    <!-- 3. Génération -->
    <div v-if="step === 3 && plan" class="space-y-3" data-testid="etape-generation">
      <p>Vous allez générer <strong data-testid="nb-confirmation">{{ plan.a_generer.length }}</strong> {{ supportLabel }}{{ plan.a_generer.length > 1 ? 's' : '' }}, chacun avec un numéro unique et un QR sécurisé distinct. Rien ne sera activé, remis ni habilité.</p>
      <p class="text-sm text-slate-600">Si la demande est répétée ou interrompue, aucun doublon n’est créé : les supports déjà générés sont conservés.</p>
      <div class="flex flex-wrap justify-between gap-2">
        <button type="button" class="site-btn site-btn-outline" @click="step = 2">Retour</button>
        <button type="button" class="site-btn site-btn-accent" :disabled="busy" data-testid="generer" @click="generate">{{ busy ? 'Génération…' : 'Générer les badges' }}</button>
      </div>
    </div>

    <!-- 4. Impression -->
    <div v-if="step === 4 && result" class="space-y-3" data-testid="etape-impression">
      <p v-if="result.complete" class="rounded-lg border border-green-300 bg-green-50 p-2 font-semibold text-green-900" role="status" data-testid="resultat-complet">{{ result.created.length }} support{{ result.created.length > 1 ? 's' : '' }} généré{{ result.created.length > 1 ? 's' : '' }}.</p>
      <p v-else class="rounded-lg border border-amber-300 bg-amber-50 p-2 font-semibold text-amber-900" role="alert" data-testid="resultat-partiel">Génération partielle : {{ result.created.length }} réussite(s), {{ result.failed.length }} échec(s). Aucun succès total n’est annoncé.</p>
      <ul v-if="result.created.length" class="flex flex-wrap gap-2 text-sm" data-testid="liste-generes"><li v-for="c in result.created" :key="c.badge_id"><button type="button" class="rounded-lg border px-2 py-1 font-semibold hover:bg-slate-50" :data-testid="'voir-' + c.code" @click="emit('focus', c.badge_id)">{{ c.code }}</button></li></ul>
      <ul v-if="result.failed.length" class="space-y-1 text-sm text-red-800" data-testid="liste-echecs"><li v-for="f in result.failed" :key="f.id">{{ names[f.id] ?? f.id }} : {{ f.message }}</li></ul>
      <p class="text-sm text-slate-700">Générer le document n’est pas confirmer l’impression physique ni la remise : ces actes restent distincts et tracés (suivi dans « Impression et historique »).</p>
      <label class="block text-sm">Motif (obligatoire seulement pour une réimpression)<input v-model="reason" class="field" maxlength="200" data-testid="motif-impression"></label>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="site-btn site-btn-accent" :disabled="printIds.length === 0" data-testid="imprimer" @click="submitPrint(printIds, reason.trim() || null)"><AdminIcon name="print" :size="18" />Imprimer ({{ printIds.length }})</button>
        <button type="button" class="site-btn site-btn-outline" data-testid="nouvelle-preparation" @click="resetAll">Préparer d’autres badges</button>
      </div>
    </div>
  </section>
</template>
