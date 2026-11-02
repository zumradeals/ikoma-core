<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { toUtcIso } from '../../utils/localDateTime';

type Zone = { id: string; name: string; classification: string | null; permanent_allowed: boolean; offline_entry_allowed: boolean };
type Entitlement = { id: string; holder_id: string; full_name: string; identifier: string; zone_id: string; zone: string; valid_from: string; valid_until: string; revoked_at: string | null; revocation_reason: string | null };
type Decision = { id: string; type: string; point: string; at: string; identifier: string | null; verdict: string | null; reason: string | null; deferred: boolean };
type Device = { id: string; name: string; last_synced_at: string | null; age_minutes: number | null; state: 'aucune_confiance' | 'confiance_expiree' | 'confiance_valide' };
type Holder = { id: string; identifier: string; full_name: string; category_code: string | null; category_label: string | null };
const props = defineProps<{
  events: { id: string; name: string }[]; eventId: string | null; zones: Zone[]; entitlements: Entitlement[]; holders: Holder[];
  categories: { code: string; label: string; color: string }[]; eventDates: { starts_at: string; ends_at: string } | null;
  decisions: Decision[]; devices: Device[]; trust: { configured: number; approved: boolean; effective: number; max: number };
  can: { grant: boolean; policies: boolean; trust: boolean };
}>();

function chooseEvent(e: Event) { router.get('/administration/habilitations', { event: (e.target as HTMLSelectElement).value }); }

// 1. Où peut-on entrer ? Ouvrir/fermer une zone aux personnes permanentes. La « classification » reste enregistrée mais n'est plus demandée : on garde la valeur existante, sinon « Standard ».
function setZone(zone: Zone, open: boolean, offline = zone.offline_entry_allowed) {
  router.put('/administration/habilitations/zones/' + zone.id, { event_id: props.eventId, classification: zone.classification ?? 'Standard', permanent_allowed: open, offline_entry_allowed: open && offline }, { preserveScroll: true });
}
const openZones = computed(() => props.zones.filter(z => z.permanent_allowed));

// 2. Qui ? Recherche, filtre par catégorie et sélection de personnes.
const search = ref('');
const categoryFilter = ref<string>('');
const chosen = ref<string[]>([]);
const norm = (v: string) => v.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const visibleHolders = computed(() => props.holders.filter(h => (categoryFilter.value === '' || h.category_code === categoryFilter.value) && (search.value === '' || norm(h.full_name + ' ' + h.identifier).includes(norm(search.value)))));
const allChosen = computed(() => visibleHolders.value.length > 0 && visibleHolders.value.every(h => chosen.value.includes(h.id)));
function toggleAll(on: boolean) {
  const ids = visibleHolders.value.map(h => h.id);
  chosen.value = on ? [...new Set([...chosen.value, ...ids])] : chosen.value.filter(id => !ids.includes(id));
}

// 3. Où et quand ?
const zonesChosen = ref<string[]>([]);
const wholeEvent = ref(true);
const from = ref('');
const until = ref('');
const grant = useForm({});
const grantErrors = ref<string[]>([]);
function submitGrant() {
  grantErrors.value = [];
  grant.transform(() => ({ event_id: props.eventId, holders: chosen.value, zones: zonesChosen.value, whole_event: wholeEvent.value, valid_from: wholeEvent.value ? null : toUtcIso(from.value), valid_until: wholeEvent.value ? null : toUtcIso(until.value) }))
    .post('/administration/habilitations/groupe', { preserveScroll: true, onSuccess: () => { chosen.value = []; zonesChosen.value = []; }, onError: errors => { grantErrors.value = Object.values(errors); } });
}
const canSubmit = computed(() => chosen.value.length > 0 && zonesChosen.value.length > 0 && (wholeEvent.value || (from.value !== '' && until.value !== '')));

