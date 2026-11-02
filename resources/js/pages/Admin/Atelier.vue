<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import '../../../../public/badges/cards.css';

type Visual = { design: string; title: string; primary: string; accent: string; orientation: string };
type Batch = { id: string; event_id: string; name: string; category: string | null; first_number: number | null; last_number: number | null };
type Run = {
  id: string; event: string | null; batch: string | null; format: string; side: string; design: string | null; mode: string | null; nature: 'officiel' | 'essai';
  count: number; first: number | null; last: number | null; reason: string | null; created_at: string; by: string | null;
  state: 'generated' | 'requested' | 'operator_confirmed'; requests: number; printed: number | null; spoiled: number | null; note: string | null;
};
type Initial = { event: string | null; batch: string | null; format: string | null; from: number | string | null; to: number | string | null; cols: number | string | null; rows: number | string | null };
const props = defineProps<{
  events: { id: string; name: string }[]; batches: Batch[]; preferences: Record<string, Visual>; runs: Run[]; initial: Initial; canPrepare: boolean;
  person?: { id: string; event_id: string; category_id: string; full_name: string } | null;
}>();

const mode = ref<'visiteurs' | 'cartes'>(new URLSearchParams(window.location.search).get('mode') === 'cartes' ? 'cartes' : 'visiteurs');
type PermanentCard = { id: string; identifier: string; name: string; category: string | null; activated: boolean; printed: boolean };
const permanentCards = ref<PermanentCard[]>([]);
const csrf = ref('');
async function loadCards() {
  try {
    const response = await fetch('/administration/atelier/cartes', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (response.ok) { const body = await response.json(); permanentCards.value = body.cards; csrf.value = body.csrf; }
  } catch { /* la liste reste vide */ }
}
watch(mode, value => { if (value === 'cartes' && props.canPrepare) void loadCards(); }, { immediate: true });
const chosen = ref<string[]>([]);
const chosenPrinted = computed(() => permanentCards.value.some(c => c.printed && chosen.value.includes(c.id)));
function toggleAll(on: boolean) { chosen.value = on ? permanentCards.value.map(c => c.id) : []; }
const defaults: Visual = { design: 'visiteur', title: 'IKOMA ACCESS', primary: '#0066B3', accent: '#F75C03', orientation: 'paysage' };
const initialEvent = props.person?.event_id ?? props.initial.event ?? props.events[0]?.id ?? '';
const initialFormat = props.initial.format ?? 'pvc';
const form = useForm({
  event: initialEvent, batch: props.initial.batch ?? '', ...defaults, ...props.preferences[initialEvent],
  from: props.initial.from ? String(props.initial.from) : '', to: props.initial.to ? String(props.initial.to) : '',
  format: initialFormat, cols: props.initial.cols ? Number(props.initial.cols) : (initialFormat === 'etiquettes' ? 3 : 2), rows: props.initial.rows ? Number(props.initial.rows) : (initialFormat === 'etiquettes' ? 5 : 5),
  side: 'recto', mode: 'reutilisable', personal_allowed: false, show_photo: false, show_org: false, show_period: false,
});
const reason = ref('');
const runs = ref<Run[]>(props.runs);
const batches = computed(() => props.batches.filter(b => b.event_id === form.event));
const batch = computed(() => batches.value.find(b => b.id === form.batch));
const busy = ref(false);
const errors = ref<string[]>([]);

// --- Appels serveur (JSON) ---------------------------------------------------------------------------------------------------
function xsrf() { return decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? ''); }
async function api<T = Record<string, unknown>>(path: string, body?: object, method = 'POST'): Promise<T | null> {
  try {
    const response = await fetch(path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: body === undefined ? undefined : JSON.stringify(body) });
    if (response.status === 422) { const data = await response.json() as { errors: Record<string, string[]> }; errors.value = Object.values(data.errors).flat(); return null; }
    if (!response.ok) { errors.value = [response.status === 401 || response.status === 419 ? 'Votre session a expiré. Rechargez la page et reconnectez-vous.' : 'Opération refusée. Vérifiez vos droits et réessayez.']; return null; }
    return await response.json() as T;
  } catch { errors.value = ['La connexion est interrompue. Réessayez une fois le réseau rétabli.']; return null; }
}

