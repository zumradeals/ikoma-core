<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import BadgesCartesShell from '../../components/admin/BadgesCartesShell.vue';

/**
 * Préparer un badge : parcours unique, unitaire ou groupé, pour un badge temporaire ou une carte permanente.
 * Personnes → vérification → génération → impression. Aucune règle ici : l'éligibilité, la génération et l'impression sont celles du serveur (BadgeIssuance, SupportPrinting).
 * Un badge est un support d'identification : il ne donne aucune habilitation. Aucun lot ni réservation n'est demandé.
 */
type Support = 'badge_temporaire' | 'carte_permanente';
type Person = { id: string; name: string; organisation: string | null; category: string | null; state: 'a_generer' | 'existant' | 'non_eligible'; code: string | null; reason: string | null };
type Plan = { a_generer: string[]; existants: { id: string; code: string }[]; non_eligibles: { id: string; code: string; message: string }[]; doublons_ignores: number };
type Result = { complete: boolean; created: { badge_id: string; code: string; visitor_id: string | null; holder_id: string | null }[]; existing: { id?: string; badge_id?: string; code?: string; message?: string }[]; failed: { id: string; code: string; message: string }[]; printable: string[] };

const props = defineProps<{ events: { id: string; name: string; kind: string; starts_at: string; ends_at: string }[]; max: number }>();

const step = ref<1 | 2 | 3 | 4>(1);
const support = ref<Support>('badge_temporaire');
const event = ref(props.events[0]?.id ?? '');
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

function newOperation(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : '00000000-0000-4000-8000-' + String(Date.now()).padStart(12, '0');
}
function xsrf(): string { return decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? ''); }
async function call<T>(path: string, body?: unknown): Promise<T | null> {
  errors.value = []; busy.value = true;
  try {
    const response = await fetch(path, body === undefined ? { credentials: 'same-origin', headers: { Accept: 'application/json' } }
      : { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) { errors.value = Object.values((data.errors ?? {}) as Record<string, string[]>).flat().concat(data.message && !data.errors ? [data.message] : []); return null; }
    return data as T;
  } catch { errors.value = ['Le service est indisponible. Réessayez.']; return null; } finally { busy.value = false; }
}

const eligible = computed(() => people.value.filter(p => p.state === 'a_generer'));
const names = computed(() => Object.fromEntries(people.value.map(p => [p.id, p.name])));
const supportLabel = computed(() => (support.value === 'badge_temporaire' ? 'badge temporaire' : 'carte permanente'));

function resetAll() { step.value = 1; selected.value = []; plan.value = null; result.value = null; errors.value = []; operation.value = newOperation(); reason.value = ''; }
function changeSupport(value: Support) { support.value = value; people.value = []; selected.value = []; searched.value = false; plan.value = null; }
async function search() {
  const params = new URLSearchParams({ support: support.value, q: query.value.trim() });
  if (support.value === 'badge_temporaire') params.set('event', event.value);
  const data = await call<{ people: Person[] }>('/administration/badges/preparer/personnes?' + params.toString());
  if (data) { people.value = data.people; searched.value = true; selected.value = selected.value.filter(id => data.people.some(p => p.id === id)); }
}
function toggle(id: string) { selected.value = selected.value.includes(id) ? selected.value.filter(x => x !== id) : selected.value.length < props.max ? [...selected.value, id] : selected.value; }
function selectEligible() { selected.value = eligible.value.slice(0, props.max).map(p => p.id); }
function clearSelection() { selected.value = []; }
async function verify() {
  const data = await call<{ plan: Plan }>('/administration/badges/preparer/recapitulatif', { support: support.value, ids: selected.value });
  if (data) { plan.value = data.plan; step.value = 2; }
}
async function generate() {
  if (!plan.value || plan.value.a_generer.length === 0) return;
  const data = await call<Result>('/administration/badges/preparer/generer', { support: support.value, ids: plan.value.a_generer, operation: operation.value, confirm: true });
  if (data) { result.value = data; step.value = 4; }
}
function printForm(ids: string[], motif: string | null): void {
  const form = document.createElement('form');
  form.method = 'POST'; form.action = '/administration/badges/impression'; form.target = '_blank';
  const add = (name: string, value: string) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input); };
  add('_token', xsrf());
  ids.forEach(id => add('badges[]', id));
  if (motif) add('reason', motif);
  document.body.appendChild(form); form.submit(); form.remove();
}
const printIds = computed(() => result.value?.printable ?? []);
const stateLabel: Record<Person['state'], string> = { a_generer: 'À générer', existant: 'Support déjà en cours', non_eligible: 'Non éligible' };
const stateClass: Record<Person['state'], string> = { a_generer: 'bg-green-100 text-green-900', existant: 'bg-sky-100 text-sky-900', non_eligible: 'bg-slate-200 text-slate-700' };
const steps = ['Personnes', 'Vérification', 'Génération', 'Impression'];
</script>

