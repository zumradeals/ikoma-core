<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import PosteApp from './PosteApp.vue';
import ControleApp from './ControleApp.vue';
import type { SitePosteRepository } from '../../poste/repository';
import { PosteRuntime, type WipeReason } from '../../poste/local/runtime';
import { getMeta } from '../../poste/local/db';
import { pinProblem, PinError } from '../../poste/local/vault';
import type { LocalOp } from '../../poste/local/apply';
import type { SyncState } from '../../poste/local/sync';
import { ApiError } from '../../poste/types';

const props = defineProps<{ agent: { id: string; name: string } | null; runtime?: PosteRuntime; app?: 'poste' | 'controle' }>();
const phase = ref<'chargement' | 'vide' | 'verrouille' | 'ouvert'>('chargement');
const sync = ref<SyncState | null>(null);
const wiped = ref<WipeReason | null>(null);
const message = ref<string | null>(null);
const code = ref(''); const pin = ref(''); const pin2 = ref(''); const busy = ref(false);
const lockedUntil = ref(0); const now = ref(Date.now()); const remaining = ref<number | null>(null);
const conflicts = shallowRef<LocalOp[]>([]);
const openKey = ref(0);
let idleTimer: number | undefined; let tick: number | undefined; let loop: number | undefined;
let idleMs = 300_000;

const runtime = props.runtime ?? new PosteRuntime();
runtime.handlers = {
  onState: (s: SyncState) => { sync.value = s; if (phase.value === 'ouvert') void loadConflicts(); },
  onWiped: (r: WipeReason) => { wiped.value = r; message.value = null; phase.value = 'vide'; clearTimeout(idleTimer); },
};

const waiting = computed(() => (lockedUntil.value > now.value ? Math.ceil((lockedUntil.value - now.value) / 1000) : 0));
const wipeText: Record<WipeReason, string> = {
  echecs_pin: 'Trop de PIN incorrects : toutes les données de cette tablette ont été effacées par sécurité.',
  deconnexion: 'Cette tablette a été déconnectée par l’administrateur : ses données ont été effacées.',
  fin_evenement: 'L’événement est terminé depuis plus de 24 heures : les données de cette tablette ont été effacées.',
  manuel: 'Les données de cette tablette ont été effacées.',
};
const conflictLabel: Record<string, string> = { identite: 'Pièce d’identité et photo', valider: 'Validation', refuser: 'Refus', badge: 'Badge', entree: 'Entrée', non_liste: 'Visiteur non listé' };

async function loadConflicts() { conflicts.value = runtime.vault.unlocked ? await runtime.conflicts() : []; }
function visitorName(op: LocalOp): string { return runtime.repo?.list.visitors.get(String(op.payload.visitor_id))?.full_name ?? 'Visiteur inconnu'; }
async function dismiss(op: LocalOp) { await runtime.store.dismissConflict(op.id); await runtime.sync.refreshCounts(); await loadConflicts(); }

function resetIdle() {
  clearTimeout(idleTimer);
  if (phase.value === 'ouvert') idleTimer = window.setTimeout(lock, idleMs);
}
function lock() { runtime.lock(); phase.value = 'verrouille'; pin.value = ''; message.value = null; clearTimeout(idleTimer); openKey.value++; }

async function afterOpen() {
  const settings = await getMeta(runtime.db, 'settings');
  idleMs = (settings?.inactivity_minutes ?? 5) * 60_000;
  phase.value = 'ouvert'; wiped.value = null; resetIdle(); await loadConflicts();
}

function explain(e: unknown): string {
  if (e instanceof PinError || e instanceof ApiError) return e.message;
  return 'Une erreur est survenue. Réessayez ou appelez le superviseur.';
}

async function enrol() {
  message.value = null;
  if (!props.agent) return;
  const problem = pin.value !== pin2.value ? 'Les deux PIN ne sont pas identiques.' : pinProblem(pin.value);
  if (problem) { message.value = problem; return; }
  busy.value = true;
  try { await runtime.enrol(code.value, pin.value, props.agent); pin.value = ''; pin2.value = ''; code.value = ''; await afterOpen(); }
  catch (e) { message.value = explain(e); }
  finally { busy.value = false; }
}

async function unlock() {
  message.value = null; busy.value = true;
  try {
    await runtime.unlock(pin.value); pin.value = '';
    if (runtime.vault.unlocked) await afterOpen();
  } catch (e) {
    pin.value = '';
    if (e instanceof PinError) {
      lockedUntil.value = e.lockedUntil; remaining.value = e.code === 'pin_incorrect' || e.code === 'verrouille' ? e.remaining : null;
    }
    message.value = explain(e);
  } finally { busy.value = false; }
}

async function syncNow() { await runtime.sync.sync(); }

