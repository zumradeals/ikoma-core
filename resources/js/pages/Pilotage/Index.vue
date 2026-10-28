<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import AlertCard, { type AlertRow } from '../../components/pilotage/AlertCard.vue';
import CancelBadge from '../../components/pilotage/CancelBadge.vue';
import { usePolling } from '../../pilotage/usePolling';

type Counters = { attendus: number; arrives: number; presents: number; refuses: number; as_of: string };
type Overview = { critiques: number; hautes: number; litiges: number; cars: { total: number; partis: number; arrives: number; ecarts: number }; postes_silencieux: number };
const props = defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; counters: Counters | null; alerts: AlertRow[]; overview: Overview | null }>();
usePolling(['counters', 'alerts', 'openAlerts', 'overview'], 3000);

const tiles = computed(() => [
  { key: 'attendus', label: 'Attendus', icon: '☰', value: props.counters?.attendus }, { key: 'arrives', label: 'Arrivés', icon: '➜', value: props.counters?.arrives },
  { key: 'presents', label: 'Présents', icon: '●', value: props.counters?.presents }, { key: 'refuses', label: 'Refusés', icon: '✕', value: props.counters?.refuses },
]);
const updated = computed(() => (props.counters ? new Date(props.counters.as_of).toLocaleTimeString('fr-FR') : '—'));
const plural = (n: number, one: string, many: string) => `${n} ${n > 1 ? many : one}`;

/** La phrase d'état dit en mots ce que le superviseur doit faire en premier : jamais la couleur seule. */
const situation = computed(() => {
  const o = props.overview;
  if (props.openAlerts > 0) {
    const critical = o?.critiques ?? 0;
    return { level: critical > 0 ? 'critique' : 'attention', icon: critical > 0 ? '⛔' : '▲', title: plural(props.openAlerts, 'alerte à traiter', 'alertes à traiter'), text: critical > 0 ? plural(critical, 'est critique', 'sont critiques') + ' : à traiter en premier.' : 'Aucune n’est critique.' };
  }
  if ((o?.litiges ?? 0) > 0) return { level: 'attention', icon: '⚖', title: plural(o!.litiges, 'cas à décider', 'cas à décider'), text: 'Des accès refusés attendent votre décision.' };
  return { level: 'calme', icon: '✓', title: 'Tout est calme', text: 'Aucune alerte et aucun cas à décider.' };
});
const field = computed(() => {
  const o = props.overview;
  if (!o) return [];
  return [
    { key: 'litiges', icon: '⚖', title: 'Cas à décider', text: o.litiges ? plural(o.litiges, 'accès refusé attend votre décision', 'accès refusés attendent votre décision') : 'Aucun cas en attente', href: '/pilotage/litiges', warn: o.litiges > 0 },
    { key: 'cars', icon: '▭', title: 'Cars', text: o.cars.total === 0 ? 'Aucun car prévu' : `${o.cars.partis} parti${o.cars.partis > 1 ? 's' : ''} sur ${o.cars.total} · ${o.cars.arrives} arrivé${o.cars.arrives > 1 ? 's' : ''}${o.cars.ecarts ? ' · ' + plural(o.cars.ecarts, 'avec un écart', 'avec des écarts') : ''}`, href: '/pilotage/groupes', warn: o.cars.ecarts > 0 },
    { key: 'postes', icon: '▯', title: 'Tablettes', text: o.postes_silencieux ? plural(o.postes_silencieux, 'tablette sans nouvelles depuis plus d’une heure', 'tablettes sans nouvelles depuis plus d’une heure') : 'Toutes les tablettes donnent des nouvelles', href: null, warn: o.postes_silencieux > 0 },
  ];
});
const arrivedPercent = computed(() => (props.counters && props.counters.attendus > 0 ? Math.min(100, Math.round((props.counters.arrives / props.counters.attendus) * 100)) : 0));
const levelClass = { critique: 'border-red-700 bg-red-50', attention: 'border-orange-600 bg-orange-50', calme: 'border-emerald-700 bg-emerald-50' } as const;
</script>
<template>
  <PilotageLayout active="tableau" :event="event" :events="events" :open-alerts="openAlerts" title="Tableau de bord">
    <template v-if="event && counters">
      <section aria-label="Situation" class="rounded-2xl border-4 p-4" :class="levelClass[situation.level as keyof typeof levelClass]" :data-level="situation.level" data-testid="situation">
        <p class="flex items-center gap-3 text-2xl font-extrabold"><span aria-hidden="true">{{ situation.icon }}</span><span data-testid="situation-titre">{{ situation.title }}</span></p>
        <p class="mt-1 text-lg">{{ situation.text }}</p>
        <p class="mt-2 text-sm" role="status">Mis à jour à <span data-testid="mis-a-jour">{{ updated }}</span> (automatique)</p>
      </section>

      <section aria-labelledby="alertes-titre" class="space-y-3">
        <h2 id="alertes-titre" class="text-heading font-bold">Alertes à traiter <span v-if="openAlerts" class="rounded-full bg-red-700 px-3 text-white">{{ openAlerts }}</span></h2>
        <ul v-if="alerts.length" class="space-y-3"><AlertCard v-for="a in alerts" :key="a.id" :alert="a" /></ul>
        <p v-else class="rounded-xl bg-white p-4" data-testid="aucune-alerte">Aucune alerte à traiter.</p>
        <Link v-if="openAlerts > alerts.length" href="/pilotage/alertes" class="btn-soft">Voir toutes les alertes</Link>
      </section>

      <section aria-label="Compteurs" class="space-y-3">
        <div class="grid grid-cols-2 gap-3">
          <div v-for="tile in tiles" :key="tile.key" class="rounded-2xl border-2 border-ikoma-navy bg-white p-4" :data-testid="'compteur-' + tile.key">
            <p class="flex items-center gap-2 text-lg font-semibold"><span aria-hidden="true">{{ tile.icon }}</span> {{ tile.label }}</p>
            <p class="text-[3rem] font-extrabold leading-tight" data-testid="valeur">{{ tile.value }}</p>
          </div>
        </div>
        <div class="rounded-2xl bg-white p-4">
          <p class="font-semibold" data-testid="avancement-arrivees">{{ counters.arrives }} arrivé{{ counters.arrives > 1 ? 's' : '' }} sur {{ counters.attendus }} attendu{{ counters.attendus > 1 ? 's' : '' }} ({{ arrivedPercent }} %)</p>
          <div class="mt-2 h-4 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Part des attendus déjà arrivés" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="arrivedPercent"><div class="h-full rounded-full bg-ikoma-orange" :style="{ width: arrivedPercent + '%' }" /></div>
        </div>
      </section>

      <section v-if="overview" aria-labelledby="terrain-titre" class="space-y-3">
        <h2 id="terrain-titre" class="text-heading font-bold">Sur le terrain</h2>
        <ul class="space-y-3" data-testid="terrain">
          <li v-for="row in field" :key="row.key" class="rounded-2xl border-2 bg-white p-4" :class="row.warn ? 'border-orange-600' : 'border-slate-300'" :data-testid="'terrain-' + row.key" :data-warn="row.warn">
            <p class="flex items-center gap-2 text-lg font-bold"><span aria-hidden="true">{{ row.icon }}</span> {{ row.title }}<span v-if="row.warn" class="rounded-full bg-orange-600 px-2 text-sm font-semibold text-white">À voir</span></p>
            <p class="mt-1">{{ row.text }}</p>
            <Link v-if="row.href" :href="row.href" class="btn-soft mt-3">Ouvrir</Link>
          </li>
        </ul>
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
