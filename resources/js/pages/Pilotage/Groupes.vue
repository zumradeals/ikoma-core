<script setup lang="ts">
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import { usePolling } from '../../pilotage/usePolling';

type Group = { id: string; name: string; bus_number: string | null; total: number; badges_remis: number; embarques: number; arrives: number; absents: number; absents_depart: number; ecarts: number; parti: boolean };
defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; groups: Group[] }>();
usePolling(['groups', 'openAlerts'], 5000);
const cells = (g: Group) => [['Badges remis', g.badges_remis], ['Embarqués', g.embarques], ['Arrivés', g.arrives], ['Absents au départ', g.absents_depart]] as const;
</script>
<template>
  <PilotageLayout active="plus" :event="event" :events="events" :open-alerts="openAlerts" title="Groupes et cars">
    <p>Lecture seule : l’avancement de chaque car, de la remise des badges à l’arrivée sur le site.</p>
    <ul class="space-y-3" data-testid="liste-groupes">
      <li v-for="g in groups" :key="g.id" class="space-y-2 rounded-2xl border-2 border-ikoma-navy bg-white p-4">
        <p class="text-xl font-bold">{{ g.name }}<span v-if="g.bus_number" class="font-normal"> · car {{ g.bus_number }}</span></p>
        <p>{{ g.total }} personne{{ g.total > 1 ? 's' : '' }} · {{ g.parti ? 'car parti' : 'car pas encore parti' }}</p>
        <p v-if="g.ecarts" class="rounded-xl border-2 border-amber-700 bg-amber-50 p-2 font-semibold text-amber-950" data-testid="ecarts-groupe"><span aria-hidden="true">⚠</span> {{ g.ecarts }} écart{{ g.ecarts > 1 ? 's' : '' }} à traiter : voir les alertes.</p>
        <dl class="grid grid-cols-2 gap-2">
          <div v-for="c in cells(g)" :key="c[0]" class="rounded-xl bg-slate-100 p-3"><dt class="text-sm font-semibold">{{ c[0] }}</dt><dd class="text-2xl font-extrabold">{{ c[1] }} <span class="text-base font-normal">/ {{ g.total }}</span></dd></div>
        </dl>
      </li>
    </ul>
    <p v-if="!groups.length" class="rounded-xl bg-white p-4" data-testid="aucun-groupe">Aucun groupe pour cet événement.</p>
  </PilotageLayout>
</template>