// Accès accordés : une ligne par personne.
const revoking = ref<string | null>(null);
const revokeForm = useForm({ reason: 'Retrait par l’administrateur' });
function submitRevoke(id: string) { revokeForm.post('/administration/habilitations/' + id + '/retirer', { preserveScroll: true, onSuccess: () => { revoking.value = null; } }); }
const byPerson = computed(() => {
  const map = new Map<string, { id: string; name: string; identifier: string; items: Entitlement[] }>();
  for (const e of props.entitlements) {
    const row = map.get(e.holder_id) ?? { id: e.holder_id, name: e.full_name, identifier: e.identifier, items: [] };
    row.items.push(e); map.set(e.holder_id, row);
  }
  return [...map.values()];
});
function status(e: Entitlement) { return e.revoked_at ? 'Retiré' : (Date.parse(e.valid_until) <= Date.now() ? 'Expiré' : (Date.parse(e.valid_from) > Date.now() ? 'À venir' : 'En cours')); }
function periodLabel(e: Entitlement) {
  if (props.eventDates && Date.parse(e.valid_from) === Date.parse(props.eventDates.starts_at) && Date.parse(e.valid_until) === Date.parse(props.eventDates.ends_at)) return 'Tout l’événement';
  return 'Du ' + new Date(e.valid_from).toLocaleDateString('fr-FR') + ' au ' + new Date(e.valid_until).toLocaleDateString('fr-FR');
}

// Réglages avancés : tablettes sans réseau et dernières décisions.
const trustForm = useForm({ minutes: props.trust.configured, approved: props.trust.approved });
function saveTrust() { trustForm.put('/administration/habilitations/confiance', { preserveScroll: true }); }
const stateLabel: Record<Device['state'], string> = { aucune_confiance: 'Aucune confiance hors ligne : nouvelles entrées refusées', confiance_expiree: 'Confiance expirée ou jamais synchronisé : nouvelles entrées refusées', confiance_valide: 'Confiance valide' };
const reasonLabel: Record<string, string> = {
  carte_perdue: 'Carte perdue', carte_volee: 'Carte volée', carte_revoquee: 'Carte révoquée', titulaire_clos: 'Titulaire clos', engagement_termine: 'Engagement terminé', engagement_non_commence: 'Engagement non commencé', habilitation_non_commencee: 'Habilitation pas encore valable',
  evenement_indisponible: 'Événement clos', habilitation_absente: 'Habilitation absente', habilitation_expiree: 'Habilitation expirée', zone_sans_politique: 'Zone sans politique', hors_ligne_non_autorise: 'Hors ligne non autorisé', confiance_expiree: 'Confiance hors ligne expirée',
  perdue: 'Carte perdue', volee: 'Carte volée', revoquee: 'Carte révoquée', active: '',
};
function when(v: string | null) { return v ? new Date(v).toLocaleString('fr-FR') : '—'; }
</script>

