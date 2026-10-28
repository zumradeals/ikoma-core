<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import StatusBar from './StatusBar.vue';
import { startScan, type ScanHandle } from '../../poste/media';
import type { SitePosteRepository } from '../../poste/repository';
import type { SyncState } from '../../poste/local/sync';
import { ApiError, type Checkpoint, type ControlResult, type ExitResult } from '../../poste/types';

const props = defineProps<{ agent: string; repository: SitePosteRepository; sync?: SyncState | null }>();
const emit = defineEmits<{ sync: []; lock: [] }>();
const STORAGE_KEY = 'ikoma.controle.point';
type Mode = 'controle' | 'sortie';

const points = ref<Checkpoint[]>([]);
const point = ref<Checkpoint | null>(null);
const mode = ref<Mode>('controle');
const control = ref<ControlResult | null>(null);
const exit = ref<ExitResult | null>(null);
const latency = ref<number | null>(null);
const error = ref<string | null>(null);
const busy = ref(false);
const online = ref(navigator.onLine);
const present = ref<number | null>(null);
const code = ref('');
const video = ref<HTMLVideoElement | null>(null);
const scanError = ref('');
let handle: ScanHandle | null = null;
let lastCamera = { text: '', at: 0 };
let dismissTimer: number | undefined; let presentTimer: number | undefined;

const showing = computed(() => control.value !== null || exit.value !== null);
const good = computed(() => (control.value ? control.value.valid : exit.value?.ok === true));
const known = computed(() => {
  const at = control.value?.known_at ?? exit.value?.known_at ?? null;
  return at ? new Date(at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : null;
});
const lastSync = computed(() => (props.sync?.lastSyncAt ? new Date(props.sync.lastSyncAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : 'jamais'));
const reasonLabels: Record<string, string> = { inconnu: 'Badge inconnu', annule: 'Badge annulé', restitue: 'Badge restitué', non_associe: 'Badge non associé', hors_periode: 'Hors période', autre_evenement: 'Autre événement', sortie_sans_entree: 'Sortie sans entrée', deja_sorti: 'Déjà sorti' };

function readStored(): { type: string; id: string } | null {
  try { const raw = window.localStorage.getItem(STORAGE_KEY); return raw ? JSON.parse(raw) : null; } catch { return null; }
}
function choose(p: Checkpoint) {
  point.value = p;
  try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ type: p.type, id: p.id })); } catch { /* le point reste valable pour cette session */ }
}
function changePoint() { stopCamera(); point.value = null; dismiss(); }

async function startCamera() {
  scanError.value = '';
  if (!video.value || handle) return;
  try { handle = await startScan(video.value, text => { void submit(text, true); }); }
  catch (e) { scanError.value = e instanceof Error && e.message.includes('HTTPS') ? e.message : 'La caméra n’est pas accessible : saisissez le code imprimé sur le badge.'; }
}
// La caméra démarre dès que l'élément vidéo existe (choix du point, ou point retrouvé au démarrage) et s'arrête quand il disparaît.
watch(video, element => { if (element) void startCamera(); else stopCamera(); });
function stopCamera() { handle?.stop(); handle = null; }

function dismiss() { lastCamera = { ...lastCamera, at: Date.now() }; window.clearTimeout(dismissTimer); control.value = null; exit.value = null; latency.value = null; }

async function refreshPresent() { present.value = await props.repository.presentCount(); }

/** Un badge à la fois : tant qu'un résultat est affiché ou qu'un contrôle est en cours, les lectures suivantes sont ignorées. */
async function submit(raw: string, fromCamera = false) {
  const value = raw.trim();
  if (!value || busy.value || showing.value || !point.value) return;
  // Le badge est encore devant la caméra quand l'agent referme le résultat : la même lecture n'est pas refaite avant 5 s.
  if (fromCamera && value === lastCamera.text && Date.now() - lastCamera.at < 5000) return;
  if (fromCamera) lastCamera.text = value;
  busy.value = true; error.value = null;
  const started = performance.now();
  const where = { type: point.value.type, id: point.value.id };
  try {
    const operationId = crypto.randomUUID();
    if (mode.value === 'controle') control.value = await props.repository.control(operationId, value, where);
    else { exit.value = await props.repository.exit(operationId, value, where); void refreshPresent(); }
    code.value = '';
    await nextTick();
    latency.value = Math.round(performance.now() - started);
    if (good.value) dismissTimer = window.setTimeout(dismiss, 6000);
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Une erreur est survenue. Réessayez ou appelez le superviseur.';
  } finally { busy.value = false; }
}

