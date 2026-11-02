<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { toUtcIso } from '../../utils/localDateTime';

type Zone = { id: string; name: string; classification: string | null; permanent_allowed: boolean; offline_entry_allowed: boolean };
type Entitlement = { id: string; holder_id: string; full_name: string; identifier: string; zone_id: string; zone: string; valid_from: string; valid_until: string; revoked_at: string | null; revocation_reason: string | null };
type Decision = { id: string; type: string; point: string; at: string; identifier: string | null; verdict: string | null; reason: string | null; deferred: boolean };
type Device = { id: string; name: string; last_synced_at: string | null; age_minutes: number | null; state: 'aucune_confiance' | 'confiance_expiree' | 'confiance_valide' };
const props = defineProps<{
  events: { id: string; name: string }[]; eventId: string | null; zones: Zone[]; entitlements: Entitlement[]; holders: { id: string; identifier: string; full_name: string }[];
  decisions: Decision[]; devices: Device[]; trust: { configured: number; approved: boolean; effective: number; max: number };
  can: { grant: boolean; policies: boolean; trust: boolean };
}>();

function chooseEvent(e: Event) { router.get('/administration/habilitations', { event: (e.target as HTMLSelectElement).value }); }
const grant = useForm({ holder_id: '', event_id: props.eventId ?? '', zone_id: '', valid_from: '', valid_until: '' });
function submitGrant() {
  grant.event_id = props.eventId ?? '';
  // Les champs sont en heure locale : le serveur reçoit des dates UTC explicites.
  grant.transform(data => ({ ...data, valid_from: toUtcIso(data.valid_from), valid_until: toUtcIso(data.valid_until) })).post('/administration/habilitations', { preserveScroll: true, onSuccess: () => grant.reset('holder_id', 'zone_id', 'valid_from', 'valid_until') }); }
const revoking = ref<string | null>(null);
const revokeForm = useForm({ reason: '' });
function submitRevoke(id: string) { revokeForm.post('/administration/habilitations/' + id + '/retirer', { preserveScroll: true, onSuccess: () => { revoking.value = null; revokeForm.reset(); } }); }
const policies = ref<Record<string, { classification: string; permanent_allowed: boolean; offline_entry_allowed: boolean }>>(Object.fromEntries(props.zones.map(z => [z.id, { classification: z.classification ?? '', permanent_allowed: z.permanent_allowed, offline_entry_allowed: z.offline_entry_allowed }])));
function savePolicy(zone: Zone) { router.put('/administration/habilitations/zones/' + zone.id, { event_id: props.eventId, ...policies.value[zone.id] }, { preserveScroll: true }); }
const trustForm = useForm({ minutes: props.trust.configured, approved: props.trust.approved });
function saveTrust() { trustForm.put('/administration/habilitations/confiance', { preserveScroll: true }); }
const stateLabel: Record<Device['state'], string> = { aucune_confiance: 'Aucune confiance hors ligne : nouvelles entrées refusées', confiance_expiree: 'Confiance expirée ou jamais synchronisé : nouvelles entrées refusées', confiance_valide: 'Confiance valide' };
const reasonLabel: Record<string, string> = {
  carte_perdue: 'Carte perdue', carte_volee: 'Carte volée', carte_revoquee: 'Carte révoquée', titulaire_clos: 'Titulaire clos', engagement_termine: 'Engagement terminé', engagement_non_commence: 'Engagement non commencé',
  evenement_indisponible: 'Événement clos', habilitation_absente: 'Habilitation absente', habilitation_expiree: 'Habilitation expirée', zone_sans_politique: 'Zone sans politique', hors_ligne_non_autorise: 'Hors ligne non autorisé', confiance_expiree: 'Confiance hors ligne expirée',
  perdue: 'Carte perdue', volee: 'Carte volée', revoquee: 'Carte révoquée', active: '',
};
function when(v: string | null) { return v ? new Date(v).toLocaleString('fr-FR') : '—'; }
function status(e: Entitlement) { return e.revoked_at ? 'Retirée' : (Date.parse(e.valid_until) <= Date.now() ? 'Expirée' : (Date.parse(e.valid_from) > Date.now() ? 'À venir' : 'En cours')); }
</script>

