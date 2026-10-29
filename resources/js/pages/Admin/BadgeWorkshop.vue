<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import '../../../../public/badges/cards.css';

type Visual = { design: string; title: string; primary: string; accent: string; orientation: string };
type Batch = { id: string; event_id: string; name: string; category: string | null; first_number: number | null; last_number: number | null };
const props = defineProps<{ events: { id: string; name: string }[]; batches: Batch[]; preferences: Record<string, Visual>; person?: { id: string; event_id: string; category_id: string; full_name: string } | null }>();
const initialEvent = props.person?.event_id ?? props.events[0]?.id ?? '';
const defaults: Visual = { design: 'visiteur', title: 'IKOMA ACCESS', primary: '#0066B3', accent: '#F75C03', orientation: 'paysage' };
const form = useForm({ event: initialEvent, batch: '', ...defaults, ...props.preferences[initialEvent], from: '', to: '', format: 'pvc', cols: 2, rows: 5, side: 'recto', mode: 'reutilisable', personal_allowed: false, show_photo: false, show_org: false, show_period: false });
const batches = computed(() => props.batches.filter(b => b.event_id === form.event));
const batch = computed(() => batches.value.find(b => b.id === form.batch));
const result = ref<{ html: string; count: number; first: string; last: string; inactive: number } | null>(null);
const errors = ref<string[]>([]);
const people = ref<{ id: string; full_name: string; organisation: string | null; host: string | null }[]>([]);
const selected = ref<string[]>([]); const personQuery = ref(props.person?.full_name ?? ''); const preparationMessage = ref('');
async function personalApi(path: string, body: object) {
  errors.value = [];
  try {
    const response = await fetch('/administration/badges/modeles/' + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '') }, body: JSON.stringify(body) });
    const data = await response.json();
    if (!response.ok) { errors.value = data.errors ? Object.values(data.errors as Record<string, string[]>).flat() : ['Opération refusée. Vérifiez votre session et vos droits.']; return null; }
    return data;
  } catch { errors.value = ['Connexion interrompue. Réessayez une fois le réseau rétabli.']; return null; }
}
async function loadPeople() { const data = await personalApi('personnes', { event: form.event, batch: form.batch, q: personQuery.value }); if (data) { people.value = data.people; selected.value = []; preparationMessage.value = ''; } }
async function preparePeople() {
  if (busy.value) return; busy.value = true;
  const data = await personalApi('preparer', { event: form.event, batch: form.batch, from: form.from, to: form.to, visitor_ids: selected.value, personal_allowed: form.personal_allowed });
  if (data) preparationMessage.value = data.prepared.length + ' destination(s) réservée(s). Aucun badge activé. Actualisez l’aperçu.';
  busy.value = false; result.value = null;
}
const busy = ref(false);
const signature = computed(() => JSON.stringify(form.data()));
const previewSignature = ref('');
const ready = computed(() => !!result.value && previewSignature.value === signature.value && !busy.value);
const printUrl = computed(() => '/administration/badges/modeles/imprimer?' + new URLSearchParams(Object.entries(form.data()).filter(([, value]) => value !== '').map(([key, value]) => [key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value)])).toString());
const designs = [
  { id: 'visiteur', name: 'Visiteur', label: 'A', description: 'Blanc, bleu et orange. Clair et essentiel.', color: '#0066B3', primary: '#0066B3', accent: '#F75C03' },
  { id: 'vip', name: 'VIP', label: 'B', description: 'Crème et or. Une présentation soignée.', color: '#C9962B', primary: '#0B3C6B', accent: '#C9962B' },
  { id: 'prestataire', name: 'Prestataire / Stagiaire', label: 'C', description: 'Bleu foncé et turquoise. Distinct et lisible.', color: '#089EAA', primary: '#0B3C6B', accent: '#089EAA' },
];
function designColors(design: { primary: string; accent: string }) { form.primary = design.primary; form.accent = design.accent; }
function chooseBatch() {
  form.batch = batches.value[0]?.id ?? '';
  range();
}
function range() {
  form.from = String(batch.value?.first_number ?? '');
  form.to = String(batch.value?.last_number ?? '');
}
watch(() => form.event, () => {
  Object.assign(form, props.preferences[form.event] ?? defaults);
  chooseBatch();
});
watch(() => form.batch, () => { range(); people.value = []; selected.value = []; });
watch(() => form.mode, () => { form.personal_allowed = false; form.show_photo = false; form.show_org = false; form.show_period = false; people.value = []; selected.value = []; });
watch(() => form.orientation, () => { form.cols = form.orientation === 'portrait' ? 3 : 2; form.rows = form.orientation === 'portrait' ? 3 : 5; });
watch(signature, () => { errors.value = []; });
async function preview() {
  if (!form.batch || busy.value) return;
  busy.value = true; errors.value = []; result.value = null;
  const requested = signature.value;
  try {
    const response = await fetch('/administration/badges/modeles/apercu', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '') },
      body: requested,
    });
    if (response.status === 422) {
      const data = await response.json() as { errors: Record<string, string[]> };
      if (requested === signature.value) errors.value = Object.values(data.errors).flat();
    } else if (!response.ok) {
      errors.value = [response.status === 401 || response.status === 419 ? 'Votre session a expiré. Rechargez la page et reconnectez-vous.' : 'Impossible de préparer les badges. Vérifiez votre connexion et vos droits, puis réessayez.'];
    } else if (requested === signature.value) {
      result.value = await response.json(); previewSignature.value = requested;
    }
  } catch {
    errors.value = ['La connexion est interrompue. Reconnectez-vous au réseau, puis actualisez l’aperçu.'];
  } finally { busy.value = false; }
}
function save() {
  form.put('/administration/badges/modeles', { preserveScroll: true });
}
onMounted(() => { chooseBatch(); if (form.batch) void preview(); });
</script>

