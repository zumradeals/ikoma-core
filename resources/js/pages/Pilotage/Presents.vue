<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { pilotageHref } from '../../pilotage/navigation';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import CategorySwatch from '../../components/CategorySwatch.vue';

type Row = { id: string; full_name: string; organisation: string | null; group: string | null; zone: string; entered_at: string; category: { id: string; name: string; color: string; priority_circuit: boolean } };
const props = defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; filters: { q: string; category: string; zone: string }; rows: Row[]; total: number; page: number; perPage: number; categories: { id: string; name: string }[]; zones: string[] }>();
const q = ref(props.filters.q); const category = ref(props.filters.category); const zone = ref(props.filters.zone);
const pages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)));
let timer: number | undefined;
function go(page = 1) {
  router.get('/pilotage/presents', { q: q.value || undefined, category: category.value || undefined, zone: zone.value || undefined, page: page > 1 ? page : undefined, event: props.events.length > 1 ? props.event?.id : undefined }, { preserveState: true, preserveScroll: true, replace: true });
}
watch(q, () => { window.clearTimeout(timer); timer = window.setTimeout(() => go(), 300); });
// Ouvrir une fiche pendant que la recherche attend son délai : la recherche en attente ne doit pas annuler cette ouverture.
const leaving = () => window.clearTimeout(timer);
watch([category, zone], () => go());
const time = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
</script>
<template>
  <PilotageLayout active="presents" :event="event" :events="events" :open-alerts="openAlerts" title="Personnes présentes">
    <form class="space-y-3" role="search" aria-label="Rechercher parmi les présents" @submit.prevent="go()">
      <label class="block text-lg font-semibold">Nom<input v-model="q" type="search" class="field" autocomplete="off" placeholder="Rechercher un nom" data-testid="recherche"></label>
      <div class="grid grid-cols-2 gap-3">
        <label class="block text-lg font-semibold">Catégorie<select v-model="category" class="field" data-testid="filtre-categorie"><option value="">Toutes</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
        <label class="block text-lg font-semibold">Zone<select v-model="zone" class="field" data-testid="filtre-zone"><option value="">Toutes</option><option value="Accueil">Accueil</option><option v-for="z in zones.filter(x => x !== 'Accueil')" :key="z" :value="z">{{ z }}</option></select></label>
      </div>
    </form>
    <p role="status" data-testid="total">{{ total }} personne{{ total > 1 ? 's' : '' }} présente{{ total > 1 ? 's' : '' }}</p>
    <ul class="pilotage-record-grid" data-testid="liste-presents">
      <li v-for="row in rows" :key="row.id">
        <Link :href="pilotageHref('/pilotage/visiteurs/' + row.id, event?.id)" class="block rounded-2xl border-2 border-slate-300 bg-white p-4" @click="leaving">
          <span class="flex flex-wrap items-center justify-between gap-2"><strong class="text-xl">{{ row.full_name }}</strong><CategorySwatch :name="row.category.name" :color="row.category.color" :priority="row.category.priority_circuit" /></span>
          <span class="mt-1 block">{{ row.organisation || 'Organisation non indiquée' }}</span>
          <span class="mt-1 block text-sm">Zone : {{ row.zone }} · Groupe : {{ row.group || '—' }} · Entrée : {{ time(row.entered_at) }}</span>
        </Link>
      </li>
    </ul>
    <p v-if="!rows.length" class="rounded-xl bg-white p-4">Aucune personne ne correspond.</p>
    <nav v-if="pages > 1" class="grid grid-cols-3 items-center gap-3" aria-label="Pages">
      <button type="button" class="btn-soft justify-center" :disabled="page <= 1" data-testid="page-precedente" @click="go(page - 1)">Précédent</button>
      <p class="text-center font-semibold" data-testid="page">Page {{ page }} / {{ pages }}</p>
      <button type="button" class="btn-soft justify-center" :disabled="page >= pages" data-testid="page-suivante" @click="go(page + 1)">Suivant</button>
    </nav>
  </PilotageLayout>
</template>