<template>
  <AdminLayout active="badges-cartes">
    <BadgesCartesShell active="preparer" />
    <div class="space-y-5" data-testid="page-preparer-badge">
      <header>
        <h1 class="text-display font-bold">Préparer un badge</h1>
        <p class="admin-muted mt-2">Choisissez une ou plusieurs personnes, vérifiez, générez puis imprimez. Un badge identifie une personne : il ne donne <strong>aucune habilitation</strong> et ne demande ni lot ni réservation.</p>
      </header>

      <ol class="grid grid-cols-4 gap-2 text-center text-sm" aria-label="Étapes" data-testid="etapes">
        <li v-for="(label, i) in steps" :key="label" class="rounded-lg border p-2 font-semibold" :class="step === i + 1 ? 'border-blue-600 bg-blue-50 text-blue-900' : step > i + 1 ? 'border-green-600 bg-green-50' : 'border-slate-300 bg-white'" :aria-current="step === i + 1 ? 'step' : undefined">
          <span class="block text-lg">{{ step > i + 1 ? '✓' : i + 1 }}</span><span class="hidden sm:block">{{ label }}</span>
        </li>
      </ol>

      <p v-for="e in errors" :key="e" role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-preparation">{{ e }}</p>

      <!-- 1. Personnes -->
      <section v-if="step === 1" class="site-card p-4 space-y-4" data-testid="etape-personnes">
        <h2 class="text-heading font-bold">1. Choisir les personnes</h2>
        <fieldset class="grid gap-3 sm:grid-cols-2">
          <legend class="sr-only">Type de support</legend>
          <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border-2 p-3" :class="support === 'badge_temporaire' ? 'border-blue-600 bg-blue-50' : 'border-slate-300'">
            <input type="radio" name="support" value="badge_temporaire" :checked="support === 'badge_temporaire'" data-testid="support-temporaire" @change="changeSupport('badge_temporaire')">
            <span><strong class="block">Badge temporaire</strong><small>Visite, événement, stage : participants d’une activité</small></span>
          </label>
          <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border-2 p-3" :class="support === 'carte_permanente' ? 'border-blue-600 bg-blue-50' : 'border-slate-300'">
            <input type="radio" name="support" value="carte_permanente" :checked="support === 'carte_permanente'" data-testid="support-carte" @change="changeSupport('carte_permanente')">
            <span><strong class="block">Carte permanente</strong><small>Personnel permanent : titulaires actifs</small></span>
          </label>
        </fieldset>
        <form class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" role="search" aria-label="Rechercher des personnes" @submit.prevent="search">
          <label v-if="support === 'badge_temporaire'" class="block">Activité
            <select v-model="event" class="field" data-testid="choix-activite"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select>
          </label>
          <label class="block" :class="support === 'carte_permanente' ? 'sm:col-span-2' : ''">Rechercher (nom{{ support === 'carte_permanente' ? ', identifiant' : '' }})
            <input v-model="query" class="field" maxlength="160" placeholder="Laisser vide pour afficher la liste" data-testid="recherche-personnes">
          </label>
          <button type="submit" class="site-btn site-btn-blue" :disabled="busy || (support === 'badge_temporaire' && !event)" data-testid="lancer-recherche">Afficher</button>
        </form>
        <p v-if="support === 'badge_temporaire' && !events.length" class="text-sm text-amber-900" role="status">Aucune activité ouverte : créez ou rouvrez une activité dans « Activités ».</p>
        <p class="text-sm text-slate-600">Une personne n’est jamais choisie sur son seul nom : la sélection porte sur l’identité enregistrée. Une personne absente se crée d’abord dans « Personnes ».</p>

        <div v-if="searched" class="space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="font-semibold" data-testid="compte-selection">{{ selected.length }} sélectionnée{{ selected.length > 1 ? 's' : '' }} · {{ eligible.length }} éligible{{ eligible.length > 1 ? 's' : '' }} sur {{ people.length }}</p>
            <span class="flex gap-2"><button type="button" class="site-btn site-btn-outline !min-h-11" data-testid="tout-eligible" @click="selectEligible">Sélectionner les éligibles</button><button type="button" class="site-btn site-btn-outline !min-h-11" @click="clearSelection">Aucune</button></span>
          </div>
          <p v-if="!people.length" data-testid="aucune-personne">Aucune personne ne correspond.</p>
          <ul v-else class="divide-y rounded-xl border" data-testid="liste-personnes">
            <li v-for="p in people" :key="p.id" class="flex flex-wrap items-center gap-3 p-3" :data-testid="'personne-' + p.id">
              <input :id="'sel-' + p.id" type="checkbox" class="h-6 w-6" :checked="selected.includes(p.id)" :disabled="p.state === 'non_eligible'" :aria-label="'Sélectionner ' + p.name" :data-testid="'choisir-' + p.id" @change="toggle(p.id)">
              <label :for="'sel-' + p.id" class="min-w-0 grow"><strong class="block">{{ p.name }}</strong><small class="text-slate-600">{{ p.organisation ?? '—' }}<template v-if="p.category"> · {{ p.category }}</template></small></label>
              <span class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="stateClass[p.state]" :data-testid="'etat-' + p.id">{{ stateLabel[p.state] }}<template v-if="p.code"> · {{ p.code }}</template></span>
              <small v-if="p.reason" class="w-full text-slate-700">{{ p.reason }}</small>
            </li>
          </ul>
          <button type="button" class="site-btn site-btn-accent" :disabled="busy || selected.length === 0" data-testid="verifier" @click="verify"><AdminIcon name="arrow" :size="18" />Vérifier la sélection ({{ selected.length }})</button>
        </div>
      </section>

      <!-- 2. Vérification -->
      <section v-if="step === 2 && plan" class="site-card p-4 space-y-4" data-testid="etape-verification">
        <h2 class="text-heading font-bold">2. Vérifier avant de générer</h2>
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div class="rounded-xl border bg-green-50 p-3"><dd class="text-3xl font-extrabold" data-testid="nb-a-generer">{{ plan.a_generer.length }}</dd><dt>Nouveaux supports à générer</dt></div>
          <div class="rounded-xl border bg-sky-50 p-3"><dd class="text-3xl font-extrabold" data-testid="nb-existants">{{ plan.existants.length }}</dd><dt>Déjà pourvus</dt></div>
          <div class="rounded-xl border bg-slate-100 p-3"><dd class="text-3xl font-extrabold" data-testid="nb-non-eligibles">{{ plan.non_eligibles.length }}</dd><dt>Non éligibles</dt></div>
          <div class="rounded-xl border bg-white p-3"><dd class="text-3xl font-extrabold">{{ plan.doublons_ignores }}</dd><dt>Doublons ignorés</dt></div>
        </dl>
        <p class="text-sm text-slate-700">Modèle : celui de la catégorie institutionnelle de chaque personne (référentiel unique des modèles) ; la photo est facultative et son refus d’impression est respecté. Support : {{ supportLabel }}.</p>
        <ul v-if="plan.existants.length" class="space-y-1 text-sm" data-testid="liste-existants"><li v-for="x in plan.existants" :key="x.id">{{ names[x.id] ?? x.id }} : support {{ x.code }} déjà en cours — conservé (réimpression ou remplacement explicite possibles).</li></ul>
        <ul v-if="plan.non_eligibles.length" class="space-y-1 text-sm text-amber-900" data-testid="liste-non-eligibles"><li v-for="x in plan.non_eligibles" :key="x.id">{{ names[x.id] ?? x.id }} : {{ x.message }}</li></ul>
        <div class="flex flex-wrap gap-3">
          <button type="button" class="site-btn site-btn-outline" data-testid="retour-personnes" @click="step = 1">Modifier la sélection</button>
          <button type="button" class="site-btn site-btn-accent" :disabled="plan.a_generer.length === 0" data-testid="continuer" @click="step = 3">Continuer</button>
        </div>
      </section>

      <!-- 3. Génération -->
      <section v-if="step === 3 && plan" class="site-card p-4 space-y-4" data-testid="etape-generation">
        <h2 class="text-heading font-bold">3. Générer les supports</h2>
        <p>Vous allez générer <strong data-testid="nb-confirmation">{{ plan.a_generer.length }}</strong> {{ supportLabel }}{{ plan.a_generer.length > 1 ? 's' : '' }}, chacun avec un numéro unique et un QR sécurisé distinct. Rien ne sera activé, remis ni habilité.</p>
        <p class="text-sm text-slate-600">Si la demande est répétée ou interrompue, aucun doublon n’est créé : les supports déjà générés sont conservés.</p>
        <div class="flex flex-wrap gap-3">
          <button type="button" class="site-btn site-btn-outline" @click="step = 2">Retour</button>
          <button type="button" class="site-btn site-btn-accent" :disabled="busy" data-testid="generer" @click="generate">{{ busy ? 'Génération…' : 'Générer les badges' }}</button>
        </div>
      </section>

      <!-- 4. Impression -->
      <section v-if="step === 4 && result" class="site-card p-4 space-y-4" data-testid="etape-impression">
        <h2 class="text-heading font-bold">4. Prévisualiser et imprimer</h2>
        <p v-if="result.complete" class="rounded-lg border border-green-300 bg-green-50 p-3 font-semibold text-green-900" role="status" data-testid="resultat-complet">{{ result.created.length }} support{{ result.created.length > 1 ? 's' : '' }} généré{{ result.created.length > 1 ? 's' : '' }}.</p>
        <p v-else class="rounded-lg border border-amber-300 bg-amber-50 p-3 font-semibold text-amber-900" role="alert" data-testid="resultat-partiel">Génération partielle : {{ result.created.length }} réussite(s), {{ result.failed.length }} échec(s). Aucun succès total n’est annoncé.</p>
        <ul v-if="result.created.length" class="text-sm" data-testid="liste-generes"><li v-for="c in result.created" :key="c.badge_id">{{ c.code }}</li></ul>
        <ul v-if="result.failed.length" class="space-y-1 text-sm text-red-800" data-testid="liste-echecs"><li v-for="f in result.failed" :key="f.id">{{ names[f.id] ?? f.id }} : {{ f.message }}</li></ul>
        <p class="text-sm text-slate-700">Générer le document n’est pas confirmer l’impression physique ni la remise : ces actes restent distincts et tracés.</p>
        <label class="block text-sm">Motif (obligatoire seulement pour une réimpression)<input v-model="reason" class="field" maxlength="200" data-testid="motif-impression"></label>
        <div class="flex flex-wrap gap-3">
          <button type="button" class="site-btn site-btn-accent" :disabled="printIds.length === 0" data-testid="imprimer" @click="printForm(printIds, reason.trim() || null)"><AdminIcon name="qr" :size="18" />Prévisualiser et imprimer ({{ printIds.length }})</button>
          <button type="button" class="site-btn site-btn-outline" data-testid="nouvelle-preparation" @click="resetAll">Préparer d’autres badges</button>
          <Link href="/administration/badges" class="site-btn site-btn-outline">Retour au registre</Link>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
