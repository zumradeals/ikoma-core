<script setup lang="ts">
import AgentLayout from '../../layouts/AgentLayout.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import StatusBar from './StatusBar.vue';
import IncidentsPanel from './IncidentsPanel.vue';
import { startScan, type ScanHandle } from '../../poste/media';
import type { SitePosteRepository } from '../../poste/repository';
import type { SyncState } from '../../poste/local/sync';
import { ApiError, type Checkpoint, type ControlResult, type ExitResult, type GiveBackResult, type PassageDirection } from '../../poste/types';

const props = defineProps<{ agent: string; repository: SitePosteRepository; sync?: SyncState | null; initialMode?: string | null }>();
const emit = defineEmits<{ sync: []; lock: [] }>();
const STORAGE_KEY = 'ikoma.controle.point';
type Mode = 'controle' | 'sortie' | 'restitution';

const points = ref<Checkpoint[]>([]);
const point = ref<Checkpoint | null>(null);
// Capacités du point tenu par la tablette (S02) : l'écran n'offre que les opérations permises ; le serveur les revérifie à chaque opération.
// Contrôle d'un badge = « scan » ; sortie et restitution = « sortie ». Sans capacités connues, aucune restriction n'est appliquée ici.
const caps = computed(() => props.repository.capabilities?.() ?? null);
const MODE_CAPABILITY: Record<Mode, string> = { controle: 'scan', sortie: 'sortie', restitution: 'sortie' };
const MODE_LABEL: Record<Mode, string> = { controle: 'Contrôle d’un badge', sortie: 'Sortie individuelle', restitution: 'Restitution d’un badge' };
const allowedModes = computed<Mode[]>(() => (['controle', 'sortie', 'restitution'] as Mode[]).filter(m => caps.value === null || caps.value.includes(MODE_CAPABILITY[m])));
const requestedMode = (props.initialMode ?? new URLSearchParams(window.location.search).get('mode')) as Mode | null;
const mode = ref<Mode>(requestedMode && requestedMode in MODE_CAPABILITY ? requestedMode : 'controle');
const showIncidents = ref(false);
// Sens du passage (S10) : à la soute, l'agent choisit Entrée ou Sortie AVANT de scanner ; le choix reste affiché tant qu'il ne le change pas.
const needsDirection = computed(() => props.repository.requiresDirection?.() === true);
const direction = ref<PassageDirection | null>(null);
const DIRECTION_LABEL: Record<PassageDirection, string> = { entree: 'Entrée', sortie: 'Sortie' };
const control = ref<ControlResult | null>(null);
const exit = ref<ExitResult | null>(null);
// Restitution (en ligne uniquement) : vérification serveur → confirmation de l'agent → restitution, contrôlée de nouveau par le serveur.
const giveBackCheck = ref<(GiveBackResult & { code: string }) | null>(null);
const giveBackDone = ref<GiveBackResult | null>(null);
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