// --- 1. Modèle et aperçu d'exemple (aucun vrai QR) --------------------------------------------------------------------------------
const designs = [
  { id: 'visiteur', name: 'Visiteur', label: 'A', description: 'Blanc, bleu et orange. Clair et essentiel.', color: '#0066B3', primary: '#0066B3', accent: '#F75C03' },
  { id: 'vip', name: 'VIP', label: 'B', description: 'Crème et or. Une présentation soignée.', color: '#C9962B', primary: '#0B3C6B', accent: '#C9962B' },
  { id: 'prestataire', name: 'Prestataire / Stagiaire', label: 'C', description: 'Bleu foncé et turquoise. Distinct et lisible.', color: '#089EAA', primary: '#0B3C6B', accent: '#089EAA' },
];
function designColors(design: { primary: string; accent: string }) { form.primary = design.primary; form.accent = design.accent; }
const specimen = ref<{ recto: string; verso: string; orientation: string } | null>(null);
const specimenBusy = ref(false);
function specimenBody() {
  return { event: form.event, design: form.design, title: form.title, primary: form.primary, accent: form.accent, orientation: form.orientation, mode: form.mode, show_photo: form.show_photo, show_org: form.show_org, show_period: form.show_period, format: form.format === 'a4' ? 'a4' : 'pvc', cols: form.format === 'etiquettes' ? 2 : form.cols, rows: form.format === 'etiquettes' ? 5 : form.rows };
}
async function loadSpecimen() {
  if (!form.event || specimenBusy.value) return;
  specimenBusy.value = true;
  const data = await api<{ recto: string; verso: string; orientation: string }>('/administration/atelier/apercu-fictif', specimenBody());
  if (data) specimen.value = data;
  specimenBusy.value = false;
}
function saveModel() { form.put('/administration/atelier/modeles', { preserveScroll: true }); }

// --- 2. Sélection ------------------------------------------------------------------------------------------------------------
function range() { form.from = String(batch.value?.first_number ?? ''); form.to = String(batch.value?.last_number ?? ''); }
function chooseBatch() { if (!batches.value.some(b => b.id === form.batch)) form.batch = batches.value[0]?.id ?? ''; if (!form.from && !form.to) range(); }
const people = ref<{ id: string; full_name: string; organisation: string | null; host: string | null }[]>([]);
const selected = ref<string[]>([]);
const orderedSelected = computed(() => people.value.filter(person => selected.value.includes(person.id)));
const personQuery = ref(props.person?.full_name ?? '');
const preparationMessage = ref('');
const syncPostes = ref<string[]>([]);
const needsTabletSync = ref(false);
const preparationDone = ref(false);
const plannedCode = (index: number) => 'B-' + String(Number(form.from) + index).padStart(3, '0');
const rangeCount = computed(() => Number(form.to) >= Number(form.from) && Number(form.from) > 0 ? Number(form.to) - Number(form.from) + 1 : 0);
const exactlySelected = computed(() => rangeCount.value === selected.value.length && rangeCount.value > 0);
function resetPreparation() { people.value = []; selected.value = []; preparationDone.value = false; needsTabletSync.value = false; syncPostes.value = []; preparationMessage.value = ''; }
async function loadPeople() {
  errors.value = [];
  const data = await api<{ people: typeof people.value }>('/administration/atelier/personnes', { event: form.event, batch: form.batch, q: personQuery.value });
  if (data) { people.value = data.people; selected.value = []; preparationMessage.value = ''; preparationDone.value = false; }
}
async function preparePeople() {
  if (busy.value) return; busy.value = true; errors.value = [];
  const data = await api<{ prepared: unknown[]; sync_required: boolean; postes_a_synchroniser: string[] }>('/administration/atelier/preparer', { event: form.event, batch: form.batch, from: form.from, to: form.to, visitor_ids: orderedSelected.value.map(person => person.id), personal_allowed: form.personal_allowed });
  if (data) {
    preparationDone.value = true; needsTabletSync.value = !!data.sync_required; syncPostes.value = Array.isArray(data.postes_a_synchroniser) ? data.postes_a_synchroniser : [];
    preparationMessage.value = data.prepared.length + ' destination(s) nominative(s) préparée(s) sans activation ni changement de QR.';
  }
  busy.value = false; real.value = null;
}

