<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import CategorySwatch from '../../components/CategorySwatch.vue';
import { LocalEvacuation, type Evacuation } from '../../pilotage/evacuation';
import { pinProblem, PinError } from '../../poste/local/vault';

const props = defineProps<{ event?: { id: string; name: string } | null; events?: { id: string; name: string }[]; openAlerts?: number }>();
const page = usePage<{ auth: { id: string; name: string } | null }>();
const auth = computed(() => page.props.auth);
const local = new LocalEvacuation();
const phase = ref<'chargement' | 'vide' | 'verrouille' | 'liste'>('chargement');
const data = ref<Evacuation | null>(null);
const source = ref<'serveur' | 'local'>('serveur');
const knownAt = ref<string | null>(null);
const online = ref(navigator.onLine);
const vaultState = ref<'vide' | 'verrouille' | 'deverrouille'>('vide');
const pin = ref(''); const pin2 = ref('');
const message = ref<string | null>(null); const info = ref<string | null>(null); const busy = ref(false);
let idle: number | undefined;

const time = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
const stale = computed(() => source.value === 'local' || !online.value);
const dateLabel = computed(() => (knownAt.value ? new Date(knownAt.value).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : ''));

function resetIdle() {
  window.clearTimeout(idle);
  idle = window.setTimeout(() => { // 5 minutes sans toucher l'écran : la copie locale n'est plus lisible
    local.lock(); vaultState.value = 'verrouille';
    if (source.value === 'local') { data.value = null; phase.value = 'verrouille'; }
  }, 300_000);
}
const explain = (e: unknown) => (e instanceof PinError ? e.message : 'Une erreur est survenue. Réessayez.');

async function fetchFresh(): Promise<Evacuation | null> {
  try {
    const url = '/pilotage/api/evacuation' + (props.event ? `?event=${props.event.id}` : '');
    const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    return response.ok ? await response.json() as Evacuation : null;
  } catch { return null; }
}

async function refresh() {
  busy.value = true; message.value = null;
  const fresh = await fetchFresh();
  busy.value = false;
  if (!fresh) { message.value = 'Le serveur ne répond pas : la dernière situation connue reste affichée.'; return; }
  data.value = fresh; source.value = 'serveur'; knownAt.value = fresh.as_of; phase.value = 'liste';
  if (vaultState.value === 'deverrouille') { await local.save(fresh); info.value = 'Copie hors ligne mise à jour.'; }
}

async function create() {
  message.value = null;
  const problem = pin.value !== pin2.value ? 'Les deux PIN ne sont pas identiques.' : pinProblem(pin.value);
  if (problem) { message.value = problem; return; }
  if (!data.value || !auth.value) return;
  busy.value = true;
  try { await local.create(pin.value, auth.value, data.value.event.ends_at); await local.save(data.value); vaultState.value = 'deverrouille'; info.value = 'Liste conservée chiffrée sur cet appareil.'; pin.value = ''; pin2.value = ''; resetIdle(); }
  catch (e) { message.value = explain(e); }
  finally { busy.value = false; }
}

async function unlock() {
  message.value = null; busy.value = true;
  try {
    await local.unlock(pin.value); pin.value = ''; vaultState.value = 'deverrouille'; resetIdle();
    if (!data.value) { // hors ligne : on lit la copie locale
      const stored = await local.load();
      if (!stored) { message.value = 'Aucune liste conservée sur cet appareil.'; return; }
      data.value = stored.data; knownAt.value = stored.knownAt; source.value = 'local'; phase.value = 'liste';
    } else { await local.save(data.value); info.value = 'Copie hors ligne mise à jour.'; }
  } catch (e) {
    pin.value = ''; message.value = explain(e);
    if (e instanceof PinError && e.code === 'efface') { vaultState.value = 'vide'; if (!data.value) phase.value = 'vide'; }
  } finally { busy.value = false; }
}