watch(allowedModes, modes => { if (modes.length && !modes.includes(mode.value)) mode.value = modes[0]; }, { immediate: true });
const showing = computed(() => control.value !== null || exit.value !== null);
const good = computed(() => (control.value ? control.value.valid : exit.value?.ok === true));
const known = computed(() => {
  const at = control.value?.known_at ?? exit.value?.known_at ?? null;
  return at ? new Date(at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : null;
});
// Confiance hors ligne des titulaires permanents : l'ancienneté est recalculée toutes les 15 s ET à chaque décision (côté repository), jamais seulement au démarrage.
const tick = ref(Date.now());
let trustTimer: number | undefined;
const trust = computed(() => {
  if (online.value) return null;
  const minutes = props.repository.trustMinutes?.() ?? 0;
  const last = props.sync?.lastSyncAt ? Date.parse(props.sync.lastSyncAt) : NaN;
  if (minutes < 1) return { state: 'aucune', text: 'Aucune confiance hors ligne approuvée : les nouvelles entrées de titulaires permanents sont refusées. Les sorties restent possibles.' };
  const left = Number.isNaN(last) || last > tick.value ? 0 : Math.floor(minutes - (tick.value - last) / 60_000);
  return left > 0 ? { state: 'valide', text: `Confiance hors ligne : encore ${left} min pour les nouvelles entrées de titulaires permanents.` }
    : { state: 'expiree', text: 'Confiance hors ligne EXPIRÉE : les nouvelles entrées de titulaires permanents sont refusées jusqu’à la synchronisation. Les sorties restent possibles.' };
});
const lastSync = computed(() => (props.sync?.lastSyncAt ? new Date(props.sync.lastSyncAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : 'jamais'));
const reasonLabels: Record<string, string> = { sens_requis: 'Sens du passage à choisir', inconnu: 'Badge inconnu', zone_non_autorisee: 'Zone non autorisée', annule: 'Badge annulé', restitue: 'Badge restitué', non_associe: 'Badge non associé', hors_periode: 'Hors période', autre_evenement: 'Autre événement', sortie_sans_entree: 'Sortie sans entrée', deja_sorti: 'Déjà sorti', personne_sortie: 'Personne sortie : badge à restituer', personne_presente: 'Personne encore présente', carte_perdue: 'Carte perdue', carte_volee: 'Carte volée', carte_revoquee: 'Carte révoquée', titulaire_clos: 'Titulaire clos', engagement_termine: 'Engagement terminé', engagement_non_commence: 'Engagement non commencé', evenement_indisponible: 'Événement clos', habilitation_absente: 'Habilitation absente', habilitation_expiree: 'Habilitation expirée', habilitation_non_commencee: 'Habilitation pas encore valable', zone_sans_politique: 'Zone sans politique', hors_ligne_non_autorise: 'Hors ligne non autorisé', confiance_expiree: 'Confiance hors ligne expirée' };

function readStored(): { type: string; id: string } | null {
  try { const raw = window.localStorage.getItem(STORAGE_KEY); return raw ? JSON.parse(raw) : null; } catch { return null; }
}
function choose(p: Checkpoint) {
  point.value = p;
  try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ type: p.type, id: p.id })); } catch { /* le point reste valable pour cette session */ }
}
function changePoint() { stopCamera(); point.value = null; direction.value = null; dismiss(); }

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
async function confirmGiveBack() {
  const pending = giveBackCheck.value;
  if (!pending || busy.value) return;
  busy.value = true; error.value = null;
  try { giveBackDone.value = await props.repository.giveBack(crypto.randomUUID(), pending.code); giveBackCheck.value = null; }
  catch (e) { error.value = e instanceof ApiError ? e.message : 'La restitution a échoué. Réessayez ou appelez le superviseur.'; }
  finally { busy.value = false; }
}
function cancelGiveBack() { giveBackCheck.value = null; giveBackDone.value = null; error.value = null; }
function chooseMode(next: Mode) { mode.value = next; cancelGiveBack(); }