<template>
  <AdminLayout active="badges" :event="events.find(event => event.id === form.event) ?? null">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div><p class="text-sm font-bold uppercase tracking-widest text-blue-700">Atelier de badges · IKOMA ACCESS</p><h1 class="mt-2 text-display font-bold">Modèles et impression</h1><p class="mt-2 max-w-3xl">Choisissez votre lot, personnalisez le modèle, puis préparez l’impression à taille réelle.</p></div>
      <Link href="/administration/badges" class="btn-soft">Retour aux badges</Link>
    </div>
    <p class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4 text-base">Le mode standard reste anonyme. Les données personnelles apparaissent seulement en mode nominatif explicitement autorisé. Les zones ne sont jamais imprimées. Le modèle VIP ne donne aucun droit d’accès supplémentaire.</p>
    <div class="workshop-layout">
      <form class="space-y-5" aria-label="Configurer les badges" @submit.prevent="preview">
        <section class="workshop-panel">
<h2>1. Choisir les badges</h2>
<label>Mode de badge<select v-model="form.mode" class="field"><option value="reutilisable">Standard réutilisable · sans identité</option><option value="nominatif">Nominatif · depuis une fiche</option></select></label>
          <label>Événement<select v-model="form.event" class="field"><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option></select></label>
          <label>Lot<select v-model="form.batch" class="field"><option v-for="item in batches" :key="item.id" :value="item.id">{{ item.name }} · {{ item.category || 'Sans catégorie' }}</option></select></label>
          <p v-if="!batches.length" role="status">Aucun lot pour cet événement. <Link href="/administration/badges" class="underline">Générer un lot</Link>.</p>
          <p v-if="batch" class="text-base">Numéros du lot : {{ batch.first_number }} à {{ batch.last_number }}. La catégorie reste celle du badge.</p>
          <div class="grid grid-cols-2 gap-3"><label>Du badge n°<input v-model="form.from" class="field" type="number" min="1" placeholder="Premier"></label><label>Au badge n°<input v-model="form.to" class="field" type="number" min="1" placeholder="Dernier"></label></div>
          <button type="button" class="btn-soft" :disabled="!batch" @click="range">Tout le lot</button>
          <p class="text-base">Pour un seul badge, saisissez le même numéro dans les deux champs. Maximum : 1 000 badges par préparation.</p>
        </section>
        <section class="workshop-panel">