<template>
  <AdminLayout active="habilitations">
    <div class="space-y-6" data-testid="page-habilitations">
      <header class="max-w-4xl space-y-3">
        <h1 class="text-display font-bold">Qui peut entrer où ?</h1>
        <p class="text-lg">Une personne permanente entre uniquement dans les zones où vous lui donnez accès. Sa carte et sa catégorie, seules, n’ouvrent rien.</p>
        <label class="block max-w-xl font-semibold">Événement
          <select class="field mt-1" :value="eventId ?? ''" data-testid="choix-evenement" @change="chooseEvent"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </label>
      </header>

      <p v-if="!eventId" class="rounded-xl border bg-white p-5" data-testid="aucun-evenement">Aucun événement ordinaire : créez d’abord un événement et ses zones.</p>
      <template v-else>
        <section class="space-y-4 rounded-2xl border bg-white p-6" aria-labelledby="etape-zones" data-testid="etape-zones">
          <h2 id="etape-zones" class="flex items-center gap-3 text-heading font-bold"><span class="grid size-10 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">1</span>Où peut-on entrer ?</h2>
          <p>Une zone fermée refuse toujours les personnes permanentes. Ouvrez seulement celles qui le doivent.</p>
          <p v-if="zones.length === 0" data-testid="aucune-zone">Cet événement n’a pas encore de zone.</p>
          <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <li v-for="z in zones" :key="z.id" class="flex flex-col gap-2 rounded-xl border-2 p-4" :class="z.permanent_allowed ? 'border-green-700' : 'border-slate-300'" :data-testid="'zone-' + z.id">
              <strong class="text-lg">{{ z.name }}</strong>
              <span class="self-start rounded-full px-3 py-1 text-sm font-bold" :class="z.permanent_allowed ? 'bg-green-100 text-green-900' : 'bg-amber-100 text-amber-900'" :data-testid="'etat-zone-' + z.id">{{ z.permanent_allowed ? 'Ouverte' : 'Fermée' }}</span>
              <button v-if="can.policies" type="button" class="btn-soft self-start" :data-testid="'bascule-zone-' + z.id" @click="setZone(z, !z.permanent_allowed)">{{ z.permanent_allowed ? 'Fermer' : 'Ouvrir aux permanents' }}</button>
              <label v-if="z.permanent_allowed" class="flex items-center gap-2 text-sm"><input type="checkbox" class="size-5" :checked="z.offline_entry_allowed" :disabled="!can.policies" :data-testid="'horsligne-' + z.id" @change="setZone(z, true, ($event.target as HTMLInputElement).checked)"> Accepter aussi sans réseau</label>
            </li>
          </ul>
        </section>

        <div v-if="can.grant" class="flex flex-wrap items-start gap-6">
          <section class="min-w-0 flex-[1_1_32rem] space-y-4 rounded-2xl border bg-white p-6" aria-labelledby="etape-qui" data-testid="etape-qui">
            <h2 id="etape-qui" class="flex items-center gap-3 text-heading font-bold"><span class="grid size-10 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">2</span>Qui ?</h2>
            <label class="block font-semibold">Chercher une personne<input v-model="search" type="search" class="field mt-1" placeholder="Nom ou identifiant" data-testid="chercher-personne"></label>
            <div role="group" aria-label="Filtrer par catégorie" class="flex flex-wrap gap-2">
              <button type="button" class="rounded-full border-2 px-4 py-2 font-bold" :class="categoryFilter === '' ? 'border-[#0B3C6B] bg-[#0B3C6B] text-white' : 'border-slate-300'" :aria-pressed="categoryFilter === ''" data-testid="filtre-toutes" @click="categoryFilter = ''">Toutes</button>
              <button v-for="c in categories" :key="c.code" type="button" class="rounded-full border-2 px-4 py-2 font-semibold" :class="categoryFilter === c.code ? 'border-[#0B3C6B] bg-[#0B3C6B] text-white' : 'border-slate-300'" :aria-pressed="categoryFilter === c.code" :data-testid="'filtre-' + c.code" @click="categoryFilter = c.code"><span class="mr-2 inline-block size-3 rounded-full" :style="{ background: c.color }" aria-hidden="true"></span>{{ c.label }}</button>
            </div>
            <label class="flex items-center gap-3 font-bold"><input type="checkbox" class="size-6" :checked="allChosen" data-testid="tout-selectionner" @change="toggleAll(($event.target as HTMLInputElement).checked)"> Tout sélectionner ({{ visibleHolders.length }} personne(s))</label>
            <p v-if="holders.length === 0" data-testid="aucune-personne">Aucune personne permanente active : ajoutez-en dans « Personnes et cartes permanentes ».</p>
            <ul class="max-h-[28rem] space-y-2 overflow-y-auto">
              <li v-for="h in visibleHolders" :key="h.id">
                <label class="flex items-center gap-4 rounded-xl border-2 p-3" :class="chosen.includes(h.id) ? 'border-[#0066B3] bg-blue-50' : 'border-slate-200'"><input v-model="chosen" type="checkbox" :value="h.id" class="size-6" :data-testid="'personne-' + h.identifier">
                  <span><strong>{{ h.full_name }}</strong><br><span class="text-sm text-slate-700">{{ h.identifier }}<span v-if="h.category_label"> · {{ h.category_label }}</span></span></span></label>
              </li>
            </ul>
          </section>

          <section class="min-w-0 flex-[1_1_22rem] space-y-4 rounded-2xl border bg-white p-6" aria-labelledby="etape-ou" data-testid="etape-ou">
            <h2 id="etape-ou" class="flex items-center gap-3 text-heading font-bold"><span class="grid size-10 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">3</span>Où et quand ?</h2>
            <fieldset class="space-y-2">
