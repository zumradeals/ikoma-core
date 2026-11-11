<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import type { Centre } from '../../components/pilotage/CentreSupervision.vue';
import { pilotageHref } from '../../pilotage/navigation';
import { displayDate } from '../../labels';

/**
 * Administration › Opérations : vue d'ensemble en LECTURE SEULE. Les chiffres viennent des événements réels (accès, refus, alertes, postes) ; les actions renvoient vers les écrans
 * propriétaires (Pilotage, Postes, Journal). Aucune décision de contrôle, aucune clôture et aucun moteur ne vivent ici.
 */
type General = {
  as_of: string; mouvements: { entrees: number; sorties: number; controles: number; refus: number }; presents: { total: number; permanents: number; activites: number; contextes: { event_id: string; name: string; is_permanent: boolean; presents: number }[]; multi_contextes: number };
  alertes: { a_traiter: number; critiques: number }; litiges: number; postes: { total: number; actifs: number; appareils_enroles: number; appareils_synchronises: number; appareils_silencieux: number }; par_heure: { heure: string; entrees: number; sorties: number }[];
};
type Counters = { attendus: number; arrives: number; presents: number; refuses: number };
type Overview = { critiques: number; hautes: number; litiges: number; cars: { total: number; partis: number; arrives: number; ecarts: number }; postes_silencieux: number; retards?: number };
const props = defineProps<{
  events: { id: string; name: string; is_permanent: boolean }[]; event: { id: string; name: string; starts_at: string; ends_at: string; status: string | null; is_permanent?: boolean } | null;
  general: General; counters: Counters | null; overview: Overview | null; centre: Centre | null;
  can: { presences: boolean; alertes: boolean; litiges: boolean; journal: boolean; postes: boolean; groupes: boolean };
}>();

const TABS = [{ key: 'supervision', label: 'Supervision', icon: 'monitor' }, { key: 'mouvements', label: 'Contrôles et mouvements', icon: 'pin' }, { key: 'presences', label: 'Présences', icon: 'users' },
  { key: 'incidents', label: 'Incidents', icon: 'alert' }, { key: 'journaux', label: 'Journaux', icon: 'book' }] as const;
type TabKey = typeof TABS[number]['key'];
const tab = ref<TabKey>('supervision');
const href = (path: string) => pilotageHref(path, props.event?.id);
const choose = (e: Event) => router.get('/administration/operations', { event: (e.target as HTMLSelectElement).value }, { preserveScroll: true });
const refresh = () => router.reload({ only: ['general', 'counters', 'overview', 'centre'] });

const incidents = computed(() => props.general.alertes.a_traiter + props.general.litiges);
const signal = computed(() => (props.general.alertes.critiques > 0 ? { level: 'critique', text: `${props.general.alertes.critiques} alerte${props.general.alertes.critiques > 1 ? 's' : ''} critique${props.general.alertes.critiques > 1 ? 's' : ''} à traiter` }
  : incidents.value > 0 || props.general.postes.appareils_silencieux > 0 ? { level: 'attention', text: 'Des situations demandent votre attention' } : { level: 'calme', text: 'Aucun signal à traiter' }));
const signalClass = { critique: 'bg-red-100 text-red-900 border-red-300', attention: 'bg-amber-100 text-amber-900 border-amber-300', calme: 'bg-green-100 text-green-900 border-green-300' } as const;
const max = computed(() => Math.max(1, ...props.general.par_heure.map(h => Math.max(h.entrees, h.sorties))));
const arrivedPercent = computed(() => (props.counters && props.counters.attendus > 0 ? Math.min(100, Math.round((props.counters.arrives / props.counters.attendus) * 100)) : 0));
const time = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '—');
const typeLabel: Record<string, string> = { entree: 'Entrée', sortie: 'Sortie', controle: 'Contrôle', validation: 'Validation', correction: 'Correction' };
// « En ligne » n'est jamais affirmé : un appareil est « synchronisé récemment » (preuve : dernière synchronisation dans le délai existant) ou « sans nouvelles ».
const posteLabel: Record<string, string> = { actif: 'Synchronisé récemment', silencieux: 'Sans nouvelles', revoque: 'Révoqué' };
const scope = computed(() => (props.event ? (props.event.is_permanent ? 'site (personnel permanent)' : `activité « ${props.event.name} »`) : 'aucune activité'));
const posteClass: Record<string, string> = { actif: 'bg-green-100 text-green-900', silencieux: 'bg-amber-100 text-amber-900', revoque: 'bg-slate-200 text-slate-700' };
</script>