// --- 3. Aperçu d'un badge réel (vrai QR : le lot est marqué exposé) ---------------------------------------------------------------
const real = ref<{ html: string; count: number; first: string; last: string; inactive: number } | null>(null);
const realSignature = ref('');
const signature = computed(() => JSON.stringify(form.data()));
const realReady = computed(() => !!real.value && realSignature.value === signature.value && !busy.value);
async function showReal() {
  if (!form.batch || busy.value || form.format === 'etiquettes') return;
  busy.value = true; errors.value = []; real.value = null;
  const requested = signature.value;
  const data = await api<NonNullable<typeof real.value>>('/administration/atelier/apercu', { ...form.data(), format: form.format === 'a4' ? 'a4' : 'pvc' });
  if (data && requested === signature.value) { real.value = data; realSignature.value = requested; }
  busy.value = false;
}

// --- 4. Format, alignement, document -----------------------------------------------------------------------------------------
const alignmentUrl = computed(() => '/administration/atelier/alignement?' + new URLSearchParams({ format: form.format === 'a4' ? 'a4' : 'pvc', orientation: form.orientation, cols: String(Math.min(form.cols, 3)), rows: String(Math.min(form.rows, 5)) }).toString());
const generated = ref<{ run: string; document_url: string; nature: 'officiel' | 'essai' } | null>(null);
async function refreshRuns() {
  const data = await api<{ runs: Run[] }>('/administration/atelier/tirages' + (form.event ? '?event=' + form.event : ''), undefined, 'GET');
  if (data) runs.value = data.runs;
}
async function generate(trial: boolean) {
  if (busy.value || (!trial && !form.batch)) return;
  busy.value = true; errors.value = []; generated.value = null;
  const body = trial ? { ...specimenBody(), essai: true } : { ...form.data(), reason: reason.value };
  const data = await api<{ run: string; document_url: string }>('/administration/atelier/tirages', body);
  if (data) { generated.value = { ...data, nature: trial ? 'essai' : 'officiel' }; await refreshRuns(); }
  busy.value = false;
}

// --- 5. Suivi : confirmation depuis l'historique ----------------------------------------------------------------------------------
const confirming = ref<string | null>(null);
const confirmForm = ref({ printed: 0, spoiled: 0, note: '' });
function startConfirm(run: Run) { confirming.value = run.id; confirmForm.value = { printed: run.count, spoiled: 0, note: '' }; errors.value = []; }
async function submitConfirm(run: Run) {
  const data = await api('/administration/atelier/tirages/' + run.id + '/confirmation', confirmForm.value);
  if (data) { confirming.value = null; await refreshRuns(); }
}
const stateLabel: Record<Run['state'], string> = { generated: 'Document généré (pas encore imprimé)', requested: 'Impression demandée (non confirmée)', operator_confirmed: 'Impression confirmée par l’opérateur' };
const formatLabel: Record<string, string> = { pvc: 'PVC CR80', a4: 'Planche A4', etiquettes: 'Étiquettes A4' };
function when(value: string) { return new Date(value).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }); }

watch(() => form.event, () => { Object.assign(form, props.preferences[form.event] ?? defaults); form.batch = ''; form.from = ''; form.to = ''; chooseBatch(); real.value = null; generated.value = null; void refreshRuns(); void loadSpecimen(); });
watch(() => form.batch, () => { range(); resetPreparation(); real.value = null; generated.value = null; });
watch(() => form.mode, () => { form.personal_allowed = false; form.show_photo = false; form.show_org = false; form.show_period = false; resetPreparation(); });
watch(() => form.orientation, () => { if (form.format !== 'etiquettes') { form.cols = form.orientation === 'portrait' ? 3 : 2; form.rows = form.orientation === 'portrait' ? 3 : 5; } });
watch(() => form.format, (format) => { form.cols = format === 'etiquettes' ? 3 : (form.orientation === 'portrait' ? 3 : 2); form.rows = format === 'etiquettes' ? 5 : (form.orientation === 'portrait' ? 3 : 5); generated.value = null; });
watch(() => [form.design, form.title, form.primary, form.accent, form.orientation, form.mode, form.show_photo, form.show_org, form.show_period], () => { void loadSpecimen(); });
watch(signature, () => { errors.value = []; generated.value = null; });
onMounted(() => { if (!form.batch) chooseBatch(); else if (!form.from && !form.to) range(); void loadSpecimen(); });
</script>

