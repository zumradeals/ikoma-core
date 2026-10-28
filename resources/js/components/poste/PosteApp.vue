<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { initial, reduce, stepNumber, validateBlockedReason, type Ev } from '../../poste/machine';
import { HttpPosteRepository, OperationIds, type PosteRepository } from '../../poste/repository';
import { ApiError, type Avis, type DeskContext, type Fiche, type Poste, type SearchResult, type UnlistedInput } from '../../poste/types';
import StatusBar from './StatusBar.vue'; import StepTracker from './StepTracker.vue'; import PosteSelect from './PosteSelect.vue'; import StepSearch from './StepSearch.vue'; import StepFiche from './StepFiche.vue';
import StepIdentity from './StepIdentity.vue'; import StepDecision from './StepDecision.vue'; import StepBadge from './StepBadge.vue'; import StepHandover from './StepHandover.vue'; import StepDone from './StepDone.vue';
import StepRefused from './StepRefused.vue'; import UnlistedForm from './UnlistedForm.vue'; import StepWaitHost from './StepWaitHost.vue';

const props = defineProps<{ agent: string; repository?: PosteRepository }>();
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

function onSelect(result: SearchResult) {
  return run(async () => { const f = await repo.visitor(result.id); fiche.value = f; homonyms.value = result.homonyms; send({ type: 'SELECT', fiche: f }); });
}
const onConfirm = () => send({ type: 'CONFIRM_FICHE' });
function onIdentity(photo: Blob) {
  return run(async () => {
    const id = ctx.value.visitorId as string; const key = `identite:${id}`;
    await repo.saveIdentity(ops.get(key), id, photo); ops.done(key);
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
    const f = await refreshFiche(id);
    if (f.host_validation === 'accepte') send({ type: 'HOST_DECISION', accepted: true });
    if (f.host_validation === 'refuse') send({ type: 'HOST_DECISION', accepted: false });
  } catch (e) { if (e instanceof ApiError) error.value = e; }
}
function onNext() { ctx.value = reduce(ctx.value, { type: 'NEXT' }); if (ctx.value.step === 'recherche') { fiche.value = null; error.value = null; homonyms.value = 1; } }
function onBack() { error.value = null; send({ type: 'BACK' }); }

const setOnline = () => { online.value = navigator.onLine; };
onMounted(async () => {
  window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline);
  clock = window.setInterval(() => { now.value = Date.now(); }, 1000);
  try { postes.value = await repo.listPostes(); pending.value = await repo.pendingCount(); }
  catch (e) { if (e instanceof ApiError) error.value = e; return; }
  const saved = readStored();
  if (saved && postes.value.some(p => p.id === saved)) await choosePoste(saved);
});
onBeforeUnmount(() => { window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); window.clearInterval(clock); });
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-ikoma-navy">
    <StatusBar :poste="posteName" :agent="agent" :online="online" :pending="pending" />
    <main id="contenu" class="mx-auto max-w-4xl space-y-6 px-4 py-6">
      <div v-if="error" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4 text-lg text-red-900" data-testid="erreur">
        <p class="font-bold"><span aria-hidden="true">⚠</span> {{ error.message }}</p>
        <p v-if="error.action && !error.message.includes(error.action)" class="mt-1 font-semibold">{{ error.action }}</p>
      </div>
      <PosteSelect v-if="!context" :postes="postes" @select="choosePoste" />
      <template v-else>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <StepTracker :current="stepNumber[ctx.step]" class="min-w-64 flex-1" />
          <p v-if="ctx.startedAt !== null && ctx.step !== 'entree'" class="rounded-full bg-white px-4 py-2 text-xl font-bold" aria-label="Chrono du parcours" data-testid="chrono-live"><span aria-hidden="true">⏱</span> {{ clockLabel }}</p>
        </div>
        <StepSearch v-if="ctx.step === 'recherche'" :repository="repo" @select="onSelect" @started="send({ type: 'START' })" @unlisted="send({ type: 'NEW_UNLISTED' })" @error="e => (error = e)" />
        <UnlistedForm v-else-if="ctx.step === 'non_liste'" :context="context" :busy="busy" @submit="onCreateUnlisted" @back="onBack" />
        <StepFiche v-else-if="ctx.step === 'fiche' && fiche" :key="fiche.id" :fiche="fiche" :homonyms="homonyms" @confirm="onConfirm" @back="onBack" />
        <StepWaitHost v-else-if="ctx.step === 'attente_hote' && fiche" :fiche="fiche" :refused="ctx.hostRefused" :busy="busy" @refresh="onHostRefresh" @next="onNext" />
        <StepIdentity v-else-if="ctx.step === 'identite' && fiche" :name="fiche.full_name" :busy="busy" @done="onIdentity" @back="onBack" />
        <StepDecision v-else-if="ctx.step === 'decision' && fiche" :name="fiche.full_name" :hors-periode="fiche.hors_periode" :blocked-reason="blockedReason" :busy="busy" @validate="onValidate" @refuse="onRefuse" @back="onBack" />
        <StepBadge v-else-if="ctx.step === 'badge' && fiche" :name="fiche.full_name" :busy="busy" @submit="onBadge" />
        <StepHandover v-else-if="ctx.step === 'remise' && fiche" :fiche="fiche" :badge-code="ctx.badgeCode ?? ''" :busy="busy" @enter="onEnter" />
        <StepDone v-else-if="ctx.step === 'entree' && fiche" :name="fiche.full_name" :badge-code="ctx.badgeCode ?? ''" :zones="entryZones" :duration-ms="ctx.durationMs ?? 0" @next="onNext" />
        <StepRefused v-else-if="ctx.step === 'refus' && fiche" :name="fiche.full_name" @next="onNext" />
      </template>
    </main>
  </div>
</template>
