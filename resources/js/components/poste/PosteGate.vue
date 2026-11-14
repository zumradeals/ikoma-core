<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, provide, ref, shallowRef } from 'vue';
import PosteApp from './PosteApp.vue';
import ControleApp from './ControleApp.vue';
import GroupeApp from './GroupeApp.vue';
import SiteModeNotice from './SiteModeNotice.vue';
import type { GroupPosteRepository, SitePosteRepository } from '../../poste/repository';
import { PosteRuntime, syncRequiredMessage, type WipeReason } from '../../poste/local/runtime';
import { closeSharedSession, sharedRuntime } from '../../poste/local/session';
import { cookie } from '../../poste/repository';
import { PinError } from '../../poste/local/vault';
import type { LocalOp } from '../../poste/local/apply';
import type { SyncState } from '../../poste/local/sync';
import { ApiError } from '../../poste/types';
import { useInstitution } from '../../composables/useInstitution';

const props = defineProps<{ agent: { id: string; name: string } | null; runtime?: PosteRuntime; app?: 'poste' | 'controle' | 'groupe' }>();
const institution = useInstitution();
// vide : enrôlement ; migration : ancien coffre par PIN, à migrer une dernière fois ; ferme : réseau ou connexion nécessaire pour s'ouvrir ; ouvert. Plus aucun PIN au quotidien.
const phase = ref<'chargement' | 'vide' | 'migration' | 'ferme' | 'ouvert'>('chargement');
const sync = ref<SyncState | null>(null);
const wiped = ref<WipeReason | null>(null);
const message = ref<string | null>(null);
const code = ref(''); const pin = ref(''); const busy = ref(false);
const lockedUntil = ref(0); const now = ref(Date.now()); const remaining = ref<number | null>(null);
const conflicts = shallowRef<LocalOp[]>([]);
const resetConfirm = ref(false); const resetPending = ref(0);
const openKey = ref(0);
// Fusion (test de faisabilité) : un seul écran déverrouillé ; poste, contrôle et groupes sont des VUES internes qui partagent le même coffre, la même copie et la même synchronisation.
// Aucune visite de page : le changement de vue marche donc aussi sans réseau. Les adresses /poste, /controle et /groupe restent des points d'entrée (vue de départ).
type View = 'poste' | 'controle' | 'groupe';
const view = ref<View>(props.app ?? 'poste');
const viewMode = ref<string | null>(null);
/** Vue de départ selon le poste (capacités du contrat V1) : sans « accueil » mais avec scan ou sortie (la soute), on ouvre le contrôle ; la tablette sans poste (hôtel) ouvre les groupes. Seulement quand l'adresse ouverte est celle du poste. */
function chooseStartView() {
  if (props.app !== undefined && props.app !== 'poste') return;
  const list = runtime.repo?.list;
  const caps = runtime.repo?.capabilities?.() ?? null;
  if (!list || caps === null) return;
  if (!caps.includes('accueil') && (caps.includes('scan') || caps.includes('sortie'))) view.value = 'controle';
  else if (caps.length === 0 && list.snapshot.poste.point_code === 'hotel') view.value = 'groupe';
}
/** L'adresse suit la vue (retour du navigateur, rechargement et liens directs restent cohérents) ; l'état de la page Inertia est conservé. */
const pathOf = (v: View, mode: string | null) => (v === 'controle' ? '/controle' + (mode ? '?mode=' + encodeURIComponent(mode) : '') : v === 'groupe' ? '/groupe' + (mode ? '?mode=' + encodeURIComponent(mode) : '') : '/poste');
function viewFromLocation(): { v: View; mode: string | null } {
  const mode = new URLSearchParams(window.location.search).get('mode');
  const path = window.location.pathname;
  return path.startsWith('/controle') ? { v: 'controle', mode } : path.startsWith('/groupe') ? { v: 'groupe', mode } : { v: 'poste', mode: null };
}
function onPopState() { const { v, mode } = viewFromLocation(); view.value = v; viewMode.value = mode; }
provide('agentNavigate', (next: View, mode: string | null = null) => {
  view.value = next; viewMode.value = mode;
  try { window.history.pushState({ ...(window.history.state ?? {}), ikomaView: { view: next, mode } }, '', pathOf(next, mode)); } catch { /* l'adresse est un confort : la vue a déjà changé */ }
});
let tick: number | undefined; let loop: number | undefined;