<template>
  <AdminLayout active="atelier" :event="events.find(event => event.id === form.event) ?? null">
    <div class="mb-6 space-y-2" data-testid="page-atelier">
      <p class="text-sm font-bold uppercase tracking-widest text-blue-700">Atelier des badges · IKOMA ACCESS</p>
      <h1 class="text-display font-bold">Atelier des badges</h1>
      <p class="max-w-3xl">Un seul endroit pour choisir un modèle, sélectionner des badges, les prévisualiser, les imprimer et suivre chaque tirage. Imprimer ne donne <strong>aucun droit d’accès</strong> et ne change ni l’état ni la réservation d’un badge.</p>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2" role="group" aria-label="Type de badge">
      <button type="button" class="rounded-xl border-2 p-4 text-left" :class="mode === 'visiteurs' ? 'border-blue-600 bg-blue-50' : 'bg-white'" :aria-pressed="mode === 'visiteurs'" data-testid="type-visiteurs" @click="mode = 'visiteurs'"><span class="block text-heading font-bold">Badges visiteurs</span><span>Badges temporaires d’un événement : lots, plages, étiquettes.</span></button>
      <button type="button" class="rounded-xl border-2 p-4 text-left" :class="mode === 'cartes' ? 'border-blue-600 bg-blue-50' : 'bg-white'" :aria-pressed="mode === 'cartes'" data-testid="type-permanents" @click="mode = 'cartes'"><span class="block text-heading font-bold">Cartes permanentes</span><span>Cartes des personnes du registre : choisissez les personnes et imprimez.</span></button>
    </div>

    <template v-if="mode === 'visiteurs'">