async function submit(raw: string, fromCamera = false) {
  const value = raw.trim();
  if (!value || busy.value || showing.value || !point.value || giveBackCheck.value) return;
  if (mode.value === 'controle' && needsDirection.value && !direction.value) { error.value = 'Choisissez Entrée ou Sortie avant de scanner un badge.'; return; }
  // Le badge est encore devant la caméra quand l'agent referme le résultat : la même lecture n'est pas refaite avant 5 s.
  if (fromCamera && value === lastCamera.text && Date.now() - lastCamera.at < 5000) return;
  if (fromCamera) lastCamera.text = value;
  busy.value = true; error.value = null;
  const started = performance.now();
  const where = { type: point.value.type, id: point.value.id };
  try {
    const operationId = crypto.randomUUID();
    if (mode.value === 'restitution') {
      giveBackDone.value = null;
      const check = await props.repository.giveBackCheck(value);
      if (check.ok) giveBackCheck.value = { ...check, code: value }; else giveBackDone.value = check;
      code.value = '';
      return;
    }
    if (mode.value === 'controle') control.value = await props.repository.control(operationId, value, where, needsDirection.value ? direction.value ?? undefined : undefined);
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
onMounted(() => { trustTimer = window.setInterval(() => { tick.value = Date.now(); }, 15_000); });
onBeforeUnmount(() => { window.clearInterval(trustTimer); stopCamera(); window.clearTimeout(dismissTimer); window.clearInterval(presentTimer); window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); });
</script>

<template>
  <AgentLayout active="controle" :agent="agent" :online="online" :capabilities="caps">
    <template #header-context><span v-if="point?.name ?? null">Point de contrôle : {{ point?.name ?? null }}</span></template>
    <template #status><StatusBar context-label="Point de contrôle" :poste="point?.name ?? null" :agent="agent" :online="online" :pending="sync?.pending ?? 0" :sync="sync" @sync="emit('sync')" @lock="emit('lock')" /></template>
    <div class="agent-workspace agent-controle-workspace">
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
        <p v-if="trust" role="status" class="rounded-xl border-2 p-3 font-semibold" :class="trust.state === 'valide' ? 'border-amber-700 bg-amber-50 text-amber-950' : 'border-red-700 bg-red-50 text-red-900'" :data-testid="'confiance-' + trust.state"><span aria-hidden="true">⏱</span> {{ trust.text }}</p>
        <p v-if="!allowedModes.length" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="aucune-operation"><span aria-hidden="true">⚠</span> Ce poste n’est habilité à aucune opération de contrôle : l’administrateur doit préciser son point de contrôle.</p>
        <p v-else-if="allowedModes.length === 1" class="rounded-xl bg-white px-4 py-3 text-lg" data-testid="operations-autorisees">Opération autorisée à ce poste : <strong>{{ MODE_LABEL[allowedModes[0]] }}</strong>. Les autres opérations (accueil, sortie, restitution) se font aux postes prévus.</p>
        <div v-if="allowedModes.length > 1" class="grid gap-3" :style="{ gridTemplateColumns: 'repeat(' + allowedModes.length + ', minmax(0, 1fr))' }" role="group" aria-label="Mode">
          <button v-if="allowedModes.includes('controle')" type="button" class="min-h-16 rounded-xl border-2 px-4 py-3 text-xl font-bold" :class="mode === 'controle' ? 'border-ikoma-blue bg-ikoma-blue text-white' : 'border-ikoma-blue bg-white text-ikoma-navy'" :aria-pressed="mode === 'controle'" data-testid="mode-controle" @click="chooseMode('controle')">Contrôle</button>
          <button v-if="allowedModes.includes('sortie')" type="button" class="min-h-16 rounded-xl border-2 px-4 py-3 text-xl font-bold" :class="mode === 'sortie' ? 'border-ikoma-blue bg-ikoma-blue text-white' : 'border-ikoma-blue bg-white text-ikoma-navy'" :aria-pressed="mode === 'sortie'" data-testid="mode-sortie" @click="chooseMode('sortie')">Sortie</button>
          <button v-if="allowedModes.includes('restitution')" type="button" class="min-h-16 rounded-xl border-2 px-4 py-3 text-xl font-bold" :class="mode === 'restitution' ? 'border-ikoma-blue bg-ikoma-blue text-white' : 'border-ikoma-blue bg-white text-ikoma-navy'" :aria-pressed="mode === 'restitution'" data-testid="mode-restitution" @click="chooseMode('restitution')">Restitution</button>
        </div>
        <p v-if="mode === 'restitution' && !online" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="restitution-hors-ligne"><span aria-hidden="true">⚠</span> La restitution d’un badge n’est possible qu’en ligne. Reconnectez le poste pour restituer.</p>
        <section v-if="mode === 'restitution' && giveBackCheck" class="space-y-3 rounded-2xl border-4 border-ikoma-blue bg-white p-4" role="alertdialog" aria-labelledby="titre-restitution" data-testid="confirmation-restitution">
          <h2 id="titre-restitution" class="text-2xl font-bold">Restituer ce badge ?</h2>
          <p class="text-xl">Badge <strong data-testid="restitution-badge">{{ giveBackCheck.badge_code }}</strong> · rendu par <strong data-testid="restitution-nom">{{ giveBackCheck.person?.name }}</strong></p>
          <p class="text-lg">Le badge sera récupéré et pourra être attribué à une autre personne. Cette action est enregistrée.</p>
          <div class="grid grid-cols-2 gap-3"><button type="button" class="btn-main" :disabled="busy" data-testid="confirmer-restitution" @click="confirmGiveBack">Confirmer la restitution</button><button type="button" class="btn-soft" :disabled="busy" data-testid="annuler-restitution" @click="cancelGiveBack">Annuler</button></div>
        </section>
        <section v-if="mode === 'restitution' && giveBackDone" class="space-y-2 rounded-2xl border-4 p-4" :class="giveBackDone.ok ? 'border-green-700 bg-green-50 text-green-950' : 'border-red-700 bg-red-50 text-red-900'" role="status" data-testid="resultat-restitution">
          <p class="text-2xl font-bold"><span aria-hidden="true">{{ giveBackDone.ok ? '✓' : '✕' }}</span> {{ giveBackDone.ok ? 'BADGE RESTITUÉ' : 'RESTITUTION REFUSÉE' }}</p>
          <p v-if="giveBackDone.ok" class="text-xl">Badge {{ giveBackDone.badge_code }} · {{ giveBackDone.person?.name }} · {{ giveBackDone.badge_status === 'Disponible' ? 'de nouveau disponible' : 'clos (usage unique)' }}</p>
          <template v-else><p class="text-xl font-bold" data-testid="raison-restitution">{{ reasonLabels[giveBackDone.reason ?? ''] ?? giveBackDone.reason }}</p><p class="text-lg">{{ giveBackDone.message }}</p></template>
          <button type="button" class="btn-soft" data-testid="restitution-suivante" @click="cancelGiveBack">Restitution suivante</button>
        </section>
        <div v-if="error" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4 text-lg text-red-900" data-testid="erreur"><span aria-hidden="true">⚠</span> {{ error }}</div>
        <fieldset v-if="needsDirection && mode === 'controle'" class="space-y-2 rounded-2xl border-2 border-ikoma-blue bg-white p-4" data-testid="sens-passage">
          <legend class="px-1 text-xl font-bold">Sens du passage</legend>
          <div class="grid grid-cols-2 gap-3" role="group" aria-label="Sens du passage">
            <button v-for="d in (['entree', 'sortie'] as PassageDirection[])" :key="d" type="button" class="min-h-16 rounded-xl border-2 px-4 py-3 text-2xl font-bold" :class="direction === d ? 'border-ikoma-blue bg-ikoma-blue text-white' : 'border-ikoma-blue bg-white text-ikoma-navy'" :aria-pressed="direction === d" :data-testid="'sens-' + d" @click="direction = d">{{ DIRECTION_LABEL[d] }}</button>
          </div>
          <p v-if="!direction" role="status" class="font-semibold text-amber-950" data-testid="sens-requis"><span aria-hidden="true">⚠</span> Choisissez Entrée ou Sortie avant de scanner : aucun badge n’est lu tant que le sens n’est pas choisi.</p>
          <p v-else class="text-lg" data-testid="sens-choisi">Sens choisi : <strong>{{ DIRECTION_LABEL[direction] }}</strong>. Les habilitations s’appliquent de la même façon dans les deux sens.</p>
        </fieldset>
        <div class="overflow-hidden rounded-2xl bg-slate-900"><video ref="video" class="mx-auto max-h-[40vh] w-full object-contain" playsinline muted aria-label="Caméra de lecture du badge"></video></div>
        <p v-if="scanError" role="alert" class="font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ scanError }}</p>
        <form class="space-y-3" aria-label="Saisir le code du badge" @submit.prevent="submit(code)">
          <label class="block text-xl font-semibold">QR illisible ? Saisissez le code imprimé (exemple : B-014)<input v-model="code" class="field !min-h-16 !text-2xl uppercase" autocomplete="off" maxlength="100" data-testid="code-manuel"></label>
          <button type="submit" class="btn-main" :disabled="!code.trim() || busy || (mode === 'controle' && needsDirection && !direction) || (mode === 'restitution' && (!online || !!giveBackCheck))" data-testid="valider-code">{{ mode === 'controle' ? 'Contrôler ce badge' : mode === 'sortie' ? 'Enregistrer la sortie' : 'Vérifier le badge à restituer' }}</button>
        </form>
        <button type="button" class="btn-soft" :aria-expanded="showIncidents" data-testid="ouvrir-incidents" @click="showIncidents = !showIncidents">{{ showIncidents ? 'Fermer les refus et incidents' : 'Refus et incidents de ce poste' }}</button>
        <IncidentsPanel v-if="showIncidents && repository.incidents" :repository="repository" :reasons="reasonLabels" />
        <button type="button" class="btn-soft" data-testid="changer-point" @click="changePoint">Changer de point de contrôle</button>
      </template>
    </div>

    <div v-if="showing" class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-5 px-6 py-8 text-center text-white" :class="good ? 'bg-green-700' : 'bg-red-700'" role="alertdialog" aria-modal="true" :aria-label="good ? 'Résultat : valide' : 'Résultat : refusé'" :data-latency-ms="latency ?? undefined" data-testid="resultat" :data-verdict="good ? 'valide' : 'refuse'" @click="dismiss">
      <template v-if="control">
        <p class="text-[clamp(3rem,12vw,6rem)] font-extrabold leading-none" data-testid="verdict"><span aria-hidden="true">{{ control.valid ? '✓' : '✕' }}</span> {{ control.valid ? 'VALIDE' : 'NON VALIDE' }}</p>
        <template v-if="control.valid && control.person">
          <p v-if="control.direction" class="rounded-2xl bg-white px-4 py-2 text-3xl font-extrabold text-ikoma-navy" data-testid="sens-resultat">{{ DIRECTION_LABEL[control.direction] }}</p>
          <p class="text-4xl font-bold" data-testid="nom">{{ control.person.name }}</p>
          <div v-if="control.category" class="rounded-2xl bg-white px-4 py-2 text-ikoma-navy"><CategorySwatch :name="control.category.name" :color="control.category.color" :priority="control.category.priority_circuit" /></div>
          <p class="text-2xl">{{ control.organisation || 'Organisation non indiquée' }}</p>
          <p class="text-2xl" data-testid="zones">Zones autorisées : {{ control.zones.join(', ') || 'aucune' }}</p>
          <p v-if="control.zone_ok === false" class="rounded-2xl border-4 border-white bg-amber-300 px-5 py-3 text-3xl font-extrabold text-red-900" data-testid="zone-non-autorisee"><span aria-hidden="true">⚠</span> ZONE NON AUTORISÉE : {{ control.point }}</p>
        </template>
        <template v-else>
          <p v-if="control.direction" class="rounded-2xl bg-white px-4 py-2 text-3xl font-extrabold text-ikoma-navy" data-testid="sens-resultat">{{ DIRECTION_LABEL[control.direction] }}</p>
          <p class="text-4xl font-bold" data-testid="raison">{{ reasonLabels[control.reason ?? ''] ?? control.reason }}</p>
          <p class="text-2xl">{{ control.message }}</p>
        </template>
      </template>
      <template v-else-if="exit">
        <p class="text-[clamp(2.5rem,10vw,5rem)] font-extrabold leading-none" data-testid="verdict"><span aria-hidden="true">{{ exit.ok ? '✓' : '✕' }}</span> {{ exit.ok ? 'SORTIE ENREGISTRÉE' : 'SORTIE REFUSÉE' }}</p>
        <template v-if="exit.ok">
          <p class="text-4xl font-bold" data-testid="nom">{{ exit.person?.name }}</p>
          <p class="text-2xl">Badge {{ exit.badge_code }}<template v-if="exit.badge_status"> · {{ exit.badge_status === 'Associé' ? 'à restituer à l’accueil' : exit.badge_status === 'Disponible' ? 'de nouveau disponible' : 'clos (usage unique)' }}</template></p>
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
  </AgentLayout>
</template>
