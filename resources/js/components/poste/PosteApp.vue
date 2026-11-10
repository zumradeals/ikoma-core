<script setup lang="ts">
import AdminIcon from '../admin/AdminIcon.vue';
import AgentLayout from '../../layouts/AgentLayout.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { initial, reduce, stepNumber, validateBlockedReason, type Ev } from '../../poste/machine';
import { HttpPosteRepository, OperationIds, type PosteRepository } from '../../poste/repository';
import { ApiError, type ActivityChoice, type Avis, type DeskContext, type DeskSummary, type Fiche, type IdentityDocument, type Poste, type SearchResult, type UnlistedInput } from '../../poste/types';
import StatusBar from './StatusBar.vue'; import StepTracker from './StepTracker.vue'; import PosteSelect from './PosteSelect.vue'; import StepSearch from './StepSearch.vue'; import StepFiche from './StepFiche.vue';
import StepIdentity from './StepIdentity.vue'; import StepDecision from './StepDecision.vue'; import StepBadge from './StepBadge.vue'; import StepHandover from './StepHandover.vue'; import StepDone from './StepDone.vue';
import PosteFooter from './PosteFooter.vue'; import DeskPanel from './DeskPanel.vue'; import StepRefused from './StepRefused.vue'; import UnlistedForm from './UnlistedForm.vue'; import StepWaitHost from './StepWaitHost.vue';

import type { SyncState } from '../../poste/local/sync';
const props = defineProps<{ agent: string; repository?: PosteRepository; sync?: SyncState | null; autoSelect?: boolean }>();
const emit = defineEmits<{ sync: []; lock: [] }>();
const STORAGE_KEY = 'ikoma.poste';
const posteId = ref<string | null>(null);
const repo: PosteRepository = props.repository ?? new HttpPosteRepository(() => posteId.value);
const ops = new OperationIds();

const postes = ref<Poste[]>([]); const context = ref<DeskContext | null>(null);
const ctx = ref(initial()); const fiche = ref<Fiche | null>(null); const homonyms = ref(1); const entryZones = ref<string[]>([]);
const error = ref<ApiError | null>(null); const busy = ref(false); const online = ref(navigator.onLine); const pending = ref(0); const now = ref(Date.now());
let clock: number | undefined;

const posteName = computed(() => { const p = postes.value.find(x => x.id === posteId.value); return p ? (p.station || p.name) : null; });
const elapsed = computed(() => (ctx.value.startedAt === null ? 0 : Math.max(0, Math.round((now.value - ctx.value.startedAt) / 1000))));
const clockLabel = computed(() => `${Math.floor(elapsed.value / 60)}:${String(elapsed.value % 60).padStart(2, '0')}`);
const blockedReason = computed(() => validateBlockedReason(ctx.value));

// Données du bandeau d'événement et de l'en-tête : tout vient du contexte du poste et de l'état réel de la synchronisation.
const shortDay = (d: Date) => d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' });
const eventPeriod = computed(() => {
  const e = context.value?.event; if (!e?.starts_at || !e.ends_at) return null;
  const a = new Date(e.starts_at); const b = new Date(e.ends_at);
  if (a.toDateString() === b.toDateString()) return `${shortDay(a)} ${a.getFullYear()}`;
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() ? `${String(a.getDate()).padStart(2, '0')} – ${shortDay(b)} ${b.getFullYear()}` : `${shortDay(a)} – ${shortDay(b)} ${b.getFullYear()}`;
});
const eventState = computed(() => {
  const e = context.value?.event; if (!e?.starts_at || !e.ends_at) return null;
  return now.value < Date.parse(e.starts_at) ? { label: 'À venir', tone: 'poste-chip-warn' } : now.value > Date.parse(e.ends_at) ? { label: 'Terminé', tone: 'poste-chip-warn' } : { label: 'En cours', tone: 'poste-chip-ok' };
});
const syncLabel = computed(() => {
  const at = props.sync?.lastSyncAt; if (!at) return null;
  const d = new Date(at); const time = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  return new Date(now.value).toDateString() === d.toDateString() ? `Aujourd’hui à ${time}` : `${shortDay(d)} à ${time}`;
});
const hasAlerts = computed(() => !online.value || !!props.sync?.needsLogin || !!props.sync?.stale || !!props.sync?.clockWarning || !!props.sync?.lastError);

