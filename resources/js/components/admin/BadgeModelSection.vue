<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import '../../../../public/badges/cards.css';

/**
 * Modèle de badge visiteur d'une activité : choisir, enregistrer, revenir au modèle par défaut ou reprendre celui d'une autre activité.
 * Mêmes routes et mêmes règles que l'ancien atelier (`/administration/atelier/modeles*`) ; l'aperçu est un exemple fictif sans vrai QR.
 */
type Visual = { design: string; title: string; primary: string; accent: string; orientation: string };
const props = defineProps<{ events: { id: string; name: string }[]; preferences: Record<string, Visual>; initialEvent?: string | null }>();

const defaults: Visual = { design: 'visiteur', title: 'IKOMA ACCESS', primary: '#0066B3', accent: '#F75C03', orientation: 'paysage' };
const startEvent = props.events.some(e => e.id === props.initialEvent) ? (props.initialEvent as string) : (props.events[0]?.id ?? '');
const form = useForm({ event: startEvent, ...defaults, ...props.preferences[startEvent] });
const designs = [
  { id: 'visiteur', name: 'Visiteur', label: 'A', description: 'Blanc, bleu et orange. Clair et essentiel.', color: '#0066B3', primary: '#0066B3', accent: '#F75C03' },
  { id: 'vip', name: 'VIP', label: 'B', description: 'Crème et or. Une présentation soignée.', color: '#C9962B', primary: '#0B3C6B', accent: '#C9962B' },
  { id: 'prestataire', name: 'Prestataire / Stagiaire', label: 'C', description: 'Bleu foncé et turquoise. Distinct et lisible.', color: '#089EAA', primary: '#0B3C6B', accent: '#089EAA' },
];
function designColors(design: { primary: string; accent: string }) { form.primary = design.primary; form.accent = design.accent; }

const busy = ref(false);
const errors = ref<string[]>([]);
const message = ref('');
function xsrf() { return decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? ''); }
async function api<T>(path: string, body: object): Promise<T | null> {
  try {
    const response = await fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) });
    if (response.status === 422) { const data = await response.json() as { errors: Record<string, string[]> }; errors.value = Object.values(data.errors).flat(); return null; }
    if (!response.ok) { errors.value = [response.status === 401 || response.status === 419 ? 'Votre session a expiré. Rechargez la page et reconnectez-vous.' : 'Opération refusée. Vérifiez vos droits et réessayez.']; return null; }
    return await response.json() as T;
  } catch { errors.value = ['La connexion est interrompue. Réessayez une fois le réseau rétabli.']; return null; }
}

// Aperçu d'exemple (aucun vrai QR, aucun lot lu).
const specimen = ref<{ recto: string; verso: string; orientation: string } | null>(null);
const specimenBusy = ref(false);
async function loadSpecimen() {
  if (!form.event || specimenBusy.value) return;
  specimenBusy.value = true;
  const data = await api<{ recto: string; verso: string; orientation: string }>('/administration/atelier/apercu-fictif', { event: form.event, design: form.design, title: form.title, primary: form.primary, accent: form.accent, orientation: form.orientation, mode: 'reutilisable' });
  if (data) specimen.value = data;
  specimenBusy.value = false;
}

// Les valeurs reprises ou rétablies priment sur celles de la page jusqu'au prochain enregistrement.
const adopted = reactive<Record<string, Visual>>({});
const sourceEvent = ref('');
const otherEvents = computed(() => props.events.filter(e => e.id !== form.event));
function save() { message.value = ''; errors.value = []; form.put('/administration/atelier/modeles', { preserveScroll: true, onSuccess: () => { delete adopted[form.event]; message.value = 'Le modèle est enregistré pour cet événement.'; } }); }
async function resetModel() {
  if (busy.value || !form.event) return;
  busy.value = true; errors.value = []; message.value = '';
  const data = await api<{ model: Visual }>('/administration/atelier/modeles/reinitialiser', { event: form.event });
  if (data) { adopted[form.event] = data.model; Object.assign(form, data.model); message.value = 'Modèle par défaut rétabli pour cet événement.'; }
  busy.value = false;
}
async function copyModel() {
  if (busy.value || !form.event || !sourceEvent.value) return;
  busy.value = true; errors.value = []; message.value = '';
  const name = props.events.find(e => e.id === sourceEvent.value)?.name ?? '';
  const data = await api<{ model: Visual }>('/administration/atelier/modeles/reprendre', { event: form.event, source: sourceEvent.value });
  if (data) { adopted[form.event] = data.model; Object.assign(form, data.model); message.value = `Modèle repris de « ${name} » pour cet événement.`; sourceEvent.value = ''; }
  busy.value = false;
}