<p class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4" data-testid="rappel-trace">Chaque tirage suit trois étapes distinctes : <strong>document généré</strong>, <strong>impression demandée</strong>, <strong>impression confirmée par vous</strong>. Un document ou un PDF n’est jamais la preuve qu’un badge est sorti de l’imprimante.</p>

    <div v-if="errors.length" role="alert" class="mb-6 rounded-xl border-2 border-red-700 bg-red-50 p-4" data-testid="erreurs-atelier"><p v-for="error in errors" :key="error">{{ error }}</p></div>

    <div class="workshop-layout">
      <div class="space-y-5">
        <section class="workshop-panel" aria-labelledby="etape-modele" data-testid="etape-modele">
          <h2 id="etape-modele">1. Choisir le modèle</h2>
          <label>Événement<select v-model="form.event" class="field" data-testid="choix-evenement"><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option></select></label>
          <div class="space-y-3"><label v-for="design in designs" :key="design.id" class="workshop-design" :class="{ selected: form.design === design.id }"><input v-model="form.design" type="radio" name="design" :value="design.id" @change="designColors(design)"><span class="workshop-design-letter" :style="{ background: design.color }" aria-hidden="true">{{ design.label }}</span><span><strong>{{ design.name }}</strong><small>{{ design.description }}</small></span></label></div>
          <label>Titre du site<input v-model="form.title" class="field" maxlength="60" required></label>
          <div class="grid grid-cols-2 gap-3"><label>Couleur principale<input v-model="form.primary" class="field" type="color"></label><label>Couleur d’accent<input v-model="form.accent" class="field" type="color"></label></div>
          <label>Orientation<select v-model="form.orientation" class="field"><option value="paysage">Paysage 85,60 × 53,98 mm</option><option value="portrait">Portrait 53,98 × 85,60 mm</option></select></label>
          <p class="text-base">Modèle générique de l’installation. Le logo IKOMA ACCESS fourni est utilisé ; aucun modèle institutionnel n’est présenté comme validé.</p>
          <button type="button" class="btn-soft" :disabled="!form.event || form.processing" data-testid="enregistrer-modele" @click="saveModel">Enregistrer ce modèle pour l’événement</button>
        </section>

        <section class="workshop-panel" aria-labelledby="etape-selection" data-testid="etape-selection">
          <h2 id="etape-selection">2. Choisir les badges</h2>
          <label>Mode de badge<select v-model="form.mode" class="field"><option value="reutilisable">Standard réutilisable · sans identité</option><option value="nominatif" :disabled="!canPrepare">Nominatif · depuis une fiche</option></select></label>
          <label>Lot<select v-model="form.batch" class="field" data-testid="choix-lot"><option v-for="item in batches" :key="item.id" :value="item.id">{{ item.name }} · {{ item.category || 'Sans catégorie' }}</option></select></label>
          <p v-if="!batches.length" role="status">Aucun lot pour cet événement. <Link href="/administration/badges" class="underline">Générer un lot</Link>.</p>
          <p v-if="batch" class="text-base">Numéros du lot : {{ batch.first_number }} à {{ batch.last_number }}. La catégorie reste celle du badge.</p>
          <div class="grid grid-cols-2 gap-3"><label>Du badge n°<input v-model="form.from" class="field" type="number" min="1" data-testid="plage-debut"></label><label>Au badge n°<input v-model="form.to" class="field" type="number" min="1" data-testid="plage-fin"></label></div>
          <button type="button" class="btn-soft" :disabled="!batch" @click="range">Tout le lot</button>
          <p class="text-base">Pour un seul badge, saisissez le même numéro dans les deux champs. Maximum : 1 000 badges par tirage.</p>

          <div v-if="form.mode === 'nominatif'" class="space-y-4 rounded-xl border-2 border-blue-200 p-4" data-testid="bloc-nominatif">
            <h3 class="font-bold">Destinations nominatives</h3>
            <p class="text-base">Associez chaque badge disponible à une personne, sans l’activer. Le numéro, le QR et la réservation au poste ne changent pas.</p>
            <label class="flex min-h-14 items-center gap-3"><input v-model="form.personal_allowed" type="checkbox" data-testid="politique-nominatif">La politique du client autorise l’impression nominative</label>
            <label>Rechercher une personne<input v-model="personQuery" class="field" maxlength="160" placeholder="Nom, ou vide pour la catégorie du lot"></label>
            <button type="button" class="btn-soft" :disabled="!form.batch || busy" data-testid="charger-personnes" @click="loadPeople">Charger les personnes de cette catégorie (200 max.)</button>
            <template v-if="people.length">
              <div class="flex flex-wrap gap-3"><button type="button" class="btn-soft" @click="selected = people.map(p => p.id)">Tout sélectionner</button><button type="button" class="btn-soft" @click="selected = []">Tout désélectionner</button></div>
              <div class="max-h-80 overflow-auto rounded-lg border p-3"><label v-for="candidate in people" :key="candidate.id" class="flex min-h-14 items-center gap-3"><input v-model="selected" type="checkbox" :value="candidate.id"><span>{{ candidate.full_name }}<small v-if="candidate.organisation"> · {{ candidate.organisation }}</small></span></label></div>
              <p class="text-base">{{ selected.length }} personne(s) sélectionnée(s) pour {{ rangeCount }} badge(s).</p>
              <p v-if="!exactlySelected" class="text-base font-bold text-amber-900" role="status">Sélectionnez exactement {{ rangeCount }} personne(s), ou réduisez la plage à un seul numéro.</p>
              <ol class="max-h-64 overflow-auto rounded-lg bg-blue-50 p-3 text-base" aria-label="Destinations à confirmer"><li v-for="(destination, index) in orderedSelected" :key="destination.id"><strong>{{ plannedCode(index) }}</strong> → {{ destination.full_name }}</li></ol>
              <button type="button" class="btn-main" :disabled="!exactlySelected || !form.personal_allowed || busy" data-testid="preparer-destinations" @click="preparePeople">Préparer les destinations</button>
            </template>
            <p v-if="preparationMessage" role="status" class="font-semibold" data-testid="message-preparation">{{ preparationMessage }}</p>
            <div v-if="preparationDone && needsTabletSync" role="status" data-testid="synchronisation-nominative" class="rounded-lg border-2 border-amber-500 bg-amber-50 p-3">
              <strong>Synchronisation obligatoire avant remise physique</strong>
              <p>Poste(s) à synchroniser : {{ syncPostes.join(', ') }}.</p>
              <p>Sur chaque tablette : « Synchroniser maintenant ». N’utilisez pas une ancienne copie hors ligne.</p>
            </div>
            <label class="flex min-h-14 items-center gap-3"><input v-model="form.show_photo" type="checkbox">Afficher le portrait autorisé, si disponible</label>
            <label class="flex min-h-14 items-center gap-3"><input v-model="form.show_org" type="checkbox">Afficher la fonction ou l’organisation</label>
            <label class="flex min-h-14 items-center gap-3"><input v-model="form.show_period" type="checkbox">Afficher la période de validité</label>
          </div>
        </section>

        <section class="workshop-panel" aria-labelledby="etape-impression" data-testid="etape-impression">
          <h2 id="etape-impression">3. Format et impression</h2>
          <div class="grid grid-cols-2 gap-3">
            <label>Format<select v-model="form.format" class="field" data-testid="choix-format"><option value="pvc">PVC CR80</option><option value="a4">Planche A4 (badges)</option><option value="etiquettes">Étiquettes A4 (code et QR)</option></select></label>
            <label v-if="form.format !== 'etiquettes'">Face à imprimer<select v-model="form.side" class="field"><option value="recto">Recto · numéro et QR</option><option value="verso">Verso · consignes génériques</option></select></label>
          </div>
          <div v-if="form.format !== 'pvc'" class="grid grid-cols-2 gap-3">
            <label>Colonnes<select v-model.number="form.cols" class="field"><option v-for="n in (form.format === 'etiquettes' ? 4 : (form.orientation === 'portrait' ? 3 : 2))" :key="n" :value="n">{{ n }}</option></select></label>
            <label>Lignes<select v-model.number="form.rows" class="field"><option v-for="n in (form.format === 'etiquettes' ? 10 : (form.orientation === 'portrait' ? 3 : 5))" :key="n" :value="n">{{ n }}</option></select></label>
          </div>
          <p class="text-base">Échelle 100 % / taille réelle, sans en-têtes ni pieds de page, arrière-plans activés. <a :href="alignmentUrl" target="_blank" rel="noopener noreferrer" class="font-semibold underline" data-testid="lien-alignement-atelier">Imprimer d’abord une page d’essai d’alignement</a> (aucun QR, aucune donnée).</p>
          <label>Motif de la génération (obligatoire pour un nouveau tirage de badges nominatifs déjà tirés, facultatif sinon)<input v-model="reason" class="field" maxlength="200" data-testid="motif-generation"></label>
          <div class="flex flex-wrap gap-3">
            <button type="button" class="btn-main" :disabled="!form.batch || busy" data-testid="generer-document" @click="generate(false)">Générer le document d’impression</button>
            <button type="button" class="btn-soft" :disabled="!form.event || busy || form.format === 'etiquettes'" data-testid="generer-essai" @click="generate(true)">Imprimer un exemple (essai marqué)</button>
          </div>
          <div v-if="generated" class="rounded-xl border-2 border-green-700 bg-green-50 p-4" role="status" data-testid="document-pret">
            <p class="font-bold">{{ generated.nature === 'essai' ? 'Essai généré (exemple sans valeur).' : 'Document généré.' }} Rien n’est encore imprimé.</p>
            <a :href="generated.document_url" target="_blank" rel="noopener noreferrer" class="btn-main mt-3 inline-block" data-testid="ouvrir-document">Ouvrir la page d’impression</a>
            <p class="mt-2 text-base">Dans la page d’impression : « Imprimer » enregistre la <strong>demande</strong> ; vous confirmez ensuite le résultat.</p>
          </div>
        </section>
      </div>

      <aside class="workshop-preview space-y-5" aria-label="Aperçus">
        <div class="workshop-panel" data-testid="apercu-fictif">
          <h2>Aperçu du modèle (exemple fictif)</h2>
          <p class="text-base">Données et QR d’exemple : aucun vrai badge, aucun lot lu, aucune exposition.</p>
          <div v-if="specimen" class="workshop-stage workshop-big">
            <!-- HTML produit exclusivement par la vue Blade autorisée, valeurs échappées côté serveur. -->
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div data-testid="exemple-recto" v-html="specimen.recto"></div>
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div data-testid="exemple-verso" v-html="specimen.verso"></div>
          </div>
          <div v-else class="workshop-stage workshop-empty">{{ specimenBusy ? 'Chargement…' : 'Votre modèle apparaîtra ici' }}</div>
        </div>

        <div class="workshop-panel" data-testid="apercu-reel">
          <h2>Aperçu d’un badge réel</h2>
          <p class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-base">Cet aperçu contient un <strong>vrai QR</strong> : le lot est alors marqué comme exposé (il ne pourra plus être supprimé comme un lot jamais imprimé).</p>
          <button type="button" class="btn-soft" :disabled="!form.batch || busy || form.format === 'etiquettes'" data-testid="afficher-reel" @click="showReal">Afficher le premier badge réel de la plage</button>
          <p v-if="form.format === 'etiquettes'" class="text-base">Les étiquettes n’ont pas d’aperçu réel : générez le document pour les voir.</p>
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div v-if="real && realReady" class="workshop-stage workshop-big" data-testid="badge-reel" v-html="real.html"></div>
          <p v-if="real && realReady" class="font-bold">{{ real.count }} badge(s) · {{ real.first }} à {{ real.last }}<span v-if="real.inactive"> · dont {{ real.inactive }} déjà utilisé(s) ou annulé(s)</span></p>
        </div>
      </aside>
    </div>

    <section class="workshop-panel mt-8" aria-labelledby="etape-suivi" data-testid="suivi-tirages">
      <h2 id="etape-suivi">Suivi des tirages</h2>
      <p class="text-base">Historique en ajout seul : il n’est jamais modifié ni effacé. « Confirmée » est une déclaration de l’opérateur, pas une preuve technique.</p>
      <p v-if="!runs.length" role="status" data-testid="aucun-tirage">Aucun tirage pour le moment.</p>
      <ul class="space-y-3">
        <li v-for="run in runs" :key="run.id" class="rounded-xl border p-3" :data-testid="'tirage-' + run.id">
          <p class="font-bold">
{{ formatLabel[run.format] ?? run.format }} · {{ run.count }} {{ run.format === 'etiquettes' ? 'étiquette(s)' : 'badge(s)' }}<span v-if="run.first !== null"> · n° {{ run.first }} à {{ run.last }}</span>
            <span class="ml-2 rounded px-2 py-1 text-sm" :class="run.nature === 'essai' ? 'bg-amber-100 text-amber-900' : 'bg-blue-100 text-blue-900'">{{ run.nature === 'essai' ? 'ESSAI' : 'Officiel' }}</span>
