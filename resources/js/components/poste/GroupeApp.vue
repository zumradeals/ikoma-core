<script setup lang="ts">
import AgentLayout from '../../layouts/AgentLayout.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import StatusBar from './StatusBar.vue';
import { startScan, type ScanHandle } from '../../poste/media';
import type { GroupPosteRepository } from '../../poste/repository';
import type { ArrivalResult, GroupMemberView, GroupSummary, GroupView } from '../../poste/local/group';
import type { SyncState } from '../../poste/local/sync';
import { ApiError } from '../../poste/types';

const props = defineProps<{ agent: string; repository: GroupPosteRepository; sync?: SyncState | null }>();
const emit = defineEmits<{ sync: []; lock: [] }>();
type Tab = 'appel' | 'arrivee';

const tab = ref<Tab>('appel');
const groups = ref<GroupSummary[]>([]);
const groupId = ref<string | null>(null);
const view = ref<GroupView | null>(null);
const chosen = ref<string | null>(null); // personne choisie dans la liste, sinon la suivante à appeler
const search = ref('');
const idChecked = ref(false);
const code = ref('');
const error = ref<string | null>(null);
const busy = ref(false);
const online = ref(navigator.onLine);
const confirmClose = ref(false);
const bilan = ref<{ boarded: number; absent: number } | null>(null);
const video = ref<HTMLVideoElement | null>(null);
const scanError = ref('');
const last = ref<ArrivalResult | null>(null);
const arrivedNow = ref(0);
const finish = ref<{ group: string; arrived: number; missing: number } | null>(null);
let handle: ScanHandle | null = null;
let waitTimer: number | undefined;

const mode = computed(() => view.value?.mode ?? 'encadreur');
const members = computed(() => view.value?.members ?? []);
const departed = computed(() => view.value?.group.departed === true);
const pending = computed(() => members.value.filter(m => !m.badge_code && !m.absent));
const current = computed<GroupMemberView | null>(() => {
  if (departed.value) return null;
  return members.value.find(m => m.visitor_id === chosen.value) ?? pending.value[0] ?? null;
});
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return q ? members.value.filter(m => m.name.toLowerCase().includes(q)) : members.value;
});
const absents = computed(() => members.value.filter(m => m.absent));
const percent = computed(() => (view.value && view.value.progress.total ? Math.round((view.value.progress.badged / view.value.progress.total) * 100) : 0));
const departable = computed(() => view.value !== null && !departed.value && view.value.progress.total > 0);
const arrivalGroups = computed(() => groups.value.filter(g => g.departed && !g.arrival_closed));

async function refresh() {
  groups.value = await props.repository.groups();
  if (groupId.value) view.value = await props.repository.groupView(groupId.value).catch(() => null);
}

async function openGroup(id: string) {
  groupId.value = id; chosen.value = null; idChecked.value = false; code.value = ''; error.value = null; bilan.value = null; search.value = '';
  await refresh();
  if (departed.value && view.value) bilan.value = { boarded: view.value.progress.boarded, absent: view.value.progress.absent };
}
function closeGroup() { groupId.value = null; view.value = null; chosen.value = null; error.value = null; void refresh(); }

function explain(e: unknown): string { return e instanceof ApiError ? e.message + (e.action ? ` ${e.action}` : '') : 'Une erreur est survenue. Réessayez ou appelez le superviseur.'; }

async function check() {
  const person = current.value;
  if (!person || !groupId.value || busy.value) return;
  busy.value = true; error.value = null;
  try { await props.repository.groupCheck(crypto.randomUUID(), groupId.value, person.visitor_id, idChecked.value); idChecked.value = false; await refresh(); }
  catch (e) { error.value = explain(e); }
  finally { busy.value = false; }
}

async function deliver(raw: string): Promise<boolean> {
  const person = current.value; const value = raw.trim();
  if (!person || !person.checked || !groupId.value || !value || busy.value) return false;
  busy.value = true; error.value = null;
  try {
    await props.repository.groupDeliver(crypto.randomUUID(), groupId.value, person.visitor_id, value);
    code.value = ''; chosen.value = null;
    await refresh();
  } catch (e) { error.value = explain(e); }
  finally { busy.value = false; }
  return true;
}

