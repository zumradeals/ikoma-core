<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import AlertCard, { type AlertRow } from '../../components/pilotage/AlertCard.vue';
import CancelBadge from '../../components/pilotage/CancelBadge.vue';
import { usePolling } from '../../pilotage/usePolling';

type Counters = { attendus: number; arrives: number; presents: number; refuses: number; as_of: string };
const props = defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; counters: Counters | null; alerts: AlertRow[] }>();
usePolling(['counters', 'alerts', 'openAlerts'], 3000);
const tiles = computed(() => [
  { key: 'attendus', label: 'Attendus', icon: '☰', value: props.counters?.attendus }, { key: 'arrives', label: 'Arrivés', icon: '➜', value: props.counters?.arrives },
  { key: 'presents', label: 'Présents', icon: '●', value: props.counters?.presents }, { key: 'refuses', label: 'Refusés', icon: '✕', value: props.counters?.refuses },
]);
const updated = computed(() => (props.counters ? new Date(props.counters.as_of).toLocaleTimeString('fr-FR') : '—'));
</script>
<template>
  <PilotageLayout active="tableau" :event="event" :events="events" :open-alerts="openAlerts" title="Tableau de bord">
    <template v-if="event && counters">
      <section aria-label="Compteurs" class="grid grid-cols-2 gap-3">
        <div v-for="tile in tiles" :key="tile.key" class="rounded-2xl border-2 border-ikoma-navy bg-white p-4" :data-testid="'compteur-' + tile.key">
          <p class="flex items-center gap-2 text-lg font-semibold"><span aria-hidden="true">{{ tile.icon }}</span> {{ tile.label }}</p>
          <p class="text-[3rem] font-extrabold leading-tight" data-testid="valeur">{{ tile.value }}</p>
        </div>
      </section>
      <p class="text-sm" role="status">Mis à jour à <span data-testid="mis-a-jour">{{ updated }}</span> (automatique)</p>
      <section aria-labelledby="alertes-titre" class="space-y-3">
        <h2 id="alertes-titre" class="text-heading font-bold">Alertes à traiter <span v-if="openAlerts" class="rounded-full bg-red-700 px-3 text-white">{{ openAlerts }}</span></h2>
        <ul v-if="alerts.length" class="space-y-3"><AlertCard v-for="a in alerts" :key="a.id" :alert="a" /></ul>
        <p v-else class="rounded-xl bg-white p-4" data-testid="aucune-alerte">Aucune alerte à traiter.</p>
        <Link v-if="openAlerts > alerts.length" href="/pilotage/alertes" class="btn-soft">Voir toutes les alertes</Link>
      </section>
      <section aria-labelledby="outils-titre" class="space-y-3">
        <h2 id="outils-titre" class="text-heading font-bold">Autres écrans</h2>
        <div class="grid gap-3">
          <Link href="/pilotage/groupes" class="btn-soft">Groupes et cars</Link>
          <Link href="/administration/journal" class="btn-soft" data-testid="lien-journal">Journal</Link>
        </div>
      </section>
      <CancelBadge :event-id="event.id" label="Annuler un badge par son code" />
    </template>
  </PilotageLayout>
</template>