</p>
          <p class="text-base">{{ run.event ?? '' }}<span v-if="run.batch"> · {{ run.batch }}</span><span v-if="run.mode === 'nominatif'"> · nominatif</span> · {{ when(run.created_at) }}<span v-if="run.by"> · {{ run.by }}</span></p>
          <p class="font-semibold" data-testid="etat-tirage">{{ stateLabel[run.state] }}<span v-if="run.state === 'operator_confirmed'"> : {{ run.printed }} imprimé(s), {{ run.spoiled }} gâché(s)<span v-if="run.note"> · {{ run.note }}</span></span></p>
          <p v-if="run.reason" class="text-base">Motif : {{ run.reason }}</p>
          <div class="mt-2 flex flex-wrap gap-3">
            <a :href="'/administration/atelier/tirages/' + run.id + '/document'" target="_blank" rel="noopener noreferrer" class="btn-soft">Rouvrir le document</a>
            <button v-if="run.state === 'requested'" type="button" class="btn-soft" :data-testid="'confirmer-' + run.id" @click="startConfirm(run)">Confirmer l’impression</button>
          </div>
          <form v-if="confirming === run.id" class="mt-3 grid gap-3 sm:grid-cols-3" @submit.prevent="submitConfirm(run)">
            <label>Imprimés<input v-model.number="confirmForm.printed" type="number" min="0" :max="run.count" class="field"></label>
            <label>Gâchés<input v-model.number="confirmForm.spoiled" type="number" min="0" :max="run.count" class="field"></label>
            <label>Note facultative<input v-model="confirmForm.note" maxlength="200" class="field"></label>
            <div class="flex gap-3 sm:col-span-3"><button type="submit" class="btn-main" data-testid="valider-confirmation">Confirmer</button><button type="button" class="btn-soft" @click="confirming = null">Annuler</button></div>
          </form>
        </li>
      </ul>
    </section>
    </template>

    <section v-if="mode === 'cartes'" class="space-y-4 rounded-xl border bg-white p-5" data-testid="cartes-permanentes" aria-label="Cartes permanentes">
      <h2 class="text-heading font-bold">Cartes permanentes</h2>
      <p>Cochez les personnes dont vous voulez imprimer la carte. Une carte imprimée n’est utilisable qu’après le bouton « Remettre et activer » dans la fiche de la personne.</p>
      <p v-if="!canPrepare" class="font-semibold" role="status">Ce parcours demande le droit de gérer les listes de personnes.</p>
      <p v-else-if="permanentCards.length === 0" data-testid="aucune-carte">Aucune carte préparée. Préparez d’abord une carte depuis la fiche d’une personne (photo obligatoire).</p>
      <form v-else method="post" action="/administration/atelier/cartes" target="_blank" class="space-y-4" data-testid="formulaire-cartes">
        <input type="hidden" name="_token" :value="csrf">
        <label class="flex items-center gap-2 font-semibold"><input type="checkbox" class="size-6" data-testid="tout-cocher" :checked="chosen.length === permanentCards.length" @change="toggleAll(($event.target as HTMLInputElement).checked)"> Tout sélectionner</label>
        <ul class="space-y-1">
          <li v-for="c in permanentCards" :key="c.id">
            <label class="flex flex-wrap items-center gap-2"><input v-model="chosen" type="checkbox" name="holders[]" :value="c.id" class="size-6" :data-testid="'carte-' + c.identifier">
              <strong>{{ c.name }}</strong> <span class="font-mono text-sm">{{ c.identifier }}</span> <span class="text-sm">{{ c.category }}</span>
              <span class="rounded px-2 py-0.5 text-sm font-bold" :class="c.activated ? 'bg-green-100 text-green-900' : 'bg-amber-100 text-amber-900'">{{ c.activated ? 'Activée' : 'Pas encore remise' }}</span>
              <span v-if="c.printed" class="text-sm">· déjà imprimée</span></label>
          </li>
        </ul>
        <label v-if="chosenPrinted" class="block max-w-xl font-semibold">Motif de la réimpression<input name="reason" class="field mt-1" maxlength="200" required data-testid="motif-cartes"></label>
        <button type="submit" class="admin-primary" :disabled="chosen.length === 0" data-testid="imprimer-cartes">Imprimer {{ chosen.length }} carte(s)</button>
      </form>
    </section>