watch(() => form.event, () => { Object.assign(form, adopted[form.event] ?? props.preferences[form.event] ?? defaults); message.value = ''; errors.value = []; sourceEvent.value = ''; void loadSpecimen(); });
watch(() => [form.design, form.title, form.primary, form.accent, form.orientation], () => { void loadSpecimen(); });
onMounted(() => { void loadSpecimen(); });
</script>

<template>
  <div class="space-y-5" data-testid="bloc-modele-badge">
    <p v-if="!events.length" class="rounded-xl border bg-white p-4" data-testid="aucun-evenement">Aucune activité n’est créée : créez d’abord une activité (rubrique « Activités et personnes »).</p>
    <div v-else class="workshop-layout">
      <section class="workshop-panel" aria-labelledby="modele-badge-titre">
        <h2 id="modele-badge-titre">Modèle des badges visiteurs</h2>
        <p class="text-base">Le modèle est propre à chaque activité : le modifier ne change ni les autres activités, ni les badges déjà imprimés.</p>
        <label>Activité<select v-model="form.event" class="field" data-testid="badge-choix-evenement"><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option></select></label>
        <div class="space-y-3"><label v-for="design in designs" :key="design.id" class="workshop-design" :class="{ selected: form.design === design.id }"><input v-model="form.design" type="radio" name="design" :value="design.id" @change="designColors(design)"><span class="workshop-design-letter" :style="{ background: design.color }" aria-hidden="true">{{ design.label }}</span><span><strong>{{ design.name }}</strong><small>{{ design.description }}</small></span></label></div>
        <label>Titre du site<input v-model="form.title" class="field" maxlength="60" required></label>
        <div class="grid grid-cols-2 gap-3"><label>Couleur principale<input v-model="form.primary" class="field" type="color"></label><label>Couleur d’accent<input v-model="form.accent" class="field" type="color"></label></div>
        <label>Orientation<select v-model="form.orientation" class="field"><option value="paysage">Paysage 85,60 × 53,98 mm</option><option value="portrait">Portrait 53,98 × 85,60 mm</option></select></label>
        <p class="text-base">Modèle générique de l’installation. Le logo IKOMA ACCESS fourni est utilisé ; aucun modèle institutionnel n’est présenté comme validé.</p>
        <div v-if="errors.length" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3" data-testid="badge-erreurs-modele"><p v-for="error in errors" :key="error">{{ error }}</p></div>
        <button type="button" class="btn-main" :disabled="!form.event || form.processing" data-testid="badge-enregistrer-modele" @click="save">Enregistrer ce modèle pour l’activité</button>
        <div class="space-y-3 rounded-xl border border-slate-300 p-3" data-testid="badge-gestion-modeles">
          <p class="font-semibold">Gérer le modèle de cette activité</p>
          <button type="button" class="btn-soft" :disabled="busy || !form.event" data-testid="badge-reinitialiser-modele" @click="resetModel">Revenir au modèle par défaut</button>
          <template v-if="otherEvents.length">
            <label class="block">Reprendre le modèle d’une autre activité<select v-model="sourceEvent" class="field" data-testid="badge-source-modele"><option value="" disabled>Choisir…</option><option v-for="e in otherEvents" :key="e.id" :value="e.id">{{ e.name }}</option></select></label>
            <button type="button" class="btn-soft" :disabled="busy || !form.event || !sourceEvent" data-testid="badge-reprendre-modele" @click="copyModel">Reprendre ce modèle</button>
          </template>
        </div>
        <p v-if="message" role="status" class="font-semibold" data-testid="badge-message-modele">{{ message }}</p>
      </section>

      <aside class="workshop-preview" aria-label="Aperçu">
        <div class="workshop-panel" data-testid="badge-apercu-modele">
          <h2>Aperçu (exemple fictif)</h2>
          <p class="text-base">Données et QR d’exemple : aucun vrai badge, aucun lot lu, aucune exposition.</p>
          <div v-if="specimen" class="workshop-stage workshop-big">
            <!-- HTML produit exclusivement par la vue Blade autorisée, valeurs échappées côté serveur. -->
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div data-testid="badge-exemple-recto" v-html="specimen.recto"></div>
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div data-testid="badge-exemple-verso" v-html="specimen.verso"></div>
          </div>
          <div v-else class="workshop-stage workshop-empty">{{ specimenBusy ? 'Chargement…' : 'Votre modèle apparaîtra ici' }}</div>
        </div>
      </aside>
    </div>
  </div>
</template>