<template>
  <AdminLayout active="habilitations">
    <div class="space-y-6" data-testid="page-habilitations">
      <header class="space-y-2">
        <h1 class="text-display font-bold">Habilitations et zones</h1>
        <p>Un titulaire permanent n’entre que dans les zones pour lesquelles il est explicitement habilité, pour la période indiquée, et si la zone accepte les titulaires permanents. <strong>Sa catégorie AILCT et la possession de sa carte ne donnent aucun accès.</strong> Une zone sans politique refuse les titulaires permanents.</p>
        <label class="block max-w-xl font-semibold">Événement
          <select class="field mt-1" :value="eventId ?? ''" data-testid="choix-evenement" @change="chooseEvent"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </label>
      </header>

      <p v-if="!eventId" class="rounded-xl border bg-white p-5" data-testid="aucun-evenement">Aucun événement ordinaire : créez d’abord un événement et ses zones.</p>
      <template v-else>
        <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Politiques de zone">
          <h2 class="text-heading font-bold">Zones et classification</h2>
          <p class="text-sm">Les classifications sont celles que l’institution définit : aucune valeur officielle n’est fournie. Une zone n’accepte les titulaires permanents que si vous le cochez.</p>
          <p v-if="zones.length === 0" data-testid="aucune-zone">Cet événement n’a pas encore de zone.</p>
          <div v-for="z in zones" :key="z.id" class="grid gap-3 rounded-lg border p-3 md:grid-cols-[1fr_1fr_auto_auto_auto]" :data-testid="'zone-' + z.id">
            <p class="self-center font-bold">{{ z.name }} <span v-if="!z.classification" class="rounded bg-amber-100 px-2 py-0.5 text-sm text-amber-900" data-testid="zone-sans-politique">Sans politique : refus</span></p>
            <label class="font-semibold">Classification<input v-model="policies[z.id].classification" class="field mt-1" maxlength="40" :disabled="!can.policies" :data-testid="'classification-' + z.id"></label>
            <label class="flex items-center gap-2 self-end font-semibold"><input v-model="policies[z.id].permanent_allowed" type="checkbox" class="size-6" :disabled="!can.policies" :data-testid="'permanents-' + z.id"> Titulaires permanents admis</label>
            <label class="flex items-center gap-2 self-end font-semibold"><input v-model="policies[z.id].offline_entry_allowed" type="checkbox" class="size-6" :disabled="!can.policies || !policies[z.id].permanent_allowed" :data-testid="'horsligne-' + z.id"> Entrée hors ligne admise</label>
            <button v-if="can.policies" type="button" class="btn-soft self-end" :data-testid="'politique-' + z.id" @click="savePolicy(z)">Enregistrer</button>
          </div>
        </section>

        <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Habilitations">
          <h2 class="text-heading font-bold">Habilitations par zone</h2>
          <form v-if="can.grant && zones.length" class="grid gap-3 md:grid-cols-5" @submit.prevent="submitGrant">
            <label class="font-semibold">Titulaire<select v-model="grant.holder_id" class="field mt-1" required data-testid="hab-titulaire"><option value="" disabled>Choisir…</option><option v-for="h in holders" :key="h.id" :value="h.id">{{ h.full_name }} ({{ h.identifier }})</option></select></label>
            <label class="font-semibold">Zone<select v-model="grant.zone_id" class="field mt-1" required data-testid="hab-zone"><option value="" disabled>Choisir…</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option></select></label>
            <label class="font-semibold">Du<input v-model="grant.valid_from" type="datetime-local" class="field mt-1" required data-testid="hab-debut"></label>
            <label class="font-semibold">Au<input v-model="grant.valid_until" type="datetime-local" class="field mt-1" required data-testid="hab-fin"></label>
            <button type="submit" class="admin-primary self-end" :disabled="grant.processing" data-testid="hab-accorder">Accorder</button>
            <p v-for="(message, key) in grant.errors" :key="key" class="font-semibold text-red-800 md:col-span-5" role="alert">{{ message }}</p>
          </form>
          <p v-if="entitlements.length === 0" data-testid="aucune-habilitation">Aucune habilitation pour cet événement : aucun titulaire permanent ne peut y entrer.</p>
          <ul class="space-y-2" data-testid="liste-habilitations">
            <li v-for="e in entitlements" :key="e.id" class="rounded-lg border p-3" :data-testid="'hab-' + e.id">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p><strong>{{ e.full_name }}</strong> <span class="font-mono text-sm">{{ e.identifier }}</span> · {{ e.zone }}</p>
                <span class="rounded px-2 py-1 text-sm font-bold" :class="status(e) === 'En cours' ? 'bg-green-100 text-green-900' : 'bg-slate-200 text-slate-800'">{{ status(e) }}</span>
              </div>
              <p class="text-sm">{{ when(e.valid_from) }} → {{ when(e.valid_until) }}<span v-if="e.revoked_at"> · retirée le {{ when(e.revoked_at) }} : {{ e.revocation_reason }}</span></p>
              <template v-if="can.grant && !e.revoked_at">
                <button v-if="revoking !== e.id" type="button" class="btn-soft mt-2" :data-testid="'retirer-' + e.id" @click="revoking = e.id">Retirer</button>
                <form v-else class="mt-2 flex flex-wrap items-end gap-3" @submit.prevent="submitRevoke(e.id)">
                  <label class="font-semibold">Motif du retrait<input v-model="revokeForm.reason" class="field mt-1" required maxlength="200" data-testid="motif-retrait"></label>
                  <button type="submit" class="admin-primary" data-testid="confirmer-retrait">Confirmer le retrait</button><button type="button" class="btn-soft" @click="revoking = null">Annuler</button>
                  <span v-if="revokeForm.errors.reason" class="font-semibold text-red-800" role="alert">{{ revokeForm.errors.reason }}</span>
                </form>
              </template>
            </li>
          </ul>
        </section>

        <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Terminaux et confiance hors ligne">
          <h2 class="text-heading font-bold">Terminaux et confiance hors ligne</h2>
          <p class="text-sm" data-testid="confiance-resume">Confiance hors ligne effective : <strong>{{ trust.effective > 0 ? trust.effective + ' min' : 'aucune' }}</strong>. {{ trust.effective > 0 ? '' : 'Sans durée approuvée par l’institution, toute nouvelle entrée de titulaire permanent hors ligne est refusée. Les sorties restent toujours possibles.' }}</p>
          <form v-if="can.trust" class="flex flex-wrap items-end gap-3" @submit.prevent="saveTrust">
            <label class="font-semibold">Durée maximale (minutes, 0 à {{ trust.max }})<input v-model.number="trustForm.minutes" type="number" min="0" :max="trust.max" class="field mt-1" data-testid="confiance-minutes"></label>
            <label class="flex items-center gap-2 font-semibold"><input v-model="trustForm.approved" type="checkbox" class="size-6" data-testid="confiance-approuvee"> Approuvée par l’institution</label>
            <button type="submit" class="admin-primary" data-testid="confiance-enregistrer">Enregistrer</button>
            <span v-for="(message, key) in trustForm.errors" :key="key" class="font-semibold text-red-800" role="alert">{{ message }}</span>
          </form>
          <ul class="space-y-2" data-testid="liste-terminaux">
            <li v-for="d in devices" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3" :data-testid="'terminal-' + d.id">
              <span><strong>{{ d.name }}</strong> · dernière synchronisation : {{ d.age_minutes === null ? 'jamais' : 'il y a ' + d.age_minutes + ' min' }}</span>
              <span class="rounded px-2 py-1 text-sm font-bold" :class="d.state === 'confiance_valide' ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-900'" :data-testid="'etat-' + d.id">{{ stateLabel[d.state] }}</span>
            </li>
            <li v-if="devices.length === 0">Aucun terminal enrôlé pour cet événement.</li>
          </ul>
        </section>

        <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Décisions d’accès">
          <h2 class="text-heading font-bold">Décisions d’accès récentes</h2>
          <p v-if="decisions.length === 0" data-testid="aucune-decision">Aucune décision pour les titulaires permanents.</p>
          <table v-else class="w-full text-left text-sm" data-testid="table-decisions">
            <thead><tr><th class="p-2">Heure</th><th class="p-2">Titulaire</th><th class="p-2">Point</th><th class="p-2">Décision</th><th class="p-2">Motif</th></tr></thead>
            <tbody>
<tr v-for="d in decisions" :key="d.id" class="border-t" :data-testid="'decision-' + d.id">
<td class="p-2">{{ when(d.at) }}<span v-if="d.deferred" class="ml-1 rounded bg-amber-100 px-1">hors ligne</span></td><td class="p-2 font-mono">{{ d.identifier }}</td><td class="p-2">{{ d.point }}</td>
              <td class="p-2 font-semibold">{{ d.type === 'entree' ? 'Entrée' : d.type === 'sortie' ? 'Sortie' : d.verdict === 'valide' ? 'Contrôle valide' : 'Refus' }}</td><td class="p-2">{{ d.reason ? (reasonLabel[d.reason] ?? d.reason) : '—' }}</td>
</tr>
</tbody>
          </table>
        </section>
      </template>
    </div>
  </AdminLayout>
</template>