async function depart() {
  if (!groupId.value || busy.value) return;
  busy.value = true; error.value = null;
  try { bilan.value = await props.repository.groupDepart(crypto.randomUUID(), groupId.value); confirmClose.value = false; await refresh(); }
  catch (e) { error.value = explain(e); }
  finally { busy.value = false; }
}

async function arrive(raw: string): Promise<boolean> {
  const value = raw.trim();
  if (!value || busy.value) return false;
  busy.value = true; error.value = null;
  try {
    const result = await props.repository.arrive(crypto.randomUUID(), value);
    last.value = result; code.value = '';
    if (!result.already) arrivedNow.value++;
    await refresh();
  } catch (e) { error.value = explain(e); }
  finally { busy.value = false; }
  return true;
}

async function endArrival(g: GroupSummary) {
  if (busy.value) return;
  busy.value = true; error.value = null;
  try { const r = await props.repository.arriveFinish(crypto.randomUUID(), g.id); finish.value = { group: g.bus_number ?? g.name, ...r }; await refresh(); }
  catch (e) { error.value = explain(e); }
  finally { busy.value = false; }
}

// La caméra reste ouverte tant que la personne à appeler existe (l'élément vidéo n'est que masqué avant le contrôle) : pas de réouverture à chaque stagiaire.
function onCode(text: string): boolean {
  const person = current.value;
  // Retour immédiat « pas prêt » (opération en cours, correspondance pas encore validée) : le lecteur renverra la même lecture à l'image suivante.
  if (busy.value || (tab.value !== 'arrivee' && !person?.checked)) return false;
  void (tab.value === 'arrivee' ? arrive(text) : deliver(text));
  return true;
}
async function startCamera() {
  scanError.value = '';
  if (!video.value || handle) return;
  try { handle = await startScan(video.value, onCode); }
  catch (e) { scanError.value = e instanceof Error && e.message.includes('HTTPS') ? e.message : 'La caméra n’est pas accessible : saisissez le code imprimé sur le badge.'; }
}
function stopCamera() { handle?.stop(); handle = null; }
watch(video, element => { if (element) void startCamera(); else stopCamera(); });

// Mode « encadreur » : on attend sa confirmation, qui arrive par la synchronisation (réseau nécessaire pour cette seule étape).
function pollConfirmation() {
  window.clearTimeout(waitTimer);
  waitTimer = window.setTimeout(() => { if (tab.value === 'appel' && mode.value === 'encadreur' && current.value && !current.value.checked && !current.value.confirmed && navigator.onLine) emit('sync'); pollConfirmation(); }, 4000);
}
watch(() => props.sync?.lastSyncAt, () => { void refresh(); });
watch(tab, () => { error.value = null; code.value = ''; });

const setOnline = () => { online.value = navigator.onLine; };
onMounted(async () => {
  window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline);
  await refresh(); await nextTick(); pollConfirmation();
});
onBeforeUnmount(() => { stopCamera(); window.clearTimeout(waitTimer); window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); });

function statusOf(m: GroupMemberView): string {
  if (m.absent) return 'Absent';
  if (m.arrived) return 'Arrivé';
  if (m.boarded) return 'Embarqué';
  if (m.badge_code) return `Badge ${m.badge_code}`;
  if (m.checked) return 'Contrôlé';
  if (m.confirmed) return 'Confirmé par l’encadreur';
  return 'À appeler';
}
</script>