onMounted(async () => {
  tick = window.setInterval(() => { now.value = Date.now(); }, 1000);
  for (const name of ['pointerdown', 'keydown', 'touchstart']) window.addEventListener(name, resetIdle, { passive: true });
  window.addEventListener('online', () => { if (phase.value === 'ouvert') runtime.sync.kick(); });
  loop = window.setInterval(async () => {
    if (phase.value !== 'ouvert') return;
    if (await runtime.expired()) { lock(); await runtime.wipe('fin_evenement'); return; }
    if (navigator.onLine) void runtime.sync.sync();
  }, 60_000);
  phase.value = (await runtime.state()) === 'vide' ? 'vide' : 'verrouille';
});
onBeforeUnmount(() => {
  clearInterval(tick); clearInterval(loop); clearTimeout(idleTimer); runtime.sync.stop();
  for (const name of ['pointerdown', 'keydown', 'touchstart']) window.removeEventListener(name, resetIdle);
});
</script>
<template>
  <div v-if="phase === 'chargement'" class="grid min-h-screen place-items-center bg-slate-50 text-ikoma-navy"><p role="status">Ouverture du poste…</p></div>

  <main v-else-if="phase === 'vide'" class="mx-auto min-h-screen max-w-xl space-y-5 bg-slate-50 px-4 py-8 text-ikoma-navy" data-testid="ecran-enrolement">
    <p class="text-2xl font-bold">IKOMA <span class="font-normal">Accueil</span></p>
    <p v-if="wiped" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4 font-semibold text-red-900" data-testid="message-efface"><span aria-hidden="true">⚠</span> {{ wipeText[wiped] }}</p>
    <h1 class="text-display font-bold">Enrôler cette tablette</h1>
    <template v-if="agent">
      <p>Demandez un code d’enrôlement à l’administrateur (valable 15 minutes, une seule fois), puis choisissez un PIN de 6 chiffres. Ce PIN déverrouille la tablette, même sans réseau.</p>
      <form class="space-y-4" @submit.prevent="enrol">
        <label class="block text-lg font-semibold">Code d’enrôlement<input v-model="code" class="field !text-2xl" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX" data-testid="code-enrolement" required></label>
        <label class="block text-lg font-semibold">Choisissez un PIN (6 chiffres)<input v-model="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" class="field !text-2xl" data-testid="pin" required></label>
        <label class="block text-lg font-semibold">Confirmez le PIN<input v-model="pin2" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" class="field !text-2xl" data-testid="pin-confirmation" required></label>
        <p v-if="message" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-gate"><span aria-hidden="true">⚠</span> {{ message }}</p>
        <button type="submit" class="btn-main" :disabled="busy" data-testid="enroler">{{ busy ? 'Copie de la liste en cours…' : 'Enrôler cette tablette' }}</button>
      </form>
      <p class="text-sm">Agent connecté : <strong>{{ agent.name }}</strong>. Cette tablette sera réservée à cet agent.</p>
    </template>
    <template v-else>
      <p role="alert" class="rounded-xl border-2 border-amber-700 bg-amber-50 p-4 font-semibold text-amber-950">Cette tablette n’est pas encore enrôlée. L’enrôlement demande le réseau : ouvrez <a class="underline" href="/poste">le poste d’accueil</a> en étant connecté avec votre compte.</p>
    </template>
  </main>

  <main v-else-if="phase === 'verrouille'" class="mx-auto min-h-screen max-w-xl space-y-5 bg-slate-50 px-4 py-8 text-ikoma-navy" data-testid="ecran-verrou">
    <p class="text-2xl font-bold">IKOMA <span class="font-normal">Accueil</span></p>
    <h1 class="text-display font-bold">Tablette verrouillée</h1>
    <p>Saisissez votre PIN à 6 chiffres. Rien n’est lisible sur cette tablette tant qu’elle est verrouillée.</p>
    <form class="space-y-4" @submit.prevent="unlock">
      <label class="block text-lg font-semibold">PIN<input v-model="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" class="field !text-3xl tracking-widest" data-testid="pin-deverrouillage" autofocus required></label>
      <p v-if="message" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-gate"><span aria-hidden="true">⚠</span> {{ message }}<template v-if="waiting"> Réessayez dans {{ waiting }} s.</template><template v-if="remaining !== null && remaining <= 5"> Essais restants avant l’effacement : {{ remaining }}.</template></p>
      <button type="submit" class="btn-main" :disabled="busy || waiting > 0" data-testid="deverrouiller">Déverrouiller</button>
    </form>
  </main>

  <template v-else>
    <ControleApp v-if="app === 'controle' && runtime.repo" :key="openKey" :agent="runtime.profile?.name ?? agent?.name ?? ''" :repository="runtime.repo as SitePosteRepository" :sync="sync" @sync="syncNow" @lock="lock" />
    <PosteApp v-else :key="openKey" :agent="runtime.profile?.name ?? agent?.name ?? ''" :repository="runtime.repo ?? undefined" :sync="sync" :auto-select="true" @sync="syncNow" @lock="lock" />
    <section v-if="conflicts.length" class="mx-auto max-w-4xl px-4 pb-8" aria-labelledby="conflits-titre" data-testid="conflits">
      <div class="rounded-2xl border-2 border-red-700 bg-red-50 p-5">
        <h2 id="conflits-titre" class="text-xl font-bold text-red-900"><span aria-hidden="true">⚠</span> {{ conflicts.length }} opération{{ conflicts.length > 1 ? 's' : '' }} refusée{{ conflicts.length > 1 ? 's' : '' }} par le serveur</h2>
        <p class="mt-1">Ces opérations n’ont pas été appliquées et le superviseur est informé. Rien n’a été écrasé.</p>
        <ul class="mt-3 space-y-2">
          <li v-for="op in conflicts" :key="op.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3">
            <span><strong>{{ conflictLabel[op.type] }}</strong> · {{ visitorName(op) }}<br><span class="text-red-900">{{ op.result?.message }}</span></span>
            <button type="button" class="btn-soft" @click="dismiss(op)">Compris</button>
          </li>
        </ul>
      </div>
    </section>
  </template>
</template>
