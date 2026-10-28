<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import AlertCard, { type AlertRow } from '../../components/pilotage/AlertCard.vue';
import { usePolling } from '../../pilotage/usePolling';

defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; state: 'a_traiter' | 'prises'; alerts: AlertRow[] }>();
usePolling(['alerts', 'openAlerts'], 3000);
</script>
<template>
  <PilotageLayout active="alertes" :event="event" :events="events" :open-alerts="openAlerts" title="Alertes">
    <div class="grid grid-cols-2 gap-3" role="group" aria-label="État">
      <Link href="/pilotage/alertes" class="flex min-h-14 items-center justify-center rounded-xl border-2 border-ikoma-navy px-3 text-lg font-bold" :class="state === 'a_traiter' ? 'bg-ikoma-navy text-white' : 'bg-white'" :aria-current="state === 'a_traiter' ? 'true' : undefined">À traiter</Link>
      <Link href="/pilotage/alertes?etat=prises" class="flex min-h-14 items-center justify-center rounded-xl border-2 border-ikoma-navy px-3 text-lg font-bold" :class="state === 'prises' ? 'bg-ikoma-navy text-white' : 'bg-white'" :aria-current="state === 'prises' ? 'true' : undefined">Prises en compte</Link>
    </div>
    <ul v-if="alerts.length" class="space-y-3" data-testid="liste-alertes"><AlertCard v-for="a in alerts" :key="a.id" :alert="a" /></ul>
    <p v-else class="rounded-xl bg-white p-4" data-testid="aucune-alerte">{{ state === 'a_traiter' ? 'Aucune alerte à traiter.' : 'Aucune alerte prise en compte.' }}</p>
  </PilotageLayout>
</template>