<template>
  <AgentLayout active="groupe" :agent="agent" :online="online">
    <template #header-context><span v-if="view?.group.bus_number ?? view?.group.name ?? null">Groupe : {{ view?.group.bus_number ?? view?.group.name ?? null }}</span></template>
    <template #status><StatusBar context-label="Groupe" :poste="view?.group.bus_number ?? view?.group.name ?? null" :agent="agent" :online="online" :pending="sync?.pending ?? 0" :sync="sync" @sync="emit('sync')" @lock="emit('lock')" /></template>
    <div class="agent-workspace agent-groupe-workspace" :data-busy="busy">
      <div class="grid grid-cols-2 gap-3" role="group" aria-label="Mode">
        <button type="button" class="min-h-16 rounded-xl border-2 px-4 py-3 text-xl font-bold" :class="tab === 'appel' ? 'border-ikoma-blue bg-ikoma-blue text-white' : 'border-ikoma-blue bg-white text-ikoma-navy'" :aria-pressed="tab === 'appel'" data-testid="onglet-appel" @click="tab = 'appel'">Appel et badges</button>
        <button type="button" class="min-h-16 rounded-xl border-2 px-4 py-3 text-xl font-bold" :class="tab === 'arrivee' ? 'border-ikoma-blue bg-ikoma-blue text-white' : 'border-ikoma-blue bg-white text-ikoma-navy'" :aria-pressed="tab === 'arrivee'" data-testid="onglet-arrivee" @click="tab = 'arrivee'">Arrivée du car</button>
      </div>
      <p v-if="!online" role="status" class="rounded-xl border-2 border-amber-700 bg-amber-50 p-3 font-semibold text-amber-950" data-testid="hors-ligne"><span aria-hidden="true">⚠</span> Hors ligne : tout est enregistré sur la tablette et sera envoyé dès le retour du réseau.</p>
      <div v-if="error" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4 text-lg text-red-900" data-testid="erreur"><span aria-hidden="true">⚠</span> {{ error }}</div>

      <template v-if="tab === 'appel'">
        <section v-if="!view" aria-labelledby="groupes-titre" class="space-y-4" data-testid="choix-groupe">
          <h1 id="groupes-titre" class="text-display font-bold">Quel groupe appeler ?</h1>
          <p v-if="!groups.length">Aucun groupe n’est enregistré pour cet événement : l’administrateur doit d’abord créer les groupes.</p>
          <ul class="space-y-3">
            <li v-for="g in groups" :key="g.id"><button type="button" class="btn-main !justify-between text-left" data-testid="choisir-groupe" @click="openGroup(g.id)"><span>{{ g.bus_number ?? g.name }} · {{ g.name }}</span><span class="text-lg font-normal">{{ g.badged }} / {{ g.total }}<template v-if="g.departed"> · parti</template></span></button></li>
          </ul>
        </section>

        <template v-else>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-heading font-bold"><span data-testid="nom-groupe">{{ view.group.bus_number ?? view.group.name }}</span> · {{ view.group.name }}</h1>
            <button type="button" class="btn-soft" data-testid="changer-groupe" @click="closeGroup">Changer de groupe</button>
          </div>
          <div class="rounded-2xl bg-white p-4">
            <p class="text-xl font-bold" data-testid="progression">Badges remis : {{ view.progress.badged }} sur {{ view.progress.total }}</p>
            <div class="mt-2 h-5 overflow-hidden rounded-full bg-slate-200" role="progressbar" :aria-valuenow="view.progress.badged" aria-valuemin="0" :aria-valuemax="view.progress.total" aria-label="Badges remis"><div class="h-full bg-green-700" :style="{ width: percent + '%' }"></div></div>
            <p class="mt-2 text-base" data-testid="mode-controle">Contrôle de correspondance : {{ mode === 'identite' ? 'pièce d’identité de chaque stagiaire' : 'confirmation de l’encadreur' }}</p>
          </div>

          <section v-if="departed" class="space-y-3 rounded-2xl border-2 p-5" :class="absents.length ? 'border-amber-700 bg-amber-50' : 'border-green-700 bg-green-50'" data-testid="bilan-car">
            <h2 class="text-2xl font-bold"><span aria-hidden="true">{{ absents.length ? '⚠' : '✓' }}</span> {{ absents.length ? 'Car parti avec des absents' : 'Groupe complet' }}</h2>
            <p class="text-xl">{{ view.progress.boarded }} stagiaire{{ view.progress.boarded > 1 ? 's' : '' }} embarqué{{ view.progress.boarded > 1 ? 's' : '' }}<template v-if="absents.length"> · {{ absents.length }} absent{{ absents.length > 1 ? 's' : '' }}. L’encadreur et le superviseur sont prévenus.</template></p>
            <ul v-if="absents.length" class="list-disc pl-6 text-lg" data-testid="liste-absents"><li v-for="m in absents" :key="m.visitor_id">{{ m.name }}</li></ul>
          </section>

          <section v-else-if="current" class="space-y-4" aria-labelledby="appel-titre" data-testid="appel">
            <h2 id="appel-titre" class="text-xl font-semibold">Appelez :</h2>
            <p class="rounded-2xl bg-white p-6 text-center text-[clamp(2rem,9vw,3.5rem)] font-extrabold leading-tight" data-testid="personne-courante">{{ current.name }}</p>
            <p v-if="current.organisation" class="text-center text-xl">{{ current.organisation }}</p>

            <div class="overflow-hidden rounded-2xl bg-slate-900"><video ref="video" class="mx-auto max-h-[35vh] w-full object-contain" playsinline muted aria-label="Caméra de lecture du badge"></video></div>
            <template v-if="!current.checked">
              <template v-if="mode === 'identite'">
                <label class="flex min-h-16 items-center gap-4 rounded-xl border-2 border-ikoma-blue bg-white px-4 text-xl font-semibold"><input v-model="idChecked" type="checkbox" class="size-8" data-testid="piece-controlee"> Pièce d’identité contrôlée</label>
                <button type="button" class="btn-main" :disabled="!idChecked || busy" data-testid="verifier-correspondance" @click="check">Valider la correspondance</button>
              </template>
              <template v-else>
                <p v-if="!current.confirmed" role="status" class="rounded-xl border-2 border-amber-700 bg-amber-50 p-4 text-lg font-semibold text-amber-950" data-testid="attente-encadreur"><span aria-hidden="true">⏳</span> En attente de la confirmation de l’encadreur pour cette personne.</p>
                <p v-else role="status" class="rounded-xl border-2 border-green-700 bg-green-50 p-4 text-lg font-semibold text-green-900" data-testid="confirme-encadreur"><span aria-hidden="true">✓</span> L’encadreur a confirmé cette personne.</p>
                <button type="button" class="btn-main" :disabled="!current.confirmed || busy" data-testid="verifier-correspondance" @click="check">Valider la correspondance</button>
              </template>
            </template>
            <template v-else>
              <p role="status" class="rounded-xl border-2 border-green-700 bg-green-50 p-3 text-lg font-semibold text-green-900"><span aria-hidden="true">✓</span> Correspondance validée. Remettez un badge du groupe et scannez-le.</p>
              <p v-if="scanError" role="alert" class="font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ scanError }}</p>
              <form class="space-y-3" aria-label="Saisir le code du badge" @submit.prevent="deliver(code)">
                <label class="block text-xl font-semibold">QR illisible ? Saisissez le code imprimé (exemple : B-014)<input v-model="code" class="field !min-h-16 !text-2xl uppercase" autocomplete="off" maxlength="100" data-testid="code-badge"></label>
                <button type="submit" class="btn-main" :disabled="!code.trim() || busy" data-testid="remettre-badge">Remettre ce badge</button>
              </form>
            </template>
          </section>

          <section v-if="!departed" class="space-y-3">
            <label class="block text-lg font-semibold">Chercher un stagiaire dans la liste<input v-model="search" class="field" autocomplete="off" data-testid="recherche-stagiaire" placeholder="Nom ou prénom"></label>
          </section>
          <ul class="space-y-2" aria-label="Stagiaires du groupe" data-testid="liste-stagiaires">
            <li v-for="m in filtered" :key="m.visitor_id">
              <button type="button" class="flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border bg-white px-4 py-2 text-left text-lg" :class="current?.visitor_id === m.visitor_id ? 'border-2 border-ikoma-blue' : 'border-slate-300'" :disabled="departed || !!m.badge_code || m.absent" data-testid="stagiaire" @click="chosen = m.visitor_id; idChecked = false">
                <span class="font-semibold">{{ m.name }}</span>
                <span class="text-base"><span aria-hidden="true">{{ m.absent ? '✕' : m.badge_code ? '✓' : m.confirmed ? '●' : '○' }}</span> {{ statusOf(m) }}</span>
              </button>
            </li>
          </ul>

          <section v-if="departable" class="space-y-3">
            <button v-if="!confirmClose" type="button" class="btn-main !bg-ikoma-navy" data-testid="fermer-car" @click="confirmClose = true">Fermer le car (embarquement)</button>
            <div v-else class="space-y-3 rounded-2xl border-2 border-ikoma-navy bg-white p-5" role="alertdialog" aria-label="Fermer le car">
              <p class="text-xl font-bold">{{ view.progress.badged }} stagiaire{{ view.progress.badged > 1 ? 's' : '' }} badgé{{ view.progress.badged > 1 ? 's' : '' }} embarqueront. {{ view.progress.total - view.progress.badged }} sera{{ view.progress.total - view.progress.badged > 1 ? 'ont' : '' }} déclaré{{ view.progress.total - view.progress.badged > 1 ? 's' : '' }} absent{{ view.progress.total - view.progress.badged > 1 ? 's' : '' }} et signalé{{ view.progress.total - view.progress.badged > 1 ? 's' : '' }} à l’encadreur et au superviseur.</p>
              <button type="button" class="btn-main" :disabled="busy" data-testid="confirmer-fermeture" @click="depart">Oui, fermer le car</button>
              <button type="button" class="btn-soft w-full" @click="confirmClose = false">Non, continuer l’appel</button>
            </div>
          </section>
        </template>
      </template>

      <template v-else>
        <section class="space-y-4" aria-labelledby="arrivee-titre" data-testid="arrivee">
          <h1 id="arrivee-titre" class="text-display font-bold">Arrivée du car</h1>
          <p>Scannez les badges des stagiaires un par un : chacun passe à « arrivé » et entre sur le site. Les écarts avec la liste d’embarquement sont signalés au superviseur.</p>
          <div class="overflow-hidden rounded-2xl bg-slate-900"><video ref="video" class="mx-auto max-h-[35vh] w-full object-contain" playsinline muted aria-label="Caméra de lecture du badge"></video></div>
          <p v-if="scanError" role="alert" class="font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ scanError }}</p>
          <form class="space-y-3" aria-label="Saisir le code du badge" @submit.prevent="arrive(code)">
            <label class="block text-xl font-semibold">QR illisible ? Saisissez le code imprimé<input v-model="code" class="field !min-h-16 !text-2xl uppercase" autocomplete="off" maxlength="100" data-testid="code-arrivee"></label>
            <button type="submit" class="btn-main" :disabled="!code.trim() || busy" data-testid="valider-arrivee">Enregistrer l’arrivée</button>
          </form>
          <p class="text-xl font-bold" data-testid="compteur-arrivee">Arrivés pendant cette séance : {{ arrivedNow }}</p>
          <div v-if="last" class="rounded-2xl border-2 p-4" :class="last.ecart ? 'border-amber-700 bg-amber-50' : 'border-green-700 bg-green-50'" role="status" data-testid="dernier-arrive">
            <p class="text-2xl font-bold"><span aria-hidden="true">{{ last.ecart ? '⚠' : '✓' }}</span> {{ last.name }}</p>
            <p class="text-lg">{{ last.group }} · badge {{ last.badge_code }}<template v-if="last.already"> · déjà arrivé</template></p>
            <p v-if="last.ecart" class="text-lg font-semibold text-amber-950" data-testid="ecart">Écart : cette personne n’était pas dans la liste d’embarquement. Le superviseur est prévenu.</p>
          </div>
          <div v-if="arrivalGroups.length" class="space-y-3">
            <h2 class="text-xl font-bold">Terminer le contrôle d’un car</h2>
            <ul class="space-y-2"><li v-for="g in arrivalGroups" :key="g.id"><button type="button" class="btn-soft w-full !justify-between" :disabled="busy" data-testid="terminer-arrivee" @click="endArrival(g)"><span>{{ g.bus_number ?? g.name }}</span><span>Terminer</span></button></li></ul>
          </div>
          <p v-if="finish" role="status" class="rounded-2xl border-2 p-4 text-xl font-bold" :class="finish.missing ? 'border-amber-700 bg-amber-50 text-amber-950' : 'border-green-700 bg-green-50 text-green-900'" data-testid="bilan-arrivee"><span aria-hidden="true">{{ finish.missing ? '⚠' : '✓' }}</span> {{ finish.group }} : {{ finish.arrived }} arrivé{{ finish.arrived > 1 ? 's' : '' }}<template v-if="finish.missing"> · {{ finish.missing }} embarqué{{ finish.missing > 1 ? 's' : '' }} non scanné{{ finish.missing > 1 ? 's' : '' }} (écart signalé au superviseur)</template><template v-else> · aucun écart</template></p>
        </section>
      </template>
    </div>
  </AgentLayout>
</template>