function print() { window.print(); }
const setOnline = () => { online.value = navigator.onLine; };
onMounted(async () => {
  window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline);
  for (const name of ['pointerdown', 'keydown', 'touchstart']) window.addEventListener(name, resetIdle, { passive: true });
  vaultState.value = await local.state();
  if (vaultState.value !== 'vide' && await local.expired()) { await local.wipe(); vaultState.value = 'vide'; info.value = 'L’événement est terminé depuis plus de 24 h : la copie conservée sur cet appareil a été effacée.'; }
  if (auth.value && online.value) { await refresh(); if (data.value) return; }
  phase.value = vaultState.value === 'vide' ? 'vide' : 'verrouille';
});
onBeforeUnmount(() => {
  window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); window.clearTimeout(idle);
  for (const name of ['pointerdown', 'keydown', 'touchstart']) window.removeEventListener(name, resetIdle);
});
</script>
<template>
  <PilotageLayout active="evacuation" :event="event ?? null" :events="events ?? []" :open-alerts="openAlerts ?? 0" title="Liste d’évacuation" :bare="!auth">
    <p v-if="stale && data" class="no-print rounded-xl border-2 border-amber-700 bg-amber-50 p-3 text-lg font-semibold text-amber-950" role="status" data-testid="fraicheur"><span aria-hidden="true">⚠</span> Situation connue à {{ dateLabel }}<template v-if="!online"> (hors ligne)</template> : des personnes ont pu entrer ou sortir depuis.</p>
    <p v-if="info" class="no-print rounded-xl bg-green-50 p-3 font-semibold text-green-900" role="status" data-testid="info">{{ info }}</p>
    <p v-if="message" class="no-print rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" role="alert" data-testid="erreur-evac"><span aria-hidden="true">⚠</span> {{ message }}</p>

    <p v-if="phase === 'chargement'" role="status">Chargement de la liste…</p>

    <section v-else-if="phase === 'vide'" class="space-y-3 rounded-2xl bg-white p-4" data-testid="evac-vide">
      <p class="text-lg font-semibold">Aucune liste n’est conservée sur cet appareil.</p>
      <p>Ouvrez cette page avec du réseau, connecté à votre compte, puis choisissez « Conserver pour l’usage hors ligne ».</p>
    </section>

    <form v-else-if="phase === 'verrouille'" class="space-y-3 rounded-2xl bg-white p-4" data-testid="evac-verrou" @submit.prevent="unlock">
      <p class="text-lg font-semibold">Liste conservée verrouillée. Saisissez votre PIN.</p>
      <label class="block text-lg font-semibold">PIN (6 chiffres)<input v-model="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" class="field !text-2xl" data-testid="evac-pin" required></label>
      <button type="submit" class="btn-main" :disabled="busy" data-testid="evac-deverrouiller">Déverrouiller</button>
    </form>

    <template v-else-if="data">
      <section class="no-print space-y-3" aria-label="Actions">
        <p class="text-lg" data-testid="evenement-liste">{{ data.event.name }}</p>
        <p class="text-2xl font-bold" data-testid="total">{{ data.total }} personne{{ data.total > 1 ? 's' : '' }} présente{{ data.total > 1 ? 's' : '' }}</p>
        <div class="grid gap-3">
          <button type="button" class="btn-main" data-testid="imprimer" @click="print">Exporter pour impression</button>
          <button v-if="auth && online" type="button" class="btn-soft justify-center" :disabled="busy" data-testid="actualiser" @click="refresh">Actualiser</button>
        </div>
        <form v-if="auth && vaultState === 'vide'" class="space-y-3 rounded-2xl border-2 border-ikoma-blue bg-white p-4" data-testid="evac-conserver" @submit.prevent="create">
          <p class="font-semibold">Conserver pour l’usage hors ligne : choisissez un PIN de 6 chiffres. La liste est chiffrée sur cet appareil et effacée à la déconnexion.</p>
          <label class="block text-lg font-semibold">PIN<input v-model="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" class="field !text-2xl" data-testid="evac-pin-creation" required></label>
          <label class="block text-lg font-semibold">Confirmez le PIN<input v-model="pin2" type="password" inputmode="numeric" maxlength="6" autocomplete="new-password" class="field !text-2xl" data-testid="evac-pin-confirmation" required></label>
          <button type="submit" class="btn-main" :disabled="busy" data-testid="evac-conserver-bouton">Conserver pour l’usage hors ligne</button>
        </form>
        <form v-else-if="auth && vaultState === 'verrouille'" class="space-y-3 rounded-2xl border-2 border-slate-300 bg-white p-4" @submit.prevent="unlock">
          <p class="font-semibold">Une copie est conservée sur cet appareil. Saisissez votre PIN pour la mettre à jour.</p>
          <label class="block text-lg font-semibold">PIN<input v-model="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" class="field !text-2xl" data-testid="evac-pin-maj"></label>
          <button type="submit" class="btn-soft justify-center" :disabled="busy || pin.length < 6" data-testid="evac-maj">Mettre à jour la copie</button>
        </form>
      </section>
      <section v-for="zone in data.zones" :key="zone.name" class="zone-sheet space-y-2 rounded-2xl border-2 border-ikoma-navy bg-white p-4" :data-testid="'zone-' + zone.name">
        <h2 class="text-heading font-bold">{{ zone.name }} <span class="rounded-full bg-ikoma-navy px-3 text-white" data-testid="zone-total">{{ zone.count }}</span></h2>
        <p class="print-only text-sm">{{ data.event.name }} · situation connue à {{ dateLabel }} · {{ zone.count }} personne{{ zone.count > 1 ? 's' : '' }} dans cette zone</p>
        <ul class="divide-y divide-slate-200">
          <li v-for="p in zone.people" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-2" data-testid="personne">
            <span><strong>{{ p.full_name }}</strong><br><span class="text-sm">{{ p.organisation || '—' }} · Groupe : {{ p.group || '—' }} · Entrée : {{ time(p.entered_at) }}</span></span>
            <CategorySwatch :name="p.category.name" :color="p.category.color" :priority="p.category.priority_circuit" />
          </li>
        </ul>
      </section>
      <p v-if="!data.zones.length" class="rounded-xl bg-white p-4">Personne n’est présent.</p>
    </template>
  </PilotageLayout>
</template>
