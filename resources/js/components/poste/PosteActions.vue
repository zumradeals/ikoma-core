<script setup lang="ts">
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue';
import AdminIcon from '../admin/AdminIcon.vue';
import type { PosteRepository } from '../../poste/repository';
import type { GroupSummary } from '../../poste/local/group';

/**
 * Actions du poste de travail, au-dessus de l'accueil des visiteurs : ce que ce poste permet, et rien d'autre (capacités du contrat V1, revérifiées par le serveur).
 *  - scan : « Scanner un badge ou une carte » (action principale) ;
 *  - sortie : « Enregistrer une sortie » et « Restituer un badge » : deux actes distincts, la restitution exige le réseau ;
 *  - scan et un car parti : « Arrivée d'un car » ;
 *  - aucune capacité : message clair, pas d'écran vide.
 * Dans l'écran déverrouillé du poste, les boutons changent de vue sans visite de page (même hors ligne) ; seuls, ils gardent leurs liens de page.
 */
const props = defineProps<{ repository: PosteRepository }>();
type View = 'poste' | 'controle' | 'groupe';
const navigate = inject<((view: View, mode?: string | null) => void) | null>('agentNavigate', null);
const online = ref(navigator.onLine);
const setOnline = () => { online.value = navigator.onLine; };
const caps = computed<string[] | null>(() => (props.repository.capabilities ? props.repository.capabilities() : null));
const can = (capability: string) => caps.value !== null && caps.value.includes(capability);
const noOperation = computed(() => caps.value !== null && caps.value.length === 0);
const arrivals = ref<GroupSummary[]>([]);
const group = computed(() => arrivals.value[0] ?? null);

async function loadArrivals() {
  const groups = (props.repository as { groups?: () => Promise<GroupSummary[]> }).groups;
  if (!groups || !can('scan')) { arrivals.value = []; return; }
  try { arrivals.value = (await groups.call(props.repository)).filter(g => g.departed && !g.arrival_closed); } catch { arrivals.value = []; }
}
onMounted(() => { window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline); void loadArrivals(); });
onBeforeUnmount(() => { window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); });

function go(event: Event, view: View, mode: string | null) {
  if (navigate) { event.preventDefault(); navigate(view, mode); }
}
/** Sans l'écran déverrouillé (liens de page), les changements d'écran demandent le réseau ; la restitution le demande toujours. */
const reachable = (needsNetwork: boolean) => (needsNetwork ? online.value : online.value || navigate !== null);
</script>

<template>
  <div class="agent-actions-grid">
  <section v-if="noOperation" class="agent-actions-none" role="status" aria-labelledby="aucune-operation" data-testid="poste-sans-operation">
    <AdminIcon name="info" :size="32" />
    <div><h2 id="aucune-operation">Aucune opération numérique à ce poste</h2><p>Ce poste n’a pas de capacité configurée : il n’y a rien à faire ici sur la tablette. En cas de doute, appelez le superviseur.</p></div>
  </section>

  <section v-if="can('scan') || can('sortie')" class="agent-card agent-actions" aria-labelledby="controler-titre" data-testid="operations-poste">
    <h2 id="controler-titre"><AdminIcon name="qr" :size="30" /> Contrôler un accès</h2>
    <p class="agent-actions-hint">Présentez la carte ou scannez le badge.</p>
    <a v-if="can('scan') && reachable(false)" href="/controle?mode=controle" class="agent-action-main" data-testid="operation-controle" @click="go($event, 'controle', 'controle')"><AdminIcon name="qr" :size="32" /> Scanner un badge ou une carte</a>
    <span v-else-if="can('scan')" class="agent-action-main is-off" aria-disabled="true" data-testid="operation-controle"><AdminIcon name="qr" :size="32" /> Scanner un badge ou une carte <small>Réseau requis</small></span>
    <div v-if="can('sortie')" class="agent-action-row">
      <a v-if="reachable(false)" href="/controle?mode=sortie" class="agent-action-soft" data-testid="operation-sortie" @click="go($event, 'controle', 'sortie')"><AdminIcon name="arrow" :size="26" /><span><strong>Enregistrer une sortie</strong><small>Le badge n’est pas restitué.</small></span></a>
      <span v-else class="agent-action-soft is-off" aria-disabled="true" data-testid="operation-sortie"><AdminIcon name="arrow" :size="26" /><span><strong>Enregistrer une sortie</strong><small>Réseau requis</small></span></span>
      <a v-if="reachable(true)" href="/controle?mode=restitution" class="agent-action-soft" data-testid="operation-restitution" @click="go($event, 'controle', 'restitution')"><AdminIcon name="tag" :size="26" /><span><strong>Restituer un badge</strong><small>Acte distinct de la sortie.</small></span></a>
      <span v-else class="agent-action-soft is-off" aria-disabled="true" data-testid="operation-restitution"><AdminIcon name="tag" :size="26" /><span><strong>Restituer un badge</strong><small>Réseau requis</small></span></span>
    </div>
  </section>

  <section v-if="group" class="agent-card agent-actions" aria-labelledby="car-titre" data-testid="arrivee-car">
    <h2 id="car-titre"><AdminIcon name="bus" :size="30" /> Arrivée d’un car</h2>
    <p class="agent-actions-hint">Un car est parti : scannez les badges des stagiaires à l’arrivée.</p>
    <p class="agent-action-car"><strong>{{ group.bus_number ?? group.name }}</strong><span>{{ group.name }}</span><span>{{ group.boarded }} embarqué{{ group.boarded > 1 ? 's' : '' }}</span></p>
    <a v-if="reachable(false)" href="/groupe" class="agent-action-soft" data-testid="operation-arrivee-car" @click="go($event, 'groupe', 'arrivee')"><AdminIcon name="arrow" :size="26" /><span><strong>Contrôler l’arrivée du car</strong></span></a>
    <span v-else class="agent-action-soft is-off" aria-disabled="true"><AdminIcon name="arrow" :size="26" /><span><strong>Contrôler l’arrivée du car</strong><small>Réseau requis</small></span></span>
  </section>
  </div>
</template>