// Activité du jour : liste des activités autorisées (en ligne) ; le choix n'est possible qu'à l'étape de recherche, jamais pendant l'accueil d'une personne.
const choices = ref<ActivityChoice[]>([]);
async function refreshChoices() {
  if (!repo.activities || !context.value || !online.value) return;
  try { choices.value = await repo.activities(); } catch { /* hors ligne ou indisponible : la tablette garde l'activité en cours */ }
}
watch([context, online, () => props.sync?.lastSyncAt], () => { void refreshChoices(); });
const canSwitch = computed(() => choices.value.length > 1 && online.value && ctx.value.step === 'recherche');
function onSwitchActivity(event: Event) {
  const id = (event.target as HTMLSelectElement).value;
  const current = context.value?.event.id;
  if (!id || id === current) return;
  const name = choices.value.find(c => c.id === id)?.name ?? 'cette activité';
  if (!window.confirm(`Passer à l’activité « ${name} » ? La liste de cette tablette sera remplacée.`)) { (event.target as HTMLSelectElement).value = current ?? ''; return; }
  void run(async () => {
    await repo.switchActivity!(id);
    context.value = await repo.context(); fiche.value = null; ctx.value = initial(); homonyms.value = 1;
    await refreshChoices(); await refreshSummary();
  });
}

// Panneau du poste : recalculé quand l'agent revient à la recherche, quand la file ou la synchronisation changent.
const summary = ref<DeskSummary | null>(null);
let summarySeq = 0;
async function refreshSummary() {
  if (!repo.deskSummary || !context.value) return;
  const mine = ++summarySeq;
  try { const fresh = await repo.deskSummary(); if (mine === summarySeq) summary.value = fresh; }
  catch { /* le panneau est un confort : son échec ne doit jamais gêner l'accueil */ }
}
watch([() => ctx.value.step, () => props.sync?.pending, () => props.sync?.lastSyncAt, context], () => { void refreshSummary(); });
watch(() => props.sync?.lastSyncAt, () => {
  if (!context.value) return;
  const currentPoste = context.value.poste.id;
  void repo.context().then(latest => {
    if (context.value?.poste.id === currentPoste && latest.poste.id === currentPoste) context.value = latest;
  }).catch(() => { /* une synchronisation peut échouer : conserver le dernier contexte connu */ });
});

function send(event: Ev) { ctx.value = reduce(ctx.value, event); }
function readStored(): string | null { try { return window.localStorage.getItem(STORAGE_KEY); } catch { return null; } }
function store(id: string) { try { window.localStorage.setItem(STORAGE_KEY, id); } catch { /* le choix du poste reste valable pour cette session */ } }

/** Exécute une action serveur : un seul appel à la fois, erreur en français, opération conservée si le réseau tombe. */
async function run<T>(action: () => Promise<T>): Promise<T | undefined> {
  if (busy.value) return undefined;
  busy.value = true; error.value = null;
  try { return await action(); }
  catch (e) { error.value = e instanceof ApiError ? e : new ApiError('erreur', 'Une erreur est survenue. Réessayez ou appelez le superviseur.'); return undefined; }
  finally { busy.value = false; }
}

async function choosePoste(id: string) {
  posteId.value = id; store(id);
  const loaded = await run(() => repo.context());
  if (loaded) context.value = loaded; else posteId.value = null;
}
async function refreshFiche(id: string) { fiche.value = await repo.visitor(id); return fiche.value; }

