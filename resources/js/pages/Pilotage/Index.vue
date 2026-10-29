<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import { pilotageHref } from '../../pilotage/navigation';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import AlertCard, { type AlertRow } from '../../components/pilotage/AlertCard.vue';
import CancelBadge from '../../components/pilotage/CancelBadge.vue';
import { usePolling } from '../../pilotage/usePolling';

type Counters = { attendus: number; arrives: number; presents: number; refuses: number; as_of: string };
type Overview = { critiques: number; hautes: number; litiges: number; cars: { total: number; partis: number; arrives: number; ecarts: number }; postes_silencieux: number };
const props = defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; counters: Counters | null; alerts: AlertRow[]; overview: Overview | null }>();
usePolling(['counters', 'alerts', 'openAlerts', 'criticalAlerts', 'overview'], 3000);

const page = usePage<{ auth?: { permissions: string[] } | null }>();
const can = (permission: string) => page.props.auth?.permissions?.includes(permission) ?? false;
const href = (path: string) => pilotageHref(path, props.event?.id);
const tiles = computed(() => [
  { key: 'attendus', label: 'Attendus', icon: 'users', value: props.counters?.attendus }, { key: 'arrives', label: 'Arrivés', icon: 'arrow', value: props.counters?.arrives },
  { key: 'presents', label: 'Présents', icon: 'users', value: props.counters?.presents }, { key: 'refuses', label: 'Refusés', icon: 'shield', value: props.counters?.refuses },
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
    <template #heading-actions><p v-if="counters" class="pilotage-updated" role="status"><AdminIcon name="clock" /><span>Mis à jour à <strong data-testid="mis-a-jour">{{ updated }}</strong><br><span class="pilotage-muted">Actualisation automatique</span></span></p></template>
    <template v-if="event && counters">
      <section aria-label="Situation" class="pilotage-situation" :class="levelClass[situation.level as keyof typeof levelClass]" :data-level="situation.level" data-testid="situation">
        <span class="pilotage-situation-icon" aria-hidden="true">{{ situation.icon }}</span><div><h2 data-testid="situation-titre">{{ situation.title }}</h2><p>{{ situation.text }}</p></div>
      </section>
      <section aria-label="Compteurs" class="pilotage-kpis">
        <div v-for="tile in tiles" :key="tile.key" class="pilotage-card pilotage-kpi" :class="'kpi-' + tile.key" :data-testid="'compteur-' + tile.key"><span class="pilotage-metric-icon"><AdminIcon :name="tile.icon" :size="28" /></span><div><h2>{{ tile.label }}</h2><p data-testid="valeur">{{ tile.value }}</p></div></div>
      </section>
      <div class="pilotage-dashboard-columns">
        <div class="pilotage-stack">
          <section v-if="can('alertes.consulter')" aria-labelledby="alertes-titre" class="pilotage-card">
            <div class="pilotage-section-heading"><h2 id="alertes-titre"><AdminIcon name="alert" /> Alertes à traiter <span v-if="openAlerts" class="pilotage-count">{{ openAlerts }}</span></h2><Link :href="href('/pilotage/alertes')" class="pilotage-text-link">Voir toutes</Link></div>
            <ul v-if="alerts.length" class="pilotage-alert-list"><AlertCard v-for="a in alerts" :key="a.id" :alert="a" :event-id="event.id" /></ul>
            <p v-else class="pilotage-empty" data-testid="aucune-alerte">✓ Aucune alerte à traiter.</p>
          </section>
          <section v-if="overview && can('litiges.decider')" class="pilotage-card" aria-labelledby="litiges-titre" data-testid="terrain-litiges" :data-warn="overview.litiges > 0">
            <div class="pilotage-section-heading"><h2 id="litiges-titre"><AdminIcon name="shield" /> Cas à décider / Litiges</h2><span v-if="overview.litiges" class="pilotage-tag">▲ À voir</span></div>
            <p class="pilotage-litige-count">{{ overview.litiges }} <span>cas à décider</span></p><p>{{ field[0]?.text }}</p><Link :href="href('/pilotage/litiges')" class="btn-soft mt-4">{{ overview.litiges ? 'Examiner les cas' : 'Consulter les litiges' }}</Link>
          </section>
        </div>
        <div class="pilotage-stack">
          <section class="pilotage-card" aria-labelledby="progression-titre">
<h2 id="progression-titre"><AdminIcon name="arrow" /> Progression des arrivées</h2>
            <p class="mt-4" data-testid="avancement-arrivees">{{ counters.arrives }} arrivé{{ counters.arrives > 1 ? 's' : '' }} sur {{ counters.attendus }} attendu{{ counters.attendus > 1 ? 's' : '' }} ({{ arrivedPercent }} %)</p>
            <div class="pilotage-progress" role="progressbar" aria-label="Part des attendus déjà arrivés" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="arrivedPercent"><div :style="{ width: arrivedPercent + '%' }" /></div>
          </section>
          <section v-if="overview" class="pilotage-card" aria-labelledby="terrain-titre">
<h2 id="terrain-titre"><AdminIcon name="bus" /> Sur le terrain</h2>
            <ul class="pilotage-field" data-testid="terrain"><li v-for="row in field.slice(1)" :key="row.key" :data-testid="'terrain-' + row.key" :data-warn="row.warn"><div class="pilotage-section-heading"><h3>{{ row.title === 'Cars' ? 'Cars et groupes' : row.title }}</h3><span v-if="row.warn" class="pilotage-tag">▲ À voir</span></div><p>{{ row.text }}</p><Link v-if="row.href && can('groupes.suivre') && can('cars.suivre')" :href="href(row.href)" class="pilotage-text-link">Suivre les groupes et cars</Link></li></ul>
          </section>
          <section v-if="can('presences.consulter')" class="pilotage-card pilotage-evacuation" aria-labelledby="evacuation-titre"><h2 id="evacuation-titre"><AdminIcon name="arrow" /> Évacuation</h2><p><strong>{{ counters.presents }}</strong> personne{{ counters.presents > 1 ? 's' : '' }} présente{{ counters.presents > 1 ? 's' : '' }}</p><Link :href="href('/pilotage/evacuation')" class="btn-soft">Ouvrir la liste d’évacuation</Link></section>
        </div>
      </div>
      <details v-if="can('badges.annuler') || can('journal.consulter') || (can('groupes.suivre') && can('cars.suivre'))" class="pilotage-card pilotage-other no-print" data-testid="autres-actions"><summary>Autres actions</summary><div class="pilotage-other-content"><Link v-if="can('groupes.suivre') && can('cars.suivre')" :href="href('/pilotage/groupes')" class="btn-soft">Groupes et cars</Link><Link v-if="can('journal.consulter')" :href="href('/administration/journal')" class="btn-soft" data-testid="lien-journal">Consulter le journal</Link><CancelBadge v-if="can('badges.annuler')" :event-id="event.id" label="Annuler un badge par son code" /></div></details>
    </template>
  </PilotageLayout>
</template>
