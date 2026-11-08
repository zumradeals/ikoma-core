<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import CategorySwatch from '../../components/CategorySwatch.vue';
import { pilotageHref } from '../../pilotage/navigation';
import { usePolling } from '../../pilotage/usePolling';
import { displayDate } from '../../labels';

type Row = { id: string; full_name: string; organisation: string | null; expected_at: string; announced_at: string; state: string; no_show_reason: string | null; category: { name: string; color: string; priority_circuit: boolean }; host: string | null; plates: string | null };
type Vehicle = { id: string; plate: string; kind: string; entered_at: string; visitor_id: string; full_name: string };
const props = defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; expected: { rows: Row[]; counts: Record<string, number>; late_after_minutes: number }; vehicles: Vehicle[]; state: string | null; permissions: { absence: boolean } }>();
usePolling(['expected', 'vehicles', 'openAlerts', 'criticalAlerts'], 10000);
const page = usePage<{ errors: Record<string, string> }>();
const states: Record<string, string> = { attendu: 'Attendu', en_retard: 'En retard', absent: 'Absent (constaté)', present: 'Présent', sorti: 'Sorti', refuse: 'Accès refusé' };
const filters = [['', 'Toutes'], ['attendu', 'Attendues'], ['en_retard', 'En retard'], ['absent', 'Absentes'], ['present', 'Présentes'], ['sorti', 'Sorties']] as const;
const kinds: Record<string, string> = { voiture: 'Voiture', moto: 'Moto', minibus: 'Minibus', camion: 'Camion', autre: 'Autre' };
const absentFor = ref<string | null>(null); const reason = ref(''); const busy = ref(false);
const chip = (state: string) => (state === 'en_retard' ? 'border-orange-600 bg-orange-50 text-orange-950' : state === 'absent' ? 'border-red-700 bg-red-50 text-red-900' : 'border-slate-400 bg-slate-100');
function filter(value: string) { router.get('/pilotage/attendus', { event: props.event?.id, etat: value || undefined }, { preserveState: true }); }
function confirmAbsence(id: string) {
  busy.value = true;
  router.post(`/pilotage/visiteurs/${id}/absence`, { reason: reason.value }, { preserveScroll: true, onSuccess: () => { absentFor.value = null; reason.value = ''; }, onFinish: () => { busy.value = false; } });
}
</script>
<template>
  <PilotageLayout active="attendus" :event="event" :events="events" :open-alerts="openAlerts" title="Visites annoncées">
    <p>Personnes dont la visite est annoncée. Une visite annoncée n’est pas une présence : la personne est présente seulement après son entrée à l’accueil. Une personne est « en retard » {{ expected.late_after_minutes }} minute{{ expected.late_after_minutes > 1 ? 's' : '' }} après l’heure attendue.</p>
    <dl class="grid grid-cols-2 gap-2 sm:grid-cols-3" data-testid="compteurs-attendus">
      <div v-for="(label, key) in states" :key="key" class="rounded-xl bg-slate-100 p-3"><dt class="text-sm font-semibold">{{ label }}</dt><dd class="text-2xl font-extrabold">{{ expected.counts[key] ?? 0 }}</dd></div>
    </dl>
    <div class="flex flex-wrap gap-2" role="group" aria-label="Filtrer les visites">
      <button v-for="f in filters" :key="f[0]" type="button" class="btn-soft !w-auto" :aria-pressed="(state ?? '') === f[0]" :data-testid="'filtre-' + (f[0] || 'toutes')" @click="filter(f[0])">{{ f[1] }}</button>
    </div>
    <p v-if="page.props.errors?.reason || page.props.errors?.participation" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-absence"><span aria-hidden="true">⚠</span> {{ page.props.errors.reason ?? page.props.errors.participation }}</p>
    <ul class="pilotage-record-grid" data-testid="liste-attendus">
      <li v-for="r in expected.rows" :key="r.id" class="space-y-2 rounded-2xl border-2 border-ikoma-navy bg-white p-4" :data-testid="'attendu-' + r.state">
        <p class="text-xl font-bold"><Link :href="pilotageHref('/pilotage/visiteurs/' + r.id, event?.id)" class="underline">{{ r.full_name }}</Link></p>
        <CategorySwatch :name="r.category.name" :color="r.category.color" :priority="r.category.priority_circuit" />
        <p>{{ r.organisation || 'Organisation non indiquée' }} · Reçu par : {{ r.host || '—' }}</p>
        <p>Attendu : <strong>{{ displayDate(r.expected_at) }}</strong></p>
        <p><span class="inline-block rounded-full border-2 px-3 py-1 font-semibold" :class="chip(r.state)" data-testid="etat-attendu">{{ states[r.state] ?? r.state }}</span></p>
        <p v-if="r.plates" data-testid="plaques-attendu">Véhicule(s) : {{ r.plates }}</p>
        <p v-if="r.no_show_reason" data-testid="motif-absence">Motif de l’absence : {{ r.no_show_reason }}</p>
        <template v-if="permissions.absence && (r.state === 'attendu' || r.state === 'en_retard')">
          <button v-if="absentFor !== r.id" type="button" class="btn-soft" data-testid="absence-debut" @click="absentFor = r.id; reason = ''">Constater l’absence</button>
          <div v-else class="space-y-2 rounded-xl border-2 border-slate-300 p-3">
            <label class="block text-lg font-semibold">Motif de l’absence (obligatoire)<textarea v-model="reason" class="field" rows="2" maxlength="200" data-testid="absence-motif"></textarea></label>
            <div class="flex flex-wrap gap-3">
              <button type="button" class="btn-no !w-auto" :disabled="busy || !reason.trim()" data-testid="absence-confirmer" @click="confirmAbsence(r.id)">Enregistrer l’absence</button>
              <button type="button" class="btn-soft" @click="absentFor = null">Retour</button>
            </div>
          </div>
        </template>
      </li>
    </ul>
    <p v-if="!expected.rows.length" class="rounded-xl bg-white p-4" data-testid="aucun-attendu">Aucune visite annoncée pour cette sélection.</p>
    <section class="space-y-2 rounded-2xl border-2 border-slate-300 bg-white p-4" aria-label="Véhicules présents">
      <h2 class="text-heading font-bold">Véhicules présents sur le site ({{ vehicles.length }})</h2>
      <p class="text-sm">Un véhicule présent n’indique pas que la personne est présente : seule son entrée à l’accueil ou au contrôle le fait.</p>
      <ul class="space-y-1" data-testid="liste-vehicules">
        <li v-for="x in vehicles" :key="x.id"><strong>{{ x.plate }}</strong> · {{ kinds[x.kind] ?? x.kind }} · {{ x.full_name }} · depuis {{ displayDate(x.entered_at) }}</li>
      </ul>
      <p v-if="!vehicles.length" data-testid="aucun-vehicule">Aucun véhicule présent.</p>
    </section>
  </PilotageLayout>
</template>