</AdminLayout>
</template>

<style scoped>
.workshop-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; }
.workshop-panel { display: grid; gap: 20px; padding: 24px; border: 1px solid #dbe5ef; border-radius: 18px; background: #fff; box-shadow: 0 3px 12px #0b3c6b06; }
.workshop-panel h2 { font-size: 22px; font-weight: 750; margin: 0; }
.workshop-panel label { display: block; font-weight: 600; }
.workshop-panel label.workshop-design { display: flex; align-items: center; gap: 12px; padding: 16px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; min-height: 76px; }
.workshop-design.selected { border-color: #0066B3 !important; background: #f0f7fc; }
.workshop-design-letter { display: grid; place-items: center; flex-shrink: 0; width: 36px; height: 42px; border-radius: 5px; color: #fff; }
.workshop-design small { display: block; font-size: 14px; font-weight: 400; margin-top: 5px; }
.workshop-preview { display: grid; align-content: start; gap: 20px; min-width: 0; }
.workshop-stage { display: flex; flex-wrap: wrap; gap: 28px; justify-content: center; align-items: center; padding: 24px 8px; min-height: 280px; border-radius: 12px; background: #eaf0f6; overflow-x: auto; }
.workshop-big { zoom: 1.25; }
.workshop-empty { color: #52677b; text-align: center; }
@media (min-width: 1200px) { .workshop-layout { grid-template-columns: minmax(360px, 1fr) minmax(430px, 1fr); align-items: start; } .workshop-preview { position: sticky; top: 24px; } }
@media (max-width: 600px) { .workshop-panel { padding: 16px; } .workshop-stage { justify-content: start; } }
</style>
