<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { pilotageHref } from '../../pilotage/navigation';
import PilotageLayout from '../../layouts/PilotageLayout.vue';

type Row = { id: string; occurred_at: string; type: string; checkpoint: string | null; visitor_id: string | null; full_name: string | null; badge_code: string | null; verdict: string | null; zone_ok: string | null; direction?: string | null; corrected: boolean; corrige_id: string | null };
const props = defineProps<{
  event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number;
  filters: { q: string; type: string; point: string; outcome: string; from: string; until: string }; rows: Row[]; total: number; page: number; perPage: number; points: string[]; types: string[];
}>();
const q = ref(props.filters.q); const type = ref(props.filters.type); const point = ref(props.filters.point); const outcome = ref(props.filters.outcome);
const pages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)));
const labels: Record<string, string> = { entree: 'Entrée', sortie: 'Sortie', controle: 'Contrôle', correction: 'Correction', embarquement: 'Embarquement', debarquement: 'Débarquement', vehicule_entree: 'Entrée de véhicule', vehicule_sortie: 'Sortie de véhicule' };
let timer: number | undefined;
function go(page = 1) {
  router.get('/pilotage/mouvements', { q: q.value || undefined, type: type.value || undefined, point: point.value || undefined, outcome: outcome.value || undefined, page: page > 1 ? page : undefined, event: props.events.length > 1 ? props.event?.id : undefined }, { preserveState: true, replace: true });
}
watch(q, () => { window.clearTimeout(timer); timer = window.setTimeout(() => go(), 300); });
watch([type, point, outcome], () => go());
const when = (iso: string) => new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
const result = (r: Row) => r.type !== 'controle' ? '' : r.verdict !== 'valide' ? 'Refusé' : r.zone_ok === 'false' ? 'Zone non autorisée' : 'Valide';
</script>
<template>
  <PilotageLayout active="mouvements" :event="event" :events="events" :open-alerts="openAlerts" title="Mouvements physiques">
    <form class="space-y-3" role="search" aria-label="Filtrer les mouvements" @submit.prevent="go()">
      <label class="block text-lg font-semibold">Nom<input v-model="q" type="search" class="field" autocomplete="off" placeholder="Rechercher un nom" data-testid="recherche"></label>
      <div class="grid grid-cols-3 gap-3">
        <label class="block font-semibold">Type<select v-model="type" class="field" data-testid="filtre-type"><option value="">Tous</option><option v-for="t in types" :key="t" :value="t">{{ labels[t] ?? t }}</option></select></label>
        <label class="block font-semibold">Point<select v-model="point" class="field" data-testid="filtre-point"><option value="">Tous</option><option v-for="p in points" :key="p" :value="p">{{ p }}</option></select></label>
        <label class="block font-semibold">Résultat<select v-model="outcome" class="field" data-testid="filtre-resultat"><option value="">Tous</option><option value="valide">Valides</option><option value="refuse">Refusés</option></select></label>
      </div>
    </form>
    <p role="status" data-testid="total">{{ total }} mouvement{{ total > 1 ? 's' : '' }}</p>
    <ul class="pilotage-record-grid" data-testid="liste-mouvements">
      <li v-for="row in rows" :key="row.id" class="rounded-2xl border-2 border-slate-300 bg-white p-4" data-testid="mouvement">
        <p class="flex flex-wrap items-center justify-between gap-2"><strong class="text-xl">{{ labels[row.type] ?? row.type }}<template v-if="row.type === 'controle' && row.direction"> · {{ row.direction === 'entree' ? 'entrée' : 'sortie' }}</template></strong><span>{{ when(row.occurred_at) }}</span></p>
        <p class="mt-1">
          <Link v-if="row.visitor_id" :href="pilotageHref('/pilotage/visiteurs/' + row.visitor_id, event?.id)" class="underline">{{ row.full_name }}</Link><span v-else>Personne non identifiée</span>
          · {{ row.checkpoint || 'Point non indiqué' }}<template v-if="row.badge_code"> · Badge {{ row.badge_code }}</template>
        </p>
        <p v-if="result(row)" class="mt-1 font-semibold" :class="result(row) === 'Valide' ? 'text-green-800' : 'text-red-800'">{{ result(row) }}</p>
        <p v-if="row.corrected" class="mt-1 text-sm">Corrigé par un événement ultérieur.</p>
      </li>
    </ul>
    <p v-if="!rows.length" class="rounded-xl bg-white p-4">Aucun mouvement ne correspond.</p>
    <nav v-if="pages > 1" class="grid grid-cols-3 items-center gap-3" aria-label="Pages">
      <button type="button" class="btn-soft justify-center" :disabled="page <= 1" data-testid="page-precedente" @click="go(page - 1)">Précédent</button>
      <p class="text-center font-semibold" data-testid="page">Page {{ page }} / {{ pages }}</p>
      <button type="button" class="btn-soft justify-center" :disabled="page >= pages" data-testid="page-suivante" @click="go(page + 1)">Suivant</button>
    </nav>
  </PilotageLayout>
</template>