<template>
  <AdminLayout active="supervision">
    <div class="space-y-5" data-testid="page-operations">
      <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-display font-bold" data-testid="titre-operations">Opérations</h1>
          <p class="admin-muted mt-2">Contrôle des accès, mouvements et supervision du site, toutes activités confondues. Vue en lecture : les décisions se prennent dans le Pilotage et aux postes.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full border px-3 py-1 text-sm font-bold" :class="signalClass[signal.level as keyof typeof signalClass]" role="status" data-testid="signal">{{ signal.text }}</span>
          <button type="button" class="site-btn site-btn-outline !min-h-11" data-testid="actualiser" @click="refresh">Actualiser</button>
        </div>
      </header>

      <section aria-labelledby="site-entier" data-testid="perimetre-site">
      <h2 id="site-entier" class="text-lg font-bold">Le site entier</h2>
      <p class="admin-muted mb-3 text-sm" data-testid="perimetre-site-texte">Toutes activités et site confondus. Ces indicateurs ne changent jamais avec l’activité consultée plus bas.</p>
      <dl class="grid grid-cols-2 gap-3 xl:grid-cols-5" data-testid="indicateurs-operations">
        <div class="site-kpi min-w-0 bg-emerald-50"><span class="site-kpi-icon text-emerald-700"><AdminIcon name="arrow" :size="36" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="kpi-entrees">{{ general.mouvements.entrees }}</dd><dt class="font-semibold">Entrées (24 h)</dt></div></div>
        <div class="site-kpi min-w-0 bg-rose-50"><span class="site-kpi-icon text-rose-700"><AdminIcon name="shield" :size="36" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="kpi-refus">{{ general.mouvements.refus }}</dd><dt class="font-semibold">Accès refusés (24 h)</dt></div></div>
        <div class="site-kpi min-w-0 bg-amber-50"><span class="site-kpi-icon text-amber-700"><AdminIcon name="alert" :size="36" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="kpi-incidents">{{ incidents }}</dd><dt class="font-semibold">Incidents en cours</dt></div></div>
        <div class="site-kpi min-w-0 bg-sky-50"><span class="site-kpi-icon text-sky-700"><AdminIcon name="monitor" :size="36" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="kpi-postes">{{ general.postes.actifs }}<small class="text-lg"> / {{ general.postes.total }}</small></dd><dt class="font-semibold">Postes actifs <span class="font-normal text-slate-600">(état administratif)</span></dt><small class="text-slate-600" data-testid="kpi-appareils">{{ general.postes.appareils_synchronises }} appareil{{ general.postes.appareils_synchronises > 1 ? 's' : '' }} synchronisé{{ general.postes.appareils_synchronises > 1 ? 's' : '' }} récemment · {{ general.postes.appareils_silencieux }} silencieux</small></div></div>
        <div class="site-kpi min-w-0 bg-indigo-50"><span class="site-kpi-icon text-indigo-700"><AdminIcon name="users" :size="36" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="kpi-presents">{{ general.presents.total }}</dd><dt class="font-semibold">Personnes présentes sur le site</dt><small class="text-slate-600" data-testid="kpi-presents-detail">Identités distinctes · dernier passage : {{ general.presents.permanents }} sur le site (personnel permanent), {{ general.presents.activites }} en activité</small></div></div>
      </dl>
      </section>

      <div class="site-tabs" role="tablist" aria-label="Opérations">
        <button v-for="t in TABS" :key="t.key" type="button" role="tab" class="site-tab" :aria-selected="tab === t.key" :tabindex="tab === t.key ? 0 : -1" :data-testid="'onglet-' + t.key" @click="tab = t.key"><AdminIcon :name="t.icon" :size="18" />{{ t.label }}</button>
      </div>

      <h2 class="text-lg font-bold" data-testid="titre-detail">Détail par activité</h2>
      <label class="block max-w-md text-sm font-semibold">Activité consultée (périmètre des panneaux ci-dessous, sauf mention « site entier »)
        <select class="field" :value="event?.id ?? ''" data-testid="choix-activite" @change="choose"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.is_permanent ? 'Site (personnel permanent)' : e.name }}</option></select>
      </label>

      <!-- Supervision -->
      <div v-if="tab === 'supervision'" class="grid gap-5 xl:grid-cols-2" data-testid="panneau-supervision" role="tabpanel">
        <section class="site-card p-4 space-y-3" aria-labelledby="act-titre">
          <h2 id="act-titre" class="text-lg font-bold">Activité consultée</h2>
          <p class="text-xs font-semibold text-blue-900" data-testid="perimetre-activite">Périmètre : {{ scope }}</p>
          <template v-if="event && counters">
            <p class="font-semibold" data-testid="activite-nom">{{ event.name }}</p>
            <p class="text-sm text-slate-600">{{ displayDate(event.starts_at) }} → {{ displayDate(event.ends_at) }}</p>
            <p data-testid="activite-effectifs">Attendus : <strong>{{ counters.attendus }}</strong> · Arrivés : <strong>{{ counters.arrives }}</strong> · Présents : <strong>{{ counters.presents }}</strong></p>
            <div class="h-3 w-full overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Part des attendus arrivés" :aria-valuenow="arrivedPercent" aria-valuemin="0" aria-valuemax="100"><div class="h-full bg-green-600" :style="{ width: arrivedPercent + '%' }" /></div>
          </template>
          <p v-else class="text-slate-600" data-testid="aucune-activite">Aucune activité à consulter.</p>
        </section>

        <section class="site-card p-4 space-y-3" aria-labelledby="mvt-titre">
          <div class="flex items-center justify-between gap-3"><h2 id="mvt-titre" class="text-lg font-bold">Accès en temps réel</h2><span class="text-xs font-semibold text-blue-900">Périmètre : {{ scope }}</span><Link v-if="can.presences" :href="href('/pilotage/mouvements')" class="text-sm font-semibold underline">Voir tout</Link></div>
          <p v-if="!can.presences" class="text-sm text-slate-600">Le détail nominatif est réservé aux comptes qui consultent les présences.</p>
          <ul v-else-if="centre && centre.movements.length" class="divide-y text-sm" data-testid="mouvements-recents"><li v-for="m in centre.movements.slice(0, 6)" :key="m.id" class="flex flex-wrap justify-between gap-2 py-2"><span><strong>{{ time(m.occurred_at) }}</strong> {{ m.full_name ?? '—' }}</span><span>{{ typeLabel[m.type] ?? m.type }}<template v-if="m.checkpoint"> · {{ m.checkpoint }}</template></span></li></ul>
          <p v-else class="text-sm text-slate-600">Aucun mouvement récent pour cette activité.</p>
        </section>

        <section class="site-card p-4 space-y-3" aria-labelledby="postes-titre">
          <div class="flex items-center justify-between gap-3"><h2 id="postes-titre" class="text-lg font-bold">Postes et appareils</h2><Link v-if="can.postes" href="/administration/site" class="text-sm font-semibold underline">Postes et accès</Link></div>
          <p class="text-sm" data-testid="postes-resume"><span class="text-xs font-semibold text-slate-700">Site entier :</span> {{ general.postes.actifs }} poste{{ general.postes.actifs > 1 ? 's' : '' }} administrativement actif{{ general.postes.actifs > 1 ? 's' : '' }} sur {{ general.postes.total }} · {{ general.postes.appareils_synchronises }} appareil{{ general.postes.appareils_synchronises > 1 ? 's' : '' }} synchronisé{{ general.postes.appareils_synchronises > 1 ? 's' : '' }} récemment · {{ general.postes.appareils_silencieux }} silencieux</p>
          <p v-if="centre && centre.postes.length" class="text-xs font-semibold text-blue-900">Appareils enrôlés — {{ scope }}</p>
          <ul v-if="centre && centre.postes.length" class="divide-y text-sm" data-testid="liste-postes"><li v-for="p in centre.postes" :key="p.id" class="flex flex-wrap items-center justify-between gap-2 py-2"><span>{{ p.name }}</span><span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="posteClass[p.state]">{{ posteLabel[p.state] ?? p.state }}</span><small class="text-slate-600">Dernière synchronisation : {{ time(p.last_synced_at) }}</small></li></ul>
        </section>

        <section class="site-card p-4 space-y-3" aria-labelledby="h24-titre">
          <h2 id="h24-titre" class="text-lg font-bold">Passages (dernières 24 h)</h2>
          <p class="text-xs font-semibold text-slate-700">Périmètre : site entier</p>
          <p class="text-sm" data-testid="mouvements-resume">{{ general.mouvements.entrees }} entrée{{ general.mouvements.entrees > 1 ? 's' : '' }} · {{ general.mouvements.sorties }} sortie{{ general.mouvements.sorties > 1 ? 's' : '' }} · {{ general.mouvements.controles }} contrôle{{ general.mouvements.controles > 1 ? 's' : '' }} intérieur{{ general.mouvements.controles > 1 ? 's' : '' }}</p>
          <svg viewBox="0 0 240 90" class="h-28 w-full" role="img" aria-label="Entrées et sorties par heure sur 24 heures" data-testid="graphique-24h">
            <g v-for="(h, i) in general.par_heure" :key="i"><rect :x="i * 10 + 1" :y="80 - (h.entrees / max) * 70" width="4" :height="(h.entrees / max) * 70" fill="#1a73e8" /><rect :x="i * 10 + 5" :y="80 - (h.sorties / max) * 70" width="4" :height="(h.sorties / max) * 70" fill="#9aa5b8" /></g>
            <line x1="0" y1="80" x2="240" y2="80" stroke="#cbd5e1" />
          </svg>
          <p class="text-xs text-slate-600">Bleu : entrées · gris : sorties. Les entrées et sorties corrigées ne sont pas comptées ; un contrôle intérieur n'est jamais une seconde entrée.</p>
        </section>
      </div>

      <!-- Contrôles et mouvements -->
      <section v-else-if="tab === 'mouvements'" class="site-card p-4 space-y-3" data-testid="panneau-mouvements" role="tabpanel">
        <h2 class="text-lg font-bold">Contrôles et mouvements</h2>
        <p class="text-xs font-semibold text-blue-900" data-testid="perimetre-mouvements">Périmètre : {{ scope }}. Les totaux du site entier sont ci-dessus.</p>
        <p v-if="!can.presences" class="text-slate-600">Réservé aux comptes qui consultent les présences.</p>
        <template v-else>
          <ul v-if="centre && centre.movements.length" class="divide-y text-sm"><li v-for="m in centre.movements" :key="m.id" class="flex flex-wrap justify-between gap-2 py-2"><span><strong>{{ time(m.occurred_at) }}</strong> {{ m.full_name ?? '—' }}<template v-if="m.badge_code"> · {{ m.badge_code }}</template></span><span>{{ typeLabel[m.type] ?? m.type }}<template v-if="m.checkpoint"> · {{ m.checkpoint }}</template></span></li></ul>
          <p v-else class="text-slate-600">Aucun mouvement pour cette activité.</p>
          <Link :href="href('/pilotage/mouvements')" class="site-btn site-btn-outline" data-testid="vers-mouvements">Journal des mouvements (Pilotage)</Link>
        </template>
      </section>

      <!-- Présences -->
      <section v-else-if="tab === 'presences'" class="site-card p-4 space-y-3" data-testid="panneau-presences" role="tabpanel">
        <h2 class="text-lg font-bold">Présences</h2>
        <div class="space-y-1">
          <p class="text-xs font-semibold text-slate-700">Site entier</p>
          <p data-testid="presences-site">{{ general.presents.total }} personne{{ general.presents.total > 1 ? 's' : '' }} présente{{ general.presents.total > 1 ? 's' : '' }} sur le site (identités distinctes ; dernier passage : {{ general.presents.permanents }} sur le site (personnel permanent), {{ general.presents.activites }} en activité). Une présence se déduit des entrées et sorties confirmées et non corrigées, jamais de la simple possession d'un badge.</p>
          <p v-if="general.presents.multi_contextes > 0" class="text-sm text-amber-900" data-testid="presences-multi">{{ general.presents.multi_contextes }} présence{{ general.presents.multi_contextes > 1 ? 's' : '' }} enregistrée{{ general.presents.multi_contextes > 1 ? 's' : '' }} dans plusieurs contextes pour une même personne : comptée{{ general.presents.multi_contextes > 1 ? 's' : '' }} une seule fois au total.</p>
          <p class="text-xs font-semibold text-slate-700">Présents par contexte (indicateurs distincts, non additionnés)</p>
          <ul class="text-sm" data-testid="presences-contextes"><li v-for="c in general.presents.contextes" :key="c.event_id">{{ c.is_permanent ? 'Site (personnel permanent)' : c.name }} : <strong>{{ c.presents }}</strong></li></ul>
        </div>
        <div v-if="centre" class="space-y-1"><p class="text-xs font-semibold text-blue-900">Périmètre : {{ scope }}</p><p data-testid="presences-activite">Dans ce contexte : <strong>{{ centre.presents.count }}</strong> présent{{ centre.presents.count > 1 ? 's' : '' }}.</p></div>
        <div class="flex flex-wrap gap-3"><Link v-if="can.presences" :href="href('/pilotage/presents')" class="site-btn site-btn-outline">Liste des présents</Link><Link v-if="can.presences" :href="href('/pilotage/evacuation')" class="site-btn site-btn-outline" data-testid="vers-evacuation">Liste d’évacuation</Link></div>
      </section>

      <!-- Incidents -->
      <section v-else-if="tab === 'incidents'" class="site-card p-4 space-y-3" data-testid="panneau-incidents" role="tabpanel">
        <h2 class="text-lg font-bold">Incidents et situations à traiter</h2>
        <p class="text-xs font-semibold text-slate-700">Site entier</p>
        <ul class="space-y-1 text-sm" data-testid="liste-incidents">
          <li>Alertes à traiter : <strong data-testid="inc-alertes">{{ general.alertes.a_traiter }}</strong> (dont {{ general.alertes.critiques }} critique{{ general.alertes.critiques > 1 ? 's' : '' }})</li>
          <li>Cas à décider (accès refusés) : <strong data-testid="inc-litiges">{{ general.litiges }}</strong></li>
          <li>Appareils silencieux : <strong>{{ general.postes.appareils_silencieux }}</strong></li>
        </ul>
        <p class="text-xs font-semibold text-blue-900" data-testid="perimetre-incidents">Périmètre : {{ scope }}</p>
        <ul class="space-y-1 text-sm" data-testid="liste-incidents-activite">
          <li v-if="overview?.retards !== undefined">Visites annoncées en retard : <strong>{{ overview.retards }}</strong></li>
          <li v-if="centre">Véhicules présents : <strong>{{ centre.vehicles_count }}</strong> · Badges à récupérer : <strong>{{ centre.badges_to_return.count }}</strong></li>
        </ul>
        <div class="flex flex-wrap gap-3"><Link v-if="can.alertes" :href="href('/pilotage/alertes')" class="site-btn site-btn-outline" data-testid="vers-alertes">Alertes (Pilotage)</Link><Link v-if="can.litiges" :href="href('/pilotage/litiges')" class="site-btn site-btn-outline" data-testid="vers-litiges">Litiges (Pilotage)</Link></div>
        <p class="text-sm text-slate-600">Les clôtures groupées et les décisions de litige relèvent du superviseur : cet écran n’en simule aucune.</p>
      </section>

      <!-- Journaux -->
      <section v-else class="site-card p-4 space-y-3" data-testid="panneau-journaux" role="tabpanel">
        <h2 class="text-lg font-bold">Journaux</h2>
        <p class="text-slate-700">Le journal d’audit est en lecture seule et chaîné ; il conserve toutes les opérations (accès, clôtures, badges, habilitations).</p>
        <Link v-if="can.journal" :href="'/administration/journal' + (event ? '?event=' + event.id : '')" class="site-btn site-btn-outline" data-testid="vers-journal">Ouvrir le journal{{ event ? ' de l’activité consultée' : '' }}</Link>
        <p v-else class="text-slate-600">Votre compte ne consulte pas le journal.</p>
      </section>
    </div>
  </AdminLayout>
</template>
