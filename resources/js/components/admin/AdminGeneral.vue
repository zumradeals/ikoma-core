<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminIcon from './AdminIcon.vue';

/**
 * Tableau de bord de l'administrateur : vue d'ensemble en LECTURE SEULE, sur un seul écran (indicateurs, accès, mouvements, site, activités, visites, badges, accès rapides, alertes).
 * Les chiffres viennent des services canoniques ; chaque alerte renvoie vers sa fonction d'origine (ou indique qu'elle relève d'un autre rôle). Aucun formulaire ni moteur ici.
 */
type Movement = { at: string; type: string; checkpoint: string | null; context: string; name: string | null; resultat: 'autorise' | 'refuse' };
type Activity = { id: string; name: string; kind: string; site: string | null; starts_at: string; ends_at: string; phase: string; participants: number; attendus: number; arrivees: number; presents: number };
type Priority = { key: string; severity: 'haute' | 'normale' | 'info'; title: string; text: string; count: number; status: string; href: string | null };
export type General = {
  as_of: string; perimetre: string; donnees: { sauvegarde: string; actualise: string };
  indicateurs: { personnes: { enregistrees: number }; titulaires: { actifs: number }; habilitations: { actives: number; a_echeance: number; jours: number }; activites: { en_cours: number; a_venir: number; participants_actifs: number };
    supports: { cartes_remises: number; badges_associes: number }; presents: { total: number; permanents: number; activites: number }; alertes: { a_traiter: number; litiges: number } };
  activites: Activity[];
  personnes: { enregistrees: number; actives: number; a_echeance: number; jours: number; retirees_ou_expirees: number; verifications: { homonymes: number; titulaires_sans_categorie: number; titulaires_sans_photo: number } };
  badges: { cartes: { actives: number; remises: number; non_remises: number; imprimees_non_remises: number; retirees: number }; temporaires: { associes: number; non_restitues: number }; impression: { emis_non_imprimes: number } };
  operations: { mouvements: { entrees: number; sorties: number; controles: number; refus: number }; presents: { total: number; permanents: number; activites: number; multi_contextes: number };
    postes: { total: number; actifs: number; appareils_enroles: number; appareils_synchronises: number; appareils_silencieux: number }; visites_annoncees: { total: number; en_retard: number };
    par_heure: { heure: string; entrees: number; sorties: number }[]; vehicules_presents: number; derniere_synchro: string | null; derniers: Movement[] };
  priorites: Priority[];
};
const props = defineProps<{ general: General }>();
const page = usePage<{ auth?: { permissions: string[] } | null }>();
const can = (permission: string) => page.props.auth?.permissions?.includes(permission) ?? false;
const hhmm = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
const day = computed(() => new Date(props.general.as_of).toLocaleDateString('fr-FR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }));
const num = (n: number) => n.toLocaleString('fr-FR');
const i = computed(() => props.general.indicateurs);
const op = computed(() => props.general.operations);
const cards = computed(() => props.general.badges.cartes);
const kpis = computed(() => [
  { key: 'personnes', label: 'Personnes enregistrées', value: i.value.personnes.enregistrees, icon: 'users', tone: 'bg-sky-50 text-sky-700' },
  { key: 'titulaires', label: 'Titulaires permanents', value: i.value.titulaires.actifs, icon: 'qr', tone: 'bg-emerald-50 text-emerald-700' },
  { key: 'activites', label: 'Activités en cours', value: i.value.activites.en_cours, icon: 'calendar', tone: 'bg-indigo-50 text-indigo-700' },
  { key: 'entrees', label: 'Entrées (24 h)', value: op.value.mouvements.entrees, icon: 'arrow', tone: 'bg-green-50 text-green-700' },
  { key: 'sorties', label: 'Sorties (24 h)', value: op.value.mouvements.sorties, icon: 'arrow', tone: 'bg-amber-50 text-amber-700' },
  { key: 'refus', label: 'Contrôles refusés (24 h)', value: op.value.mouvements.refus, icon: 'shield', tone: 'bg-rose-50 text-rose-700' },
  { key: 'alertes', label: 'Alertes en cours', value: i.value.alertes.a_traiter, icon: 'alert', tone: 'bg-yellow-50 text-yellow-700' },
]);
// Graphique : deux barres par heure (entrées, sorties) sur les 24 dernières tranches horaires (heures UTC) ; l'échelle suit le maximum réel.
const max = computed(() => Math.max(1, ...op.value.par_heure.flatMap(h => [h.entrees, h.sorties])));
const bars = computed(() => op.value.par_heure.map(h => ({ ...h, he: (h.entrees / max.value) * 180, hs: (h.sorties / max.value) * 180 })));
const ticks = computed(() => [0, 0.5, 1].map(f => ({ y: 200 - f * 180, label: Math.round(max.value * f) })));
const totalEntrees = computed(() => op.value.par_heure.reduce((s, h) => s + h.entrees, 0));
const totalSorties = computed(() => op.value.par_heure.reduce((s, h) => s + h.sorties, 0));
const typeLabel: Record<string, string> = { entree: 'Entrée', sortie: 'Sortie', controle: 'Contrôle', refus: 'Contrôle refusé' };
const initials = (name: string | null) => (name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]?.toUpperCase()).join('');
const severity = { haute: { label: 'Haute', pill: 'bg-red-100 text-red-900', dot: 'bg-red-600' }, normale: { label: 'Moyenne', pill: 'bg-amber-100 text-amber-900', dot: 'bg-amber-500' }, info: { label: 'Info', pill: 'bg-sky-100 text-sky-900', dot: 'bg-sky-500' } } as const;
const phase: Record<string, string> = { en_cours: 'En cours', a_venir: 'À venir' };
const progress = (a: Activity) => (a.attendus > 0 ? Math.min(100, Math.round((a.arrivees / a.attendus) * 100)) : 0);
const periode = (a: Activity) => `${new Date(a.starts_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })} – ${new Date(a.ends_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })}`;
const sync = computed(() => (op.value.derniere_synchro ? `Dernière : ${hhmm(op.value.derniere_synchro)}` : 'Aucune synchronisation'));
const quick = computed(() => [
  { key: 'personne', label: 'Ajouter une personne', href: '/administration/personnes', icon: 'users', tone: 'bg-sky-50 text-sky-800', show: can('listes.importer') },
  { key: 'habilitations', label: 'Gérer les habilitations', href: '/administration/habilitations', icon: 'shield', tone: 'bg-emerald-50 text-emerald-800', show: can('listes.importer') },
  { key: 'activite', label: 'Créer une activité', href: '/administration/evenements', icon: 'calendar', tone: 'bg-violet-50 text-violet-800', show: can('evenements.gerer') },
  { key: 'badges', label: 'Préparer des badges', href: '/administration/badges', icon: 'qr', tone: 'bg-orange-50 text-orange-800', show: can('badges.gerer') && can('listes.importer') },
  { key: 'operations', label: 'Voir les opérations', href: '/administration/operations', icon: 'monitor', tone: 'bg-cyan-50 text-cyan-800', show: can('pilotage.consulter') },
  { key: 'journal', label: 'Consulter le journal', href: '/administration/journal', icon: 'book', tone: 'bg-rose-50 text-rose-800', show: can('journal.consulter') },
].filter(q => q.show));
</script>

<template>
  <div class="space-y-4" data-testid="tableau-general">
    <!-- En-tête -->
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-display font-bold" data-testid="titre-general">Tableau de bord – Administrateur</h1>
        <p class="admin-muted">Vue d’ensemble générale du système</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <p class="rounded-xl border border-slate-200 bg-white px-3 py-2 capitalize" data-testid="date-jour"><AdminIcon name="calendar" :size="16" class="mr-1 inline" />Aujourd’hui : {{ day }}</p>
        <p class="rounded-xl border border-slate-200 bg-white px-3 py-2" data-testid="perimetre"><AdminIcon name="layers" :size="16" class="mr-1 inline" />Périmètre : Tout le site</p>
        <p class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-600" role="status" data-testid="fraicheur">Actualisé à <strong>{{ hhmm(general.donnees.actualise) }}</strong></p>
      </div>
    </header>

    <!-- Indicateurs -->
    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7" data-testid="indicateurs-globaux">
      <div v-for="k in kpis" :key="k.key" class="flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 p-3" :class="k.tone.split(' ')[0]">
        <span :class="k.tone.split(' ')[1]"><AdminIcon :name="k.icon" :size="30" /></span>
        <div class="min-w-0"><dd class="text-2xl font-extrabold leading-tight text-slate-900" :data-testid="'ind-' + k.key">{{ num(k.value) }}</dd><dt class="text-sm leading-tight text-slate-700">{{ k.label }}</dt></div>
      </div>
    </dl>

    <!-- Accès, mouvements, site -->
    <div class="grid items-stretch gap-4 lg:grid-cols-[1.35fr_1.15fr_.9fr]">
      <section class="admin-card" aria-labelledby="acces-titre" data-testid="bloc-acces">
        <h2 id="acces-titre" class="text-lg font-extrabold">Activité des accès <span class="font-normal text-slate-600">(24 dernières heures)</span></h2>
        <svg viewBox="0 0 620 235" class="mt-2 w-full" role="img" aria-label="Entrées et sorties par heure sur les 24 dernières heures" data-testid="graphique-acces">
          <g v-for="t in ticks" :key="t.y"><line x1="40" :x2="620" :y1="t.y" :y2="t.y" stroke="#e2e8f0" /><text x="34" :y="t.y + 4" text-anchor="end" font-size="11" fill="#475569">{{ t.label }}</text></g>
          <g v-for="(b, n) in bars" :key="n" :transform="'translate(' + (46 + n * 24) + ',0)'">
            <rect x="0" :y="200 - b.he" width="9" :height="b.he" fill="#1d4ed8" rx="1"><title>{{ b.heure }} h : {{ b.entrees }} entrée(s)</title></rect>
            <rect x="10" :y="200 - b.hs" width="9" :height="b.hs" fill="#7cb4f5" rx="1"><title>{{ b.heure }} h : {{ b.sorties }} sortie(s)</title></rect>
            <text v-if="n % 2 === 0" x="9" y="218" text-anchor="middle" font-size="11" fill="#475569">{{ b.heure }}h</text>
          </g>
        </svg>
        <p class="flex flex-wrap items-center justify-center gap-4 text-sm" data-testid="legende-acces"><span><span class="mr-1 inline-block size-3 rounded-full bg-blue-700" aria-hidden="true" />Entrées ({{ totalEntrees }})</span><span><span class="mr-1 inline-block size-3 rounded-full bg-sky-300" aria-hidden="true" />Sorties ({{ totalSorties }})</span><span class="text-xs text-slate-500">heures UTC</span></p>
      </section>

      <section class="admin-card" aria-labelledby="mouv-titre" data-testid="derniers-mouvements">
        <div class="flex items-center justify-between gap-2"><h2 id="mouv-titre" class="text-lg font-extrabold">Mouvements récents</h2><Link v-if="can('pilotage.consulter')" href="/administration/operations" class="text-sm font-semibold text-blue-800 underline" data-testid="vers-operations">Voir tout →</Link></div>
        <p v-if="!op.derniers.length" class="mt-3 text-slate-600" data-testid="aucun-mouvement">Aucun mouvement enregistré pour le moment.</p>
        <ul v-else class="mt-2 divide-y">
          <li v-for="(m, n) in op.derniers" :key="n" class="flex items-center gap-2 py-2">
            <span class="w-11 shrink-0 text-sm text-slate-600">{{ hhmm(m.at) }}</span>
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-200 text-xs font-bold text-slate-700" aria-hidden="true">{{ initials(m.name) }}</span>
            <span class="min-w-0 grow"><strong class="block truncate">{{ m.name ?? 'Personne' }}</strong><span class="block truncate text-xs text-slate-600">{{ typeLabel[m.type] ?? m.type }}<template v-if="m.checkpoint"> – {{ m.checkpoint }}</template> · {{ m.context }}</span></span>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold" :class="m.resultat === 'refuse' ? 'bg-red-100 text-red-900' : 'bg-green-100 text-green-900'">{{ m.resultat === 'refuse' ? 'Refusé' : 'Autorisé' }}</span>
          </li>
        </ul>
      </section>

      <section class="admin-card" aria-labelledby="site-titre" data-testid="bloc-site">
        <div class="flex items-center justify-between gap-2"><h2 id="site-titre" class="text-lg font-extrabold">État du site</h2></div>
        <ul class="mt-2 divide-y text-sm" data-testid="site-postes">
          <li class="flex items-center justify-between gap-2 py-2"><span>Postes de contrôle</span><span class="flex items-center gap-2"><strong>{{ op.postes.total }}</strong><span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="op.postes.actifs === op.postes.total ? 'bg-green-100 text-green-900' : 'bg-amber-100 text-amber-900'">{{ op.postes.actifs === op.postes.total ? 'Tous actifs' : op.postes.actifs + ' actifs' }}</span></span></li>
          <li class="flex items-center justify-between gap-2 py-2"><span>Appareils enrôlés</span><span class="flex items-center gap-2"><strong>{{ op.postes.appareils_enroles }}</strong><span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="op.postes.appareils_silencieux > 0 ? 'bg-red-100 text-red-900' : 'bg-green-100 text-green-900'">{{ op.postes.appareils_silencieux > 0 ? op.postes.appareils_silencieux + ' silencieux' : 'Synchronisés' }}</span></span></li>
          <li class="flex items-center justify-between gap-2 py-2"><span>Synchronisation</span><span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-800" data-testid="derniere-synchro">{{ sync }}</span></li>
          <li class="flex items-center justify-between gap-2 py-2"><span>Personnes présentes</span><strong data-testid="site-presents">{{ op.presents.total }}</strong></li>
        </ul>
        <p class="mt-2 text-xs text-slate-600" data-testid="site-synthese">Présents = identités distinctes sur tout le site. L’état « synchronisé » repose sur la dernière synchronisation reçue, jamais sur une simple connexion.</p>
      </section>
    </div>

    <!-- Activités, visites, badges -->
    <div class="grid items-stretch gap-4 lg:grid-cols-3">
      <section class="admin-card" aria-labelledby="act-titre" data-testid="bloc-activites">
        <div class="flex items-center justify-between gap-2"><h2 id="act-titre" class="text-lg font-extrabold">Activités en cours</h2><Link v-if="can('evenements.gerer')" href="/administration/evenements" class="text-sm font-semibold text-blue-800 underline" data-testid="vers-registre-activites">Voir tout →</Link></div>
        <p v-if="!general.activites.length" class="mt-3 text-slate-600" data-testid="aucune-activite-ouverte">Aucune activité ouverte ou à venir.</p>
        <ul v-else class="mt-2 space-y-3" data-testid="liste-activites">
          <li v-for="a in general.activites.slice(0, 2)" :key="a.id" class="space-y-1" :data-testid="'activite-' + a.id">
            <div class="flex items-center justify-between gap-2"><Link :href="'/administration/evenements?activite=' + a.id" class="truncate font-bold">{{ a.name }}</Link><span class="shrink-0 rounded-full bg-green-100 px-2 py-0.5 text-xs font-bold text-green-900">{{ phase[a.phase] ?? a.phase }}</span></div>
            <p class="text-xs text-slate-600">{{ periode(a) }}<template v-if="a.site"> · {{ a.site }}</template></p>
            <p class="text-sm" :data-testid="'effectifs-' + a.id">Participants : {{ a.participants }} · Attendus : {{ a.attendus }} · Arrivés : {{ a.arrivees }} · Présents : {{ a.presents }}</p>
            <div class="flex items-center gap-2" :aria-label="'Arrivées confirmées : ' + progress(a) + ' %'"><div class="h-2.5 grow overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-green-600" :style="{ width: progress(a) + '%' }" /></div><span class="text-sm font-bold">{{ progress(a) }} %</span></div>
          </li>
        </ul>
      </section>

      <section class="admin-card" aria-labelledby="visites-titre" data-testid="bloc-visites">
        <h2 id="visites-titre" class="text-lg font-extrabold">Visites et véhicules</h2>
        <ul class="mt-2 divide-y" data-testid="site-visites">
          <li class="flex items-center justify-between py-2"><span>Visites annoncées</span><strong data-testid="visites-annoncees">{{ op.visites_annoncees.total }}</strong></li>
          <li class="flex items-center justify-between py-2"><span>Visites en retard</span><strong data-testid="visites-retard">{{ op.visites_annoncees.en_retard }}</strong></li>
          <li class="flex items-center justify-between py-2"><span>Véhicules présents</span><strong data-testid="vehicules-presents">{{ op.vehicules_presents }}</strong></li>
        </ul>
      </section>

      <section class="admin-card" aria-labelledby="badges-titre" data-testid="bloc-badges">
        <div class="flex items-center justify-between gap-2"><h2 id="badges-titre" class="text-lg font-extrabold">Badges et cartes</h2><Link v-if="can('badges.gerer')" href="/administration/badges" class="text-sm font-semibold text-blue-800 underline" data-testid="vers-badges">Voir →</Link></div>
        <ul class="mt-2 divide-y">
          <li class="flex items-center justify-between py-2"><span>Badges temporaires associés</span><strong data-testid="badges-associes">{{ general.badges.temporaires.associes }}</strong></li>
          <li class="flex items-center justify-between py-2"><span>Badges non restitués</span><strong data-testid="badges-non-restitues">{{ general.badges.temporaires.non_restitues }}</strong></li>
          <li class="flex items-center justify-between py-2"><span>Cartes permanentes remises (actives)</span><strong data-testid="cartes-remises">{{ cards.remises }}</strong></li>
          <li class="flex items-center justify-between py-2"><span>Cartes émises, non remises</span><strong data-testid="cartes-non-remises">{{ cards.non_remises }}</strong></li>
          <li class="flex items-center justify-between py-2"><span>Cartes révoquées / perdues</span><strong data-testid="cartes-retirees">{{ cards.retirees }}</strong></li>
        </ul>
        <p class="mt-1 text-xs text-slate-600" data-testid="emis-non-imprimes">Émis, jamais imprimés : {{ general.badges.impression.emis_non_imprimes }} · un support émis n’est pas imprimé, remis ni actif tant que ces étapes n’ont pas eu lieu.</p>
      </section>
    </div>

    <!-- Accès rapides, alertes -->
    <div class="grid items-stretch gap-4 lg:grid-cols-[1.1fr_1fr]">
      <section class="admin-card" aria-labelledby="rapide-titre" data-testid="acces-rapides">
        <h2 id="rapide-titre" class="text-lg font-extrabold">Accès rapides</h2>
        <nav class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3" aria-label="Accès rapides">
          <Link v-for="q in quick" :key="q.key" :href="q.href" class="flex min-h-20 flex-col items-center justify-center gap-1 rounded-xl border border-slate-200 p-2 text-center text-sm font-semibold" :class="q.tone" :data-testid="'rapide-' + q.key"><AdminIcon :name="q.icon" :size="26" />{{ q.label }}</Link>
        </nav>
      </section>

      <section class="admin-card" aria-labelledby="priorites-titre" data-testid="priorites">
        <div class="flex items-center justify-between gap-2"><h2 id="priorites-titre" class="text-lg font-extrabold">Alertes et actions</h2><Link v-if="can('alertes.consulter')" href="/pilotage/alertes" class="text-sm font-semibold text-blue-800 underline" data-testid="vers-alertes">Voir toutes →</Link></div>
        <p v-if="!general.priorites.length" class="mt-3 font-semibold text-green-900" data-testid="aucune-priorite">✓ Aucune situation ne demande d’intervention administrative.</p>
        <ul v-else class="mt-2 divide-y">
          <li v-for="p in general.priorites.slice(0, 5)" :key="p.key" class="flex flex-wrap items-center gap-2 py-2" :data-testid="'priorite-' + p.key">
            <span class="size-2.5 shrink-0 rounded-full" :class="severity[p.severity].dot" aria-hidden="true" />
            <div class="min-w-0 grow"><p class="truncate text-sm font-semibold">{{ p.count > 1 || p.key !== 'sauvegarde' ? p.count + ' · ' : '' }}{{ p.title }}</p><p class="text-xs text-slate-600">Statut : {{ p.status }}</p></div>
            <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="severity[p.severity].pill">{{ severity[p.severity].label }}</span>
            <Link v-if="p.href" :href="p.href" class="text-sm font-semibold text-blue-800 underline" :data-testid="'voir-' + p.key">Voir</Link>
            <span v-else class="text-xs text-slate-600" :data-testid="'sans-lien-' + p.key">Relève d’un autre rôle</span>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