// Coffre partagé pendant toute la connexion : le PIN n'est saisi qu'une fois (voir session.ts).
const runtime = props.runtime ?? sharedRuntime();
/** Vrai quand la copie de la tablette n'a pas d'activité (mode site) : seul le contrôle des cartes permanentes est ouvert. */
const siteMode = () => runtime.repo?.list.snapshot.event === null;
runtime.handlers = {
  onState: (s: SyncState) => { sync.value = s; if (phase.value === 'ouvert') void loadConflicts(); },
  onWiped: (r: WipeReason) => { wiped.value = r; message.value = null; phase.value = 'vide'; },
};

const waiting = computed(() => (lockedUntil.value > now.value ? Math.ceil((lockedUntil.value - now.value) / 1000) : 0));
const wipeText: Record<WipeReason, string> = {
  echecs_pin: 'Trop de PIN incorrects : toutes les données de cette tablette ont été effacées par sécurité.',
  deconnexion: 'Cette tablette a été déconnectée par l’administrateur : ses données ont été effacées.',
  fin_evenement: 'L’événement est terminé depuis plus de 24 heures : les données de cette tablette ont été effacées.',
  manuel: 'Les données locales de ce navigateur ont été effacées. Vous pouvez enrôler un nouveau poste.',
  poste_supprime: 'Cet ancien poste n’existe plus sur le serveur : son enrôlement local a été effacé. Enrôlez le nouveau poste.',
  compte_change: 'Le compte actuellement connecté n’est plus celui qui avait enrôlé cette tablette. Les anciennes données locales ont été effacées : enrôlez de nouveau ce poste.',
};
const conflictLabel: Record<string, string> = { identite: 'Pièce d’identité et photo', valider: 'Validation', refuser: 'Refus', badge: 'Badge', entree: 'Entrée', non_liste: 'Visiteur non listé', groupe_controle: 'Contrôle de correspondance (groupe)', groupe_badge: 'Badge remis à un stagiaire', groupe_depart: 'Fermeture du car', groupe_arrivee: 'Arrivée du car', groupe_arrivee_fin: 'Fin du contrôle d’arrivée' };

async function loadConflicts() { conflicts.value = runtime.vault.unlocked ? await runtime.conflicts() : []; }
function visitorName(op: LocalOp): string {
  if (op.payload.visitor_id) return runtime.repo?.list.visitors.get(String(op.payload.visitor_id))?.full_name ?? 'Visiteur inconnu';
  if (op.payload.group_id) { const g = runtime.repo?.list.groups.get(String(op.payload.group_id)); return g ? (g.bus_number ?? g.name) : 'Groupe inconnu'; }
  return op.payload.code ? `Badge ${String(op.payload.code)}` : 'Opération';
}
async function dismiss(op: LocalOp) { await runtime.store.dismissConflict(op.id); await runtime.sync.refreshCounts(); await loadConflicts(); }