function selectVisitor(id: string, homonymCount: number) {
  return run(async () => { const f = await repo.visitor(id); fiche.value = f; homonyms.value = homonymCount; send({ type: 'SELECT', fiche: f }); });
}
const onSelect = (result: SearchResult) => selectVisitor(result.id, result.homonyms);
const onConfirm = () => send({ type: 'CONFIRM_FICHE' });
function onIdentity(photo: Blob, doc: IdentityDocument | undefined) {
  return run(async () => {
    const id = ctx.value.visitorId as string; const key = `identite:${id}`;
    await repo.saveIdentity(ops.get(key), id, photo, doc); ops.done(key);
    await refreshFiche(id); send({ type: 'IDENTITY_SAVED' });
  });
}
function onValidate(avis: Avis | undefined) {
  return run(async () => {
    const id = ctx.value.visitorId as string; const key = `valider:${id}:${avis ?? ''}`;
    await repo.validate(ops.get(key), id, avis); ops.done(key);
    await refreshFiche(id); send({ type: 'VALIDATED' });
  });
}
function onRefuse(motif: string) {
  return run(async () => {
    const id = ctx.value.visitorId as string; const key = `refuser:${id}:${motif}`;
    await repo.refuse(ops.get(key), id, motif); ops.done(key); send({ type: 'REFUSED' });
  });
}
function onBadge(code: string) {
  return run(async () => {
    const id = ctx.value.visitorId as string; const key = `badge:${id}:${code}`;
    try { const done = await repo.assignBadge(ops.get(key), id, code); ops.done(key); await refreshFiche(id); send({ type: 'BADGE_ASSIGNED', code: done.badge_code }); }
    catch (e) { if (e instanceof ApiError && e.code !== 'reseau') ops.done(key); throw e; }
  });
}
function onEnter() {
  return run(async () => {
    const id = ctx.value.visitorId as string; const key = `entree:${id}`;
    const duration = ctx.value.startedAt === null ? 0 : Date.now() - ctx.value.startedAt;
    const done = await repo.enter(ops.get(key), id, duration); ops.done(key);
    entryZones.value = done.zones; send({ type: 'ENTERED' });
  });
}
function onCreateUnlisted(input: UnlistedInput) {
  return run(async () => {
    const key = `non_liste:${input.name}:${input.host_id}`;
    const created = await repo.createUnlisted(ops.get(key), input); ops.done(key);
    await refreshFiche(created.visitor_id); send({ type: 'UNLISTED_CREATED', visitorId: created.visitor_id });
  });
}
async function onHostRefresh() {
  const id = ctx.value.visitorId; if (!id) return;
  try {
    const f = repo.refreshHost ? await repo.refreshHost(id) : await refreshFiche(id); fiche.value=f;
    if (f.host_validation === 'accepte') send({ type: 'HOST_DECISION', accepted: true });
    if (f.host_validation === 'refuse') send({ type: 'HOST_DECISION', accepted: false });
  } catch (e) { if (e instanceof ApiError) error.value = e; }
}
async function onRequestAdvice(){
  const id=ctx.value.visitorId;if(!id || !repo.requestHostAdvice)return;
  await run(async()=>{await repo.requestHostAdvice!(id);fiche.value=repo.refreshHost?await repo.refreshHost(id):await repo.visitor(id);});
}
async function onAdviceRefresh(){const id=ctx.value.visitorId;if(!id)return;await run(async()=>{fiche.value=repo.refreshHost?await repo.refreshHost(id):await repo.visitor(id);});}
function onNext() { ctx.value = reduce(ctx.value, { type: 'NEXT' }); if (ctx.value.step === 'recherche') { fiche.value = null; error.value = null; homonyms.value = 1; } }
function onBack() { error.value = null; send({ type: 'BACK' }); }

const offlineSince = ref<number | null>(navigator.onLine ? null : Date.now());
const offlineMinutes = computed(() => (offlineSince.value === null ? null : Math.max(0, Math.floor((now.value - offlineSince.value) / 60_000))));
const setOnline = () => { online.value = navigator.onLine; offlineSince.value = navigator.onLine ? null : (offlineSince.value ?? Date.now()); };
onMounted(async () => {
  window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline);
  clock = window.setInterval(() => { now.value = Date.now(); }, 1000);
  try { postes.value = await repo.listPostes(); pending.value = await repo.pendingCount(); }
  catch (e) { if (e instanceof ApiError) error.value = e; return; }
  const saved = readStored();
  if (props.autoSelect && postes.value.length === 1) await choosePoste(postes.value[0].id);
  else if (saved && postes.value.some(p => p.id === saved)) await choosePoste(saved);
});
onBeforeUnmount(() => { window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); window.clearInterval(clock); });
</script>

