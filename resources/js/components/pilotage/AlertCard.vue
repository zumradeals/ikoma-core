<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { displayDate } from '../../labels';

export type AlertRow = { id: string; type: string; severity: 'critique' | 'haute' | 'normale'; message: string; created_at: string; acknowledged_at: string | null; resolved_at: string | null; link: string | null };
defineProps<{ alert: AlertRow }>();
const severity = { critique: ['⛔', 'Critique', 'border-red-700 bg-red-50'], haute: ['▲', 'Haute', 'border-orange-600 bg-orange-50'], normale: ['●', 'Normale', 'border-slate-400 bg-white'] } as const;
const types: Record<string, string> = { refus: 'Accès refusé', non_liste_attente: 'Non listé en attente', badge_annule: 'Badge annulé', conflit_sync: 'Conflit de synchronisation', hors_periode: 'Hors période', car_arrive: 'Car arrivé', groupe_incomplet: 'Groupe incomplet' };
function acknowledge(id: string) { router.post(`/pilotage/alertes/${id}/prendre-en-compte`, {}, { preserveScroll: true }); }
</script>
<template>
  <li class="rounded-2xl border-2 p-4" :class="severity[alert.severity][2]" :data-testid="'alerte-' + alert.type" :data-severity="alert.severity">
    <p class="flex flex-wrap items-center gap-2 font-bold"><span aria-hidden="true">{{ severity[alert.severity][0] }}</span> {{ severity[alert.severity][1] }} · {{ types[alert.type] ?? alert.type }}</p>
    <p class="mt-1 text-lg">{{ alert.message }}</p>
    <p class="text-sm">{{ displayDate(alert.created_at) }}<template v-if="alert.acknowledged_at"> · prise en compte</template><template v-else-if="alert.resolved_at"> · réglée</template></p>
    <div class="mt-3 flex flex-wrap gap-3">
      <Link v-if="alert.link" :href="alert.link" class="btn-soft">Voir</Link>
      <button v-if="!alert.acknowledged_at && !alert.resolved_at" type="button" class="btn-main !w-auto" data-testid="prendre-en-compte" @click="acknowledge(alert.id)">Prendre en compte</button>
    </div>
  </li>
</template>