/** « Fermer ma session » : supprime le bail de la tablette puis déconnecte le compte ; le coffre ne se rouvrira qu'après une nouvelle connexion en ligne. Les données et la file restent chiffrées. */
async function lock() {
  await closeSharedSession();
  openKey.value++;
  try { await fetch('/logout', { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' }); } catch { /* hors ligne : le bail est déjà supprimé */ }
  window.location.href = '/login';
}

async function afterOpen() {
  chooseStartView();
  phase.value = 'ouvert'; wiped.value = null; message.value = null; await loadConflicts();
}

function explain(e: unknown): string {
  if (e instanceof PinError || e instanceof ApiError) return e.message;
  return 'Une erreur est survenue. Réessayez ou appelez le superviseur.';
}

async function enrol() {
  message.value = null;
  if (!props.agent) return;
  busy.value = true;
  try { await runtime.enrol(code.value, props.agent); code.value = ''; await afterOpen(); }
  catch (e) { message.value = explain(e); }
  finally { busy.value = false; }
}

/** Ouverture automatique, sans saisie : bail conservé ou demandé au serveur. */
async function begin() {
  message.value = null;
  try {
    const result = await runtime.resume(props.agent?.id);
    if (result.state === 'ouvert') await afterOpen();
    else if (result.state === 'migration') phase.value = 'migration';
    else if (result.state === 'ferme') { phase.value = 'ferme'; message.value = result.reason ?? null; }
    else phase.value = 'vide';
  } catch (e) { phase.value = 'ferme'; message.value = explain(e); }
}

/** Migration unique d'une tablette enrôlée avant la suppression du PIN : dernière saisie du PIN, puis plus jamais. */
async function migrate() {
  message.value = null; busy.value = true;
  try { await runtime.migrate(pin.value, props.agent?.id); pin.value = ''; if (runtime.vault.unlocked) await afterOpen(); else phase.value = (await runtime.state()) === 'vide' ? 'vide' : 'ferme'; }
  catch (e) {
    pin.value = '';
    if (e instanceof PinError) { lockedUntil.value = e.lockedUntil; remaining.value = e.code === 'pin_incorrect' || e.code === 'verrouille' ? e.remaining : null; }
    message.value = explain(e);
  } finally { busy.value = false; }
}

async function requestLocalReset() {
  resetPending.value = await runtime.store.count('pending');
  resetConfirm.value = true;
}
async function resetThisBrowser() {
  resetConfirm.value = false;
  // Explicite : un reset du navigateur n'efface ni la base serveur ni le nouveau code de poste.
  await runtime.wipe('manuel', true);
  try { window.localStorage.removeItem('ikoma.poste'); } catch { /* préférence facultative */ }
}
async function syncNow() { await runtime.sync.sync(); }

/** Retour du réseau : la synchronisation reprend ; une tablette fermée faute de réseau s'ouvre toute seule. */
function onOnline() { if (phase.value === 'ouvert') runtime.sync.kick(); else if (phase.value === 'ferme') void begin(); }

onMounted(async () => {
  window.addEventListener('popstate', onPopState);
  tick = window.setInterval(() => { now.value = Date.now(); }, 1000);
  window.addEventListener('online', onOnline);
  loop = window.setInterval(async () => {
    if (phase.value !== 'ouvert') return;
    if (await runtime.expired()) { runtime.lock(); phase.value = 'ferme'; await runtime.wipe('fin_evenement'); return; }
    const required = await runtime.syncRequiredHours();
    if (required !== null && !navigator.onLine) { runtime.lock(); phase.value = 'ferme'; message.value = syncRequiredMessage(required); return; }
    if (navigator.onLine) { void runtime.sync.sync(); void runtime.renewLease(); }
  }, 60_000);
  // Coffre déjà ouvert pendant cette connexion (changement d'écran sans rechargement) : on le reprend, sauf s'il appartient à un autre compte.
  if (runtime.vault.unlocked) {
    if (!props.agent || !runtime.profile || runtime.profile.id === props.agent.id) { await afterOpen(); runtime.sync.kick(); return; }
    runtime.lock();
  }
  await begin();
});
onBeforeUnmount(() => {
  window.removeEventListener('popstate', onPopState);
  window.removeEventListener('online', onOnline);
  clearInterval(tick); clearInterval(loop); runtime.sync.stop();
});
</script>
<template>
  <div v-if="phase === 'chargement'" class="grid min-h-screen place-items-center bg-slate-50 text-ikoma-navy"><p role="status">Ouverture du poste…</p></div>

  <main v-else-if="phase === 'vide'" class="agent-lockscreen mx-auto min-h-screen max-w-xl space-y-5 bg-slate-50 px-4 py-8 text-ikoma-navy" data-testid="ecran-enrolement">
    <p class="text-2xl font-bold">{{ institution.custom ? institution.name : 'IKOMA' }} <span class="font-normal">Accueil</span></p>
    <p v-if="wiped" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4 font-semibold text-red-900" data-testid="message-efface"><span aria-hidden="true">⚠</span> {{ wipeText[wiped] }}</p>
    <h1 class="text-display font-bold">Enrôler cette tablette</h1>
    <template v-if="agent">
      <p>Demandez un code d’enrôlement à l’administrateur (valable 15 minutes, une seule fois). Aucun code PIN n’est nécessaire : la tablette s’ouvre toute seule pour l’agent connecté.</p>
      <form class="space-y-4" @submit.prevent="enrol">
        <label class="block text-lg font-semibold">Code d’enrôlement<input v-model="code" class="field !text-2xl" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX" data-testid="code-enrolement" required></label>
        <p v-if="message" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-gate"><span aria-hidden="true">⚠</span> {{ message }}</p>
        <button type="submit" class="btn-main" :disabled="busy" data-testid="enroler">{{ busy ? 'Copie de la liste en cours…' : 'Enrôler cette tablette' }}</button>
      </form>
      <p class="text-sm">Agent connecté : <strong>{{ agent.name }}</strong>. Cette tablette sera réservée à cet agent.</p>
    </template>
    <template v-else>
      <p role="alert" class="rounded-xl border-2 border-amber-700 bg-amber-50 p-4 font-semibold text-amber-950">Cette tablette n’est pas encore enrôlée. L’enrôlement demande le réseau : ouvrez <a class="underline" href="/poste">le poste d’accueil</a> en étant connecté avec votre compte.</p>
    </template>
  </main>

  <main v-else-if="phase === 'migration'" class="agent-lockscreen mx-auto min-h-screen max-w-xl space-y-5 bg-slate-50 px-4 py-8 text-ikoma-navy" data-testid="ecran-migration">
    <p class="text-2xl font-bold">{{ institution.custom ? institution.name : 'IKOMA' }} <span class="font-normal">Accueil</span></p>
    <h1 class="text-display font-bold">Une dernière fois : votre PIN</h1>
    <p>Le code PIN est supprimé. Pour conserver vos données et vos opérations en attente, saisissez votre ancien PIN une dernière fois : il ne sera plus jamais demandé.</p>
    <form class="space-y-4" @submit.prevent="migrate">
      <label class="block text-lg font-semibold">Ancien PIN<input v-model="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" class="field !text-3xl tracking-widest" data-testid="pin-migration" required></label>
      <p v-if="message" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-gate"><span aria-hidden="true">⚠</span> {{ message }}<template v-if="waiting"> Réessayez dans {{ waiting }} s.</template><template v-else-if="remaining !== null"> Essais restants : {{ remaining }}.</template></p>
      <button type="submit" class="btn-main" :disabled="busy || waiting > 0" data-testid="migrer">Continuer</button>
    </form>
    <button v-if="agent" type="button" class="btn-soft" data-testid="reinitialiser-tablette-verrouillee" @click="requestLocalReset">PIN oublié ? Réinitialiser ce navigateur pour le réenrôler</button>
  </main>

  <main v-else-if="phase === 'ferme'" class="agent-lockscreen mx-auto min-h-screen max-w-xl space-y-5 bg-slate-50 px-4 py-8 text-ikoma-navy" data-testid="ecran-ferme">
    <p class="text-2xl font-bold">{{ institution.custom ? institution.name : 'IKOMA' }} <span class="font-normal">Accueil</span></p>
    <h1 class="text-display font-bold">Connexion au serveur nécessaire</h1>
    <p>Cette tablette ne peut pas s’ouvrir pour le moment. Vos données et vos opérations en attente restent chiffrées et ne sont pas perdues.</p>
    <p v-if="message" role="alert" class="rounded-xl border-2 border-amber-700 bg-amber-50 p-3 font-semibold text-amber-950" data-testid="erreur-gate"><span aria-hidden="true">⚠</span> {{ message }}</p>
    <button type="button" class="btn-main" data-testid="reessayer-ouverture" @click="begin">Réessayer</button>
    <button v-if="agent" type="button" class="btn-soft" data-testid="reinitialiser-tablette-verrouillee" @click="requestLocalReset">Ancien poste ? Réinitialiser ce navigateur pour le réenrôler</button>
  </main>

  <template v-else>
    <SiteModeNotice v-if="view !== 'controle' && runtime.repo && siteMode()" :key="openKey" :repository="runtime.repo" :online="sync?.online ?? true" />
    <GroupeApp v-else-if="view === 'groupe' && runtime.repo" :key="openKey + 'g' + (viewMode ?? '')" :initial-tab="viewMode" :agent="runtime.profile?.name ?? agent?.name ?? ''" :repository="runtime.repo as GroupPosteRepository" :sync="sync" @sync="syncNow" @lock="lock" />
    <ControleApp v-else-if="view === 'controle' && runtime.repo" :key="openKey + 'c' + (viewMode ?? '')" :initial-mode="viewMode" :agent="runtime.profile?.name ?? agent?.name ?? ''" :repository="runtime.repo as SitePosteRepository" :sync="sync" @sync="syncNow" @lock="lock" />
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
  <section v-if="resetConfirm" role="dialog" aria-modal="true" aria-label="Confirmer le réenrôlement" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
    <div class="w-full max-w-lg space-y-4 rounded-xl bg-white p-6 text-ikoma-navy">
      <h2 class="text-xl font-bold">Effacer les données de cette tablette ?</h2>
      <p>Seules les données enregistrées dans ce navigateur seront effacées. Les événements, agents et badges du serveur resteront inchangés.</p>
      <p v-if="resetPending > 0" class="font-bold text-red-800" role="alert">Attention : {{ resetPending }} opération(s) non synchronisée(s) seront définitivement perdues.</p>
      <p>Vous devrez utiliser un nouveau code d’enrôlement et choisir un nouveau PIN.</p>
      <div class="flex flex-wrap gap-3">
        <button type="button" class="btn-soft" @click="resetConfirm = false">Annuler</button>
        <button type="button" class="btn-main" data-testid="confirmer-reset-tablette" @click="resetThisBrowser">Effacer ce navigateur</button>
      </div>
    </div>
  </section>
</template>