<template>
  <AgentLayout active="poste" :agent="agent" :online="online" :sync-label="context ? syncLabel : null" :notifications="sync?.conflicts ?? 0" :site-name="context?.event.site ?? null">
    <template v-if="context" #sidebar-extra>
      <div class="agent-sidebar-device" role="group" aria-label="Appareil et connexion" data-testid="menu-appareil"><p class="agent-nav-caption">Appareil &amp; connexion</p>
        <a class="agent-nav-link" href="#poste-barre-basse"><AdminIcon name="tablet" :size="24" />Appareil et poste</a>
        <button type="button" class="agent-nav-link w-full text-left" :disabled="!sync || sync.syncing" data-testid="nav-synchroniser" @click="emit('sync')"><AdminIcon name="refresh" :size="24" />Synchronisation<span v-if="sync && sync.lastSyncAt && !sync.stale && !sync.lastError" class="agent-nav-ok" aria-label="À jour">✓</span></button>
        <button type="button" class="agent-nav-link w-full text-left" data-testid="nav-verrouiller" @click="emit('lock')"><AdminIcon name="lock" :size="24" />Verrouillage</button>
      </div>
    </template>
    <template #header-context><span v-if="posteName" class="agent-poste-chip" data-testid="poste-en-cours"><AdminIcon name="map" :size="24" /><span><small>Poste en cours</small><strong>{{ posteName }}</strong></span></span></template>
    <template #status><StatusBar v-if="hasAlerts" :poste="posteName" :agent="agent" :online="online" :pending="sync ? sync.pending : pending" :sync="sync" :offline-minutes="offlineMinutes" :call-after="summary?.offlineCallMinutes" :compact="!!context" @sync="emit('sync')" @lock="emit('lock')" /></template>
    <div class="agent-workspace agent-desk-workspace">
      <div v-if="error" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4 text-lg text-red-900" data-testid="erreur">
        <p class="font-bold"><span aria-hidden="true">⚠</span> {{ error.message }}</p>
        <p v-if="error.action && !error.message.includes(error.action)" class="mt-1 font-semibold">{{ error.action }}</p>
      </div>
      <PosteSelect v-if="!context" :postes="postes" @select="choosePoste" />
      <template v-else>
        <div class="poste-titlebar">
          <div class="poste-title-block"><span class="poste-title-icon" aria-hidden="true"><AdminIcon name="users" :size="32" /></span><div><h1 class="poste-title" data-testid="titre-poste">Poste d’accueil <span>– Enregistrement d’un visiteur</span></h1><p>Accueil, identification et enregistrement de l’entrée.</p></div></div>
          <div class="poste-title-chips">
            <p v-if="choices.length > 1" class="poste-chip-box" data-testid="selecteur-activite"><AdminIcon name="calendar" :size="22" /> <label for="activite-du-jour">Événement :</label> <select id="activite-du-jour" class="poste-activity-select" :value="context.event.id" :disabled="!canSwitch" @change="onSwitchActivity"><option v-for="c in choices" :key="c.id" :value="c.id">{{ c.name }}</option></select></p>
            <p v-else class="poste-chip-box"><AdminIcon name="calendar" :size="22" /> Événement : <strong>{{ context.event.name }}</strong></p>
            <p class="poste-chip-box"><AdminIcon name="layers" :size="22" /> Opérations en attente : <strong data-testid="en-attente">{{ sync ? sync.pending : pending }}</strong></p>
          </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <StepTracker :current="stepNumber[ctx.step]" class="min-w-64 flex-1" />
          <p v-if="ctx.startedAt !== null && ctx.step !== 'entree'" class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-xl font-bold" aria-label="Chrono du parcours" data-testid="chrono-live"><AdminIcon name="clock" /> {{ clockLabel }}</p>
        </div>
        <p v-if="ctx.step === 'recherche'" class="poste-eventbar" data-testid="poste-evenement-liste">
          <strong><AdminIcon name="users" :size="22" /> Événement : {{ context.event.name }}</strong>
          <span v-if="eventPeriod" data-testid="evt-periode"><AdminIcon name="calendar" :size="20" /> {{ eventPeriod }}</span>
          <span v-if="context.event.site" data-testid="evt-site"><AdminIcon name="map" :size="20" /> {{ context.event.site }}</span>
          <span class="poste-counts"><span><AdminIcon name="users" :size="20" /> Participants : <b data-testid="evt-participants">{{ context.visitor_count ?? '—' }}</b></span><span class="poste-sep" aria-hidden="true">/</span><span>Présents : <b data-testid="evt-presents">{{ context.present_count ?? '—' }}</b></span><span class="poste-sep" aria-hidden="true">/</span><span>Absents : <b data-testid="evt-absents">{{ context.absent_count ?? '—' }}</b></span></span>
          <span v-if="eventState" class="poste-chip ml-auto" :class="eventState.tone" data-testid="evt-etat">{{ eventState.label }}</span>
        </p>
        <template v-if="ctx.step === 'recherche'">
          <StepSearch :repository="repo" :refresh-key="sync?.lastSyncAt ?? null" :event-name="context.event.name" :categories="context.categories" :total="context.visitor_count ?? null" :period="eventPeriod" @select="onSelect" @open="id => selectVisitor(id, 1)" @started="send({ type: 'START' })" @unlisted="send({ type: 'NEW_UNLISTED' })" @error="e => (error = e)" />
          <DeskPanel v-if="summary" :summary="summary" :online="online" :syncing="sync?.syncing" @sync="emit('sync')" @select="id => selectVisitor(id, 1)" />
        </template>
        <UnlistedForm v-else-if="ctx.step === 'non_liste'" :context="context" :busy="busy" @submit="onCreateUnlisted" @back="onBack" />
        <StepFiche v-else-if="ctx.step === 'fiche' && fiche" :key="fiche.id" :fiche="fiche" :homonyms="homonyms" @confirm="onConfirm" @back="onBack" />
        <StepWaitHost v-else-if="ctx.step === 'attente_hote' && fiche" :fiche="fiche" :refused="ctx.hostRefused" :busy="busy" @refresh="onHostRefresh" @next="onNext" />
        <StepIdentity v-else-if="ctx.step === 'identite' && fiche" :name="fiche.full_name" :busy="busy" :document-required="fiche.identity_document === true" @done="onIdentity" @back="onBack" />
        <StepDecision v-else-if="ctx.step === 'decision' && fiche" :name="fiche.full_name" :hors-periode="fiche.hors_periode" :blocked-reason="blockedReason" :busy="busy" :host-advice="fiche.host_advice?.state" :can-request="!!repo.requestHostAdvice" @request-advice="onRequestAdvice" @refresh-advice="onAdviceRefresh" @validate="onValidate" @refuse="onRefuse" @back="onBack" />
        <StepBadge v-else-if="ctx.step === 'badge' && fiche" :name="fiche.full_name" :busy="busy" @submit="onBadge" />
        <StepHandover v-else-if="ctx.step === 'remise' && fiche" :fiche="fiche" :badge-code="ctx.badgeCode ?? ''" :busy="busy" @enter="onEnter" />
        <StepDone v-else-if="ctx.step === 'entree' && fiche" :name="fiche.full_name" :badge-code="ctx.badgeCode ?? ''" :zones="entryZones" :duration-ms="ctx.durationMs ?? 0" @next="onNext" />
        <StepRefused v-else-if="ctx.step === 'refus' && fiche" :name="fiche.full_name" @next="onNext" />
        <PosteFooter :poste="posteName" :device="context.poste.name" :agent="agent" :online="online" :sync="sync" @sync="emit('sync')" @lock="emit('lock')" />
      </template>
    </div>
  </AgentLayout>
</template>