<div v-if="form.mode === 'nominatif'" class="mb-5 space-y-4 rounded-xl border-2 border-blue-200 p-4">
<h2>Destinations nominatives</h2><p class="text-base">La préparation réserve chaque carte définitivement à une personne, sans contrôle d’identité ni activation. Utilisez des badges disponibles non réservés à un poste ou groupe ; réservez-les ensuite aux tablettes et synchronisez-les.</p>
<label class="flex min-h-14 items-center gap-3"><input v-model="form.personal_allowed" type="checkbox">La politique du client autorise l’impression nominative</label>
<label>Rechercher une personne<input v-model="personQuery" class="field" maxlength="160" placeholder="Nom, ou vide pour la catégorie du lot"></label>
<button type="button" class="btn-soft" :disabled="!form.batch || busy" @click="loadPeople">Charger les personnes de cette catégorie (200 max.)</button>
<template v-if="people.length">
<div class="flex flex-wrap gap-3"><button type="button" class="btn-soft" @click="selected = people.map(p => p.id)">Tout sélectionner</button><button type="button" class="btn-soft" @click="selected = []">Tout désélectionner</button></div>
<div class="max-h-80 overflow-auto rounded-lg border p-3"><label v-for="candidate in people" :key="candidate.id" class="flex min-h-14 items-center gap-3"><input v-model="selected" type="checkbox" :value="candidate.id"><span>{{ candidate.full_name }} · {{ candidate.organisation }} · {{ candidate.host }}</span></label></div>
<p class="text-base">{{ selected.length }} personne(s). Les destinations suivent l’ordre des cases sélectionnées et les numéros croissants. La plage doit contenir exactement ce nombre de badges.</p>
<button type="button" class="btn-soft" :disabled="!form.personal_allowed || !selected.length || busy" @click="preparePeople">Réserver {{ selected.length }} destination(s) nominative(s)</button>
</template>
<p v-if="preparationMessage" role="status">{{ preparationMessage }}</p>
<label class="flex min-h-14 items-center gap-3"><input v-model="form.show_photo" type="checkbox">Afficher le portrait de profil autorisé, si disponible</label><label class="flex min-h-14 items-center gap-3"><input v-model="form.show_org" type="checkbox">Afficher la fonction ou l’organisation</label><label class="flex min-h-14 items-center gap-3"><input v-model="form.show_period" type="checkbox">Afficher la période de validité</label>
<p class="text-base">Nom récupéré automatiquement. Sans portrait autorisé, le modèle sans photo est utilisé. Les photos du contrôle et des pièces ne sont jamais récupérées.</p>
</div>
<h2>2. Choisir le modèle</h2>
          <div class="space-y-3"><label v-for="design in designs" :key="design.id" class="workshop-design" :class="{ selected: form.design === design.id }"><input v-model="form.design" type="radio" name="design" :value="design.id" @change="designColors(design)"><span class="workshop-design-letter" :style="{ background: design.color }">{{ design.label }}</span><span><strong>{{ design.name }}</strong><small>{{ design.description }}</small></span></label></div>
          <label>Titre du site<input v-model="form.title" class="field" maxlength="60" required></label>
          <p class="text-base">Utilisez un titre validé par le client. Le logo fourni IKOMA ACCESS est conservé ; aucun insigne ni sceau officiel n’est créé.</p>
          <div class="grid grid-cols-2 gap-3"><label>Couleur principale<input v-model="form.primary" class="field" type="color"></label><label>Couleur d’accent<input v-model="form.accent" class="field" type="color"></label></div>
          <p class="text-base">Les modèles proposent leurs propres couleurs, que vous pouvez adapter. Le texte et le QR gardent un contraste lisible.</p>
          <button type="button" class="btn-soft" :disabled="!form.event || form.processing" @click="save">Enregistrer pour cet événement</button>
          <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
        </section>
        <section class="workshop-panel">