const setOnline = () => { online.value = navigator.onLine; if (online.value) void refreshPresent(); };
onMounted(async () => {
  window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline);
  points.value = await props.repository.checkpoints();
  const saved = readStored();
  const found = saved ? points.value.find(p => p.type === saved.type && p.id === saved.id) : undefined;
  if (found) choose(found);
  void refreshPresent();
  presentTimer = window.setInterval(() => { if (navigator.onLine) void refreshPresent(); }, 15000);
});
onBeforeUnmount(() => { stopCamera(); window.clearTimeout(dismissTimer); window.clearInterval(presentTimer); window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); });
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-ikoma-navy">
    <StatusBar :poste="point?.name ?? null" :agent="agent" :online="online" :pending="sync?.pending ?? 0" :sync="sync" @sync="emit('sync')" @lock="emit('lock')" />
    <main id="contenu" class="mx-auto max-w-3xl space-y-5 px-4 py-6">
      <section v-if="!point" aria-labelledby="point-titre" class="space-y-4" data-testid="choix-point">
        <h1 id="point-titre" class="text-display font-bold">Où effectuez-vous les contrôles ?</h1>
        <p>Choisissez votre point de contrôle : il sera retenu sur cet appareil.</p>
        <ul class="space-y-3">
          <li v-for="p in points" :key="p.type + p.id"><button type="button" class="btn-main !justify-between text-left" @click="choose(p)"><span>{{ p.name }}</span><span class="text-lg font-normal">{{ p.type === 'zone' ? 'Zone' : 'Poste' }}</span></button></li>
        </ul>
      </section>

      <template v-else>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h1 class="text-heading font-bold">Point de contrôle : <span data-testid="point">{{ point.name }}</span></h1>
          <p class="rounded-full bg-white px-4 py-2 text-xl font-bold" data-testid="presents"><span aria-hidden="true">👥</span> Présents : {{ present ?? '—' }}</p>
        </div>
        <p v-if="!online" role="status" class="rounded-xl border-2 border-amber-700 bg-amber-50 p-3 font-semibold text-amber-950" data-testid="situation-connue"><span aria-hidden="true">⚠</span> Hors ligne : les contrôles s’appuient sur la situation connue à {{ lastSync }}. Un badge annulé depuis peut apparaître valide.</p>
        <div class="grid grid-cols-2 gap-3" role="group" aria-label="Mode">
          <button type="button" class="min-h-16 rounded-xl border-2 px-4 py-3 text-xl font-bold" :class="mode === 'controle' ? 'border-ikoma-blue bg-ikoma-blue text-white' : 'border-ikoma-blue bg-white text-ikoma-navy'" :aria-pressed="mode === 'controle'" data-testid="mode-controle" @click="mode = 'controle'">Contrôle</button>
          <button type="button" class="min-h-16 rounded-xl border-2 px-4 py-3 text-xl font-bold" :class="mode === 'sortie' ? 'border-ikoma-blue bg-ikoma-blue text-white' : 'border-ikoma-blue bg-white text-ikoma-navy'" :aria-pressed="mode === 'sortie'" data-testid="mode-sortie" @click="mode = 'sortie'">Sortie</button>
        </div>
        <div v-if="error" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4 text-lg text-red-900" data-testid="erreur"><span aria-hidden="true">⚠</span> {{ error }}</div>
        <div class="overflow-hidden rounded-2xl bg-slate-900"><video ref="video" class="mx-auto max-h-[40vh] w-full object-contain" playsinline muted aria-label="Caméra de lecture du badge"></video></div>
        <p v-if="scanError" role="alert" class="font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ scanError }}</p>
        <form class="space-y-3" aria-label="Saisir le code du badge" @submit.prevent="submit(code)">
          <label class="block text-xl font-semibold">QR illisible ? Saisissez le code imprimé (exemple : B-014)<input v-model="code" class="field !min-h-16 !text-2xl uppercase" autocomplete="off" maxlength="100" data-testid="code-manuel"></label>
          <button type="submit" class="btn-main" :disabled="!code.trim() || busy" data-testid="valider-code">{{ mode === 'controle' ? 'Contrôler ce badge' : 'Enregistrer la sortie' }}</button>
        </form>
        <button type="button" class="btn-soft" data-testid="changer-point" @click="changePoint">Changer de point de contrôle</button>
      </template>
    </main>

    <div v-if="showing" class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-5 px-6 py-8 text-center text-white" :class="good ? 'bg-green-700' : 'bg-red-700'" role="alertdialog" aria-modal="true" :aria-label="good ? 'Résultat : valide' : 'Résultat : refusé'" :data-latency-ms="latency ?? undefined" data-testid="resultat" :data-verdict="good ? 'valide' : 'refuse'" @click="dismiss">
      <template v-if="control">
        <p class="text-[clamp(3rem,12vw,6rem)] font-extrabold leading-none" data-testid="verdict"><span aria-hidden="true">{{ control.valid ? '✓' : '✕' }}</span> {{ control.valid ? 'VALIDE' : 'NON VALIDE' }}</p>
        <template v-if="control.valid && control.person">
          <p class="text-4xl font-bold" data-testid="nom">{{ control.person.name }}</p>
          <div v-if="control.category" class="rounded-2xl bg-white px-4 py-2 text-ikoma-navy"><CategorySwatch :name="control.category.name" :color="control.category.color" :priority="control.category.priority_circuit" /></div>
          <p class="text-2xl">{{ control.organisation || 'Organisation non indiquée' }}</p>
          <p class="text-2xl" data-testid="zones">Zones autorisées : {{ control.zones.join(', ') || 'aucune' }}</p>
          <p v-if="control.zone_ok === false" class="rounded-2xl border-4 border-white bg-amber-300 px-5 py-3 text-3xl font-extrabold text-red-900" data-testid="zone-non-autorisee"><span aria-hidden="true">⚠</span> ZONE NON AUTORISÉE : {{ control.point }}</p>
        </template>
        <template v-else>
          <p class="text-4xl font-bold" data-testid="raison">{{ reasonLabels[control.reason ?? ''] ?? control.reason }}</p>
          <p class="text-2xl">{{ control.message }}</p>
        </template>
      </template>
      <template v-else-if="exit">
        <p class="text-[clamp(2.5rem,10vw,5rem)] font-extrabold leading-none" data-testid="verdict"><span aria-hidden="true">{{ exit.ok ? '✓' : '✕' }}</span> {{ exit.ok ? 'SORTIE ENREGISTRÉE' : 'SORTIE REFUSÉE' }}</p>
        <template v-if="exit.ok">
          <p class="text-4xl font-bold" data-testid="nom">{{ exit.person?.name }}</p>
          <p class="text-2xl">Badge {{ exit.badge_code }}<template v-if="exit.badge_status"> · {{ exit.badge_status === 'Disponible' ? 'de nouveau disponible' : 'clos (usage unique)' }}</template></p>
          <p v-if="exit.presents != null" class="text-2xl">Personnes présentes : {{ exit.presents }}</p>
          <p v-if="exit.warning" class="rounded-2xl bg-amber-300 px-5 py-3 text-2xl font-bold text-red-900"><span aria-hidden="true">⚠</span> {{ exit.warning }}</p>
        </template>
        <template v-else>
          <p class="text-4xl font-bold" data-testid="raison">{{ reasonLabels[exit.reason ?? ''] ?? exit.reason }}</p>
          <p class="text-2xl">{{ exit.message }}</p>
        </template>
      </template>
      <p v-if="known" class="rounded-xl bg-white/20 px-4 py-2 text-xl font-semibold" data-testid="connu-a">Hors ligne : situation connue à {{ known }}</p>
      <button type="button" class="btn-soft !bg-white !text-xl" data-testid="suivant" @click.stop="dismiss">Badge suivant</button>
    </div>
  </div>
</template>
