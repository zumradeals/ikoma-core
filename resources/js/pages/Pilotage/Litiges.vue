<script setup lang="ts">
import { reactive } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import { displayDate } from '../../labels';

type Row = { id: string; reason: string; refused_at: string; decision: string | null; supervisor_decision: string | null; visitor_id: string; full_name: string; status: string; category_name: string; color: string };
const props = defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; rows: Row[]; canDecide: boolean }>();
const page = usePage<{ errors: Record<string, string> }>();
const notes = reactive<Record<string, string>>({});
const labels: Record<string, string> = { maintenu: 'Refus maintenu', autorise: 'Autorisé avec conditions', a_revoir: 'À revoir' };
function decide(row: Row, decision: 'maintenu' | 'autorise' | 'a_revoir') { router.post(`/pilotage/litiges/${row.id}/decision`, { decision, note: notes[row.id] ?? '' }, { preserveScroll: true }); }
const todo = () => props.rows.filter(r => !r.decision || r.decision === 'a_revoir');
</script>
<template>
  <PilotageLayout active="litiges" :event="event" :events="events" :open-alerts="openAlerts" title="Cas litigieux">
    <p>Refus d’accès enregistrés par l’accueil. Votre décision est tracée ; si vous autorisez, la personne repasse « attendue » avec la mention de votre autorisation.</p>
    <p v-if="page.props.errors?.pilotage" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900"><span aria-hidden="true">⚠</span> {{ page.props.errors.pilotage }}</p>
    <p role="status" data-testid="a-traiter">{{ todo().length }} cas à traiter</p>
    <ul class="space-y-3" data-testid="liste-litiges">
      <li v-for="row in rows" :key="row.id" class="space-y-2 rounded-2xl border-2 bg-white p-4" :class="!row.decision ? 'border-red-700' : 'border-slate-300'" :data-decision="row.decision ?? 'aucune'">
        <p class="flex flex-wrap items-center justify-between gap-2"><Link :href="'/pilotage/visiteurs/' + row.visitor_id" class="text-xl font-bold underline">{{ row.full_name }}</Link><span class="rounded-full border-2 px-3" :style="{ borderColor: row.color }">{{ row.category_name }}</span></p>
        <p>Motif du refus : {{ row.reason }}</p>
        <p class="text-sm">{{ displayDate(row.refused_at) }}</p>
        <p v-if="row.decision" data-testid="decision"><span aria-hidden="true">{{ row.decision === 'autorise' ? '✓' : row.decision === 'maintenu' ? '✕' : '↻' }}</span> {{ labels[row.decision] }}<template v-if="row.supervisor_decision"> · {{ row.supervisor_decision }}</template></p>
        <template v-if="canDecide && (!row.decision || row.decision === 'a_revoir')">
          <label class="block text-lg font-semibold">Conditions ou remarque<textarea v-model="notes[row.id]" class="field" rows="2" maxlength="500" :data-testid="'note-' + row.id"></textarea></label>
          <div class="grid gap-3">
            <button type="button" class="btn-ok" :disabled="!(notes[row.id] ?? '').trim()" data-testid="autoriser" @click="decide(row, 'autorise')"><span aria-hidden="true">✓</span> Autoriser avec conditions</button>
            <button type="button" class="btn-no" data-testid="maintenir" @click="decide(row, 'maintenu')"><span aria-hidden="true">✕</span> Maintenir le refus</button>
            <button type="button" class="btn-soft justify-center" data-testid="a-revoir" @click="decide(row, 'a_revoir')">À revoir</button>
          </div>
        </template>
      </li>
    </ul>
    <p v-if="!rows.length" class="rounded-xl bg-white p-4">Aucun refus enregistré.</p>
  </PilotageLayout>
</template>