<h2>3. Préparer le tirage</h2>
          <div class="grid grid-cols-2 gap-3"><label>Format<select v-model="form.format" class="field"><option value="pvc">PVC CR80</option><option value="a4">Planche A4</option></select></label><label>Orientation<select v-model="form.orientation" class="field"><option value="paysage">Paysage</option><option value="portrait">Portrait</option></select></label></div>
          <div v-if="form.format === 'a4'" class="grid grid-cols-2 gap-3"><label>Colonnes<select v-model.number="form.cols" class="field"><option v-for="n in (form.orientation === 'portrait' ? 3 : 2)" :key="n" :value="n">{{ n }}</option></select></label><label>Lignes<select v-model.number="form.rows" class="field"><option v-for="n in (form.orientation === 'portrait' ? 3 : 5)" :key="n" :value="n">{{ n }}</option></select></label></div>
          <label>Face à imprimer<select v-model="form.side" class="field"><option value="recto">Recto · numéro et QR</option><option value="verso">Verso · consignes génériques</option></select></label>
          <p class="text-base">CR80 : 85,60 × 53,98 mm. Aucun badge n’est réduit pour tenir sur une feuille. Les étiquettes A4 historiques restent accessibles dans Badges.</p>
          <button type="submit" class="btn-main" :disabled="!form.batch || busy">{{ busy ? 'Préparation de l’aperçu…' : 'Actualiser l’aperçu' }}</button>
          <div v-if="errors.length" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4"><p v-for="error in errors" :key="error">{{ error }}</p></div>
        </section>
      </form>
      <aside class="workshop-preview" aria-label="Aperçu avant impression">
        <div class="workshop-panel">
<div class="flex items-center justify-between gap-3"><h2>Aperçu du badge réel</h2><span class="text-sm font-bold">{{ form.side === 'recto' ? 'RECTO' : 'VERSO' }}</span></div>
          <p v-if="!ready" class="text-base" role="status">{{ busy ? 'Chargement…' : 'Actualisez l’aperçu pour vérifier vos choix avant d’imprimer.' }}</p>
          <!-- HTML produit exclusivement par la vue Blade autorisée, valeurs échappées côté serveur. -->
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div v-if="result && ready" class="workshop-stage" v-html="result.html"></div>
          <div v-else class="workshop-stage workshop-empty">Votre badge apparaîtra ici</div>
          <template v-if="result && ready"><p class="font-bold">{{ result.count }} badge(s) · {{ result.first }} à {{ result.last }}</p><p class="text-base">{{ form.format === 'pvc' ? result.count : Math.ceil(result.count / (form.cols * form.rows)) }} page(s) à imprimer. L’écran peut afficher une taille différente ; les dimensions d’impression sont fixes.</p><p v-if="result.inactive" role="status" class="rounded-lg bg-amber-50 p-3 text-base">{{ result.inactive }} badge(s) restitué(s), annulé(s) ou clos dans cette sélection. L’impression ne les réactive pas.</p></template>
          <a v-if="ready" :href="printUrl" target="_blank" rel="noopener noreferrer" class="btn-main" data-testid="preparer-impression">Ouvrir la page d’impression</a>
          <button v-else class="btn-main" disabled>Ouvrir la page d’impression</button>
          <p class="text-base">La page s’ouvre dans un nouvel onglet. L’impression commence uniquement après votre clic sur « Imprimer les badges ».</p>
        </div>
        <section class="workshop-panel"><h2>Un premier essai réussi</h2><ol class="list-decimal space-y-3 pl-6 text-base"><li>Imprimez un seul badge à 100 %, sans en-têtes ni pieds de page, avec les arrière-plans activés.</li><li>Mesurez les deux côtés, puis scannez le QR depuis le contrôle IKOMA ACCESS.</li><li>Vérifiez l’imprimante et le sens du papier avant de lancer tout le lot.</li></ol><p class="text-base">Une copie imprimée conserve le même identifiant. L’association, la restitution et l’annulation restent gérées par IKOMA ACCESS.</p></section>
      </aside>
    </div>
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
.workshop-stage { display: flex; justify-content: center; align-items: center; padding: 24px 8px; min-height: 280px; border-radius: 12px; background: #eaf0f6; overflow-x: auto; }
.workshop-empty { color: #52677b; text-align: center; }
@media (min-width: 1200px) { .workshop-layout { grid-template-columns: minmax(360px, 1fr) minmax(390px, 1fr); align-items: start; } .workshop-preview { position: sticky; top: 24px; } }
@media (max-width: 600px) { .workshop-panel { padding: 16px; } .workshop-stage { justify-content: start; } }
</style>