<legend class="mb-2 font-bold">Zones ouvertes</legend>
              <p v-if="openZones.length === 0" class="text-sm" data-testid="aucune-zone-ouverte">Aucune zone n’est ouverte : ouvrez d’abord une zone (étape 1).</p>
              <label v-for="z in openZones" :key="z.id" class="flex items-center gap-3 text-lg"><input v-model="zonesChosen" type="checkbox" :value="z.id" class="size-6" :data-testid="'choisir-zone-' + z.id"> {{ z.name }}</label>
            </fieldset>
            <fieldset class="space-y-2">
<legend class="mb-2 font-bold">Période</legend>
              <label class="flex items-center gap-3 text-lg"><input v-model="wholeEvent" type="radio" :value="true" class="size-6" data-testid="periode-evenement"> Pendant tout l’événement</label>
              <label class="flex items-center gap-3 text-lg"><input v-model="wholeEvent" type="radio" :value="false" class="size-6" data-testid="periode-dates"> Dates précises</label>
              <div v-if="!wholeEvent" class="grid gap-3 sm:grid-cols-2">
                <label class="font-semibold">Du<input v-model="from" type="datetime-local" class="field mt-1" data-testid="hab-debut"></label>
                <label class="font-semibold">Au<input v-model="until" type="datetime-local" class="field mt-1" data-testid="hab-fin"></label>
              </div>
            </fieldset>
            <p v-for="message in grantErrors" :key="message" class="font-semibold text-red-800" role="alert">{{ message }}</p>
            <button type="button" class="admin-primary w-full text-lg" :disabled="!canSubmit || grant.processing" data-testid="hab-accorder" @click="submitGrant">Donner l’accès à {{ chosen.length }} personne(s)</button>
            <p class="text-sm text-slate-700">Chaque personne reçoit son propre accès : vous pourrez le retirer individuellement.</p>
          </section>
        </div>

        <section class="space-y-4 rounded-2xl border bg-white p-6" aria-labelledby="acces-accordes" data-testid="acces-accordes">
          <h2 id="acces-accordes" class="text-heading font-bold">Accès accordés</h2>
          <p v-if="byPerson.length === 0" data-testid="aucune-habilitation">Aucun accès pour cet événement : aucune personne permanente ne peut y entrer.</p>
          <ul class="space-y-3" data-testid="liste-habilitations">
            <li v-for="p in byPerson" :key="p.id" class="space-y-2 rounded-xl border p-4" :data-testid="'acces-' + p.identifier">
              <p><strong>{{ p.name }}</strong> <span class="font-mono text-sm">{{ p.identifier }}</span></p>
              <ul class="space-y-2">
                <li v-for="e in p.items" :key="e.id" class="flex flex-wrap items-center gap-3" :data-testid="'hab-' + e.id">
                  <span class="rounded-full bg-blue-100 px-3 py-1 font-semibold text-blue-950">{{ e.zone }}</span>
                  <span class="text-slate-700">{{ periodLabel(e) }}</span>
                  <span class="rounded px-2 py-1 text-sm font-bold" :class="status(e) === 'En cours' ? 'bg-green-100 text-green-900' : 'bg-slate-200 text-slate-800'">{{ status(e) }}</span>
                  <span v-if="e.revoked_at" class="text-sm">retiré : {{ e.revocation_reason }}</span>
                  <template v-if="can.grant && !e.revoked_at">
                    <button v-if="revoking !== e.id" type="button" class="rounded-lg border-2 border-red-800 px-3 py-1 font-bold text-red-800" :data-testid="'retirer-' + e.id" @click="revoking = e.id">Retirer</button>
                    <form v-else class="flex flex-wrap items-end gap-2" @submit.prevent="submitRevoke(e.id)">
                      <label class="text-sm font-semibold">Motif du retrait<input v-model="revokeForm.reason" class="field mt-1" required maxlength="200" data-testid="motif-retrait"></label>
                      <button type="submit" class="admin-primary" data-testid="confirmer-retrait">Confirmer</button><button type="button" class="btn-soft" @click="revoking = null">Annuler</button>
                      <span v-if="revokeForm.errors.reason" class="font-semibold text-red-800" role="alert">{{ revokeForm.errors.reason }}</span>
                    </form>
                  </template>
                </li>
              </ul>
            </li>
          </ul>
        </section>

        <details class="rounded-2xl border bg-white" data-testid="reglages-avances">
          <summary class="flex cursor-pointer items-center justify-between p-6 text-xl font-bold"><span>Réglages avancés (tablettes sans réseau, dernières décisions)</span><span aria-hidden="true">+</span></summary>
          <div class="space-y-6 p-6 pt-0">
            <section class="space-y-3" aria-label="Tablettes sans réseau">
              <p class="text-sm" data-testid="confiance-resume">Durée actuelle : <strong>{{ trust.effective > 0 ? trust.effective + ' min' : 'aucune' }}</strong>. {{ trust.effective > 0 ? '' : 'Sans durée approuvée, toute nouvelle entrée d’une personne permanente est refusée quand la tablette n’a pas de réseau. Les sorties restent toujours possibles.' }}</p>
              <form v-if="can.trust" class="flex flex-wrap items-end gap-3" @submit.prevent="saveTrust">
                <label class="font-semibold">Une tablette sans réseau peut accepter les entrées pendant (minutes, 0 à {{ trust.max }})<input v-model.number="trustForm.minutes" type="number" min="0" :max="trust.max" class="field mt-1" data-testid="confiance-minutes"></label>
                <label class="flex items-center gap-2 font-semibold"><input v-model="trustForm.approved" type="checkbox" class="size-6" data-testid="confiance-approuvee"> Approuvée par l’institution</label>
                <button type="submit" class="admin-primary" data-testid="confiance-enregistrer">Enregistrer</button>
                <span v-for="(message, key) in trustForm.errors" :key="key" class="font-semibold text-red-800" role="alert">{{ message }}</span>
              </form>
              <ul class="space-y-2" data-testid="liste-terminaux">
                <li v-for="d in devices" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3" :data-testid="'terminal-' + d.id">
                  <span><strong>{{ d.name }}</strong> · dernière synchronisation : {{ d.age_minutes === null ? 'jamais' : 'il y a ' + d.age_minutes + ' min' }}</span>
                  <span class="rounded px-2 py-1 text-sm font-bold" :class="d.state === 'confiance_valide' ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-900'" :data-testid="'etat-' + d.id">{{ stateLabel[d.state] }}</span>
                </li>
                <li v-if="devices.length === 0">Aucune tablette enrôlée pour cet événement.</li>
              </ul>
            </section>
            <section class="space-y-3" aria-label="Dernières décisions">
              <h3 class="text-lg font-bold">Dernières décisions</h3>
              <p v-if="decisions.length === 0" data-testid="aucune-decision">Aucune décision pour les personnes permanentes.</p>
              <table v-else class="w-full text-left text-sm" data-testid="table-decisions">
                <thead><tr><th class="p-2">Heure</th><th class="p-2">Personne</th><th class="p-2">Point</th><th class="p-2">Décision</th><th class="p-2">Motif</th></tr></thead>
                <tbody>
                  <tr v-for="d in decisions" :key="d.id" class="border-t" :data-testid="'decision-' + d.id">
                    <td class="p-2">{{ when(d.at) }}<span v-if="d.deferred" class="ml-1 rounded bg-amber-100 px-1">hors ligne</span></td><td class="p-2 font-mono">{{ d.identifier }}</td><td class="p-2">{{ d.point }}</td>
                    <td class="p-2 font-semibold">{{ d.type === 'entree' ? 'Entrée' : d.type === 'sortie' ? 'Sortie' : d.verdict === 'valide' ? 'Contrôle valide' : 'Refus' }}</td><td class="p-2">{{ d.reason ? (reasonLabel[d.reason] ?? d.reason) : '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </section>
          </div>
        </details>
      </template>
    </div>
  </AdminLayout>
</template>
