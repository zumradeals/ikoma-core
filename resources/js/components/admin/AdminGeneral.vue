<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminIcon from './AdminIcon.vue';
import { displayDate } from '../../labels';

/**
 * Tableau de bord général de l'administrateur : vue institutionnelle transversale, en LECTURE SEULE. Les chiffres viennent des services canoniques ; chaque priorité renvoie vers sa
 * fonction d'origine. Aucun formulaire ni moteur métier ici ; les détails restent dans Opérations, Pilotage, Badges et cartes et Personnes.
 */
type Movement = { at: string; type: string; checkpoint: string | null; context: string; name: string | null };
type Activity = { id: string; name: string; kind: string; site: string | null; starts_at: string; ends_at: string; phase: string; participants: number; attendus: number; arrivees: number; presents: number };
type Priority = { key: string; severity: 'haute' | 'normale' | 'info'; title: string; text: string; count: number; status: string; href: string | null };
export type General = {
  as_of: string; perimetre: string; donnees: { sauvegarde: string; actualise: string };
  indicateurs: { personnes: { enregistrees: number }; habilitations: { actives: number; a_echeance: number; jours: number }; activites: { en_cours: number; a_venir: number; participants_actifs: number };
    supports: { cartes_remises: number; badges_associes: number }; presents: { total: number; permanents: number; activites: number }; alertes: { a_traiter: number; litiges: number } };
  activites: Activity[];
  personnes: { enregistrees: number; actives: number; a_echeance: number; jours: number; retirees_ou_expirees: number; verifications: { homonymes: number; titulaires_sans_categorie: number; titulaires_sans_photo: number } };
  badges: { cartes: { actives: number; remises: number; non_remises: number; imprimees_non_remises: number; retirees: number }; temporaires: { associes: number; non_restitues: number }; impression: { emis_non_imprimes: number } };
  operations: { mouvements: { entrees: number; sorties: number; controles: number; refus: number }; presents: { total: number; permanents: number; activites: number; multi_contextes: number };
    postes: { total: number; actifs: number; appareils_enroles: number; appareils_synchronises: number; appareils_silencieux: number }; visites_annoncees: { total: number; en_retard: number }; derniers: Movement[] };
  priorites: Priority[];
};
const props = defineProps<{ general: General }>();
const page = usePage<{ auth?: { permissions: string[] } | null }>();
const can = (permission: string) => page.props.auth?.permissions?.includes(permission) ?? false;
const time = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
const plural = (n: number, one: string, many: string) => `${n} ${n > 1 ? many : one}`;
const backup = computed(() => ({ inactif: 'Sauvegarde inactive', jamais: 'Aucune sauvegarde réussie', ancienne: 'Sauvegarde ancienne', echec: 'Dernière sauvegarde en échec' } as Record<string, string>)[props.general.donnees.sauvegarde] ?? 'Sauvegarde à jour');
const phase: Record<string, string> = { en_cours: 'En cours', a_venir: 'À venir' };
const typeLabel: Record<string, string> = { entree: 'Entrée', sortie: 'Sortie', controle: 'Contrôle' };
const severity = { haute: { label: 'Important', pill: 'bg-red-100 text-red-900' }, normale: { label: 'À prévoir', pill: 'bg-amber-100 text-amber-900' }, info: { label: 'À savoir', pill: 'bg-sky-100 text-sky-900' } } as const;
const i = computed(() => props.general.indicateurs);
const quick = computed(() => [
  { key: 'personne', label: 'Ajouter une personne', href: '/administration/personnes', icon: 'users', show: can('listes.importer') },
  { key: 'habilitations', label: 'Gérer les habilitations', href: '/administration/habilitations', icon: 'shield', show: can('listes.importer') },
  { key: 'activite', label: 'Créer une activité', href: '/administration/evenements', icon: 'calendar', show: can('evenements.gerer') },
  { key: 'badges', label: 'Préparer des badges', href: '/administration/badges/preparer', icon: 'qr', show: can('badges.gerer') && can('listes.importer') },
  { key: 'operations', label: 'Voir les opérations', href: '/administration/operations', icon: 'monitor', show: can('pilotage.consulter') },
  { key: 'journal', label: 'Consulter le journal', href: '/administration/journal', icon: 'book', show: can('journal.consulter') },
].filter(q => q.show));
</script>

<template>
  <div class="space-y-5" data-testid="tableau-general">
    <!-- A — En-tête -->
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-display font-bold" data-testid="titre-general">Tableau de bord</h1>
        <p class="admin-muted mt-1">Vue d’ensemble de l’AILCT · <span data-testid="perimetre">Périmètre : {{ general.perimetre }}</span></p>
      </div>
      <p class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" role="status" data-testid="fraicheur">Actualisé à <strong>{{ time(general.donnees.actualise) }}</strong> · <span :class="['jamais', 'echec', 'ancienne'].includes(general.donnees.sauvegarde) ? 'font-bold text-amber-900' : ''">{{ backup }}</span></p>
    </header>

    <!-- B — Indicateurs globaux -->
    <dl class="grid grid-cols-2 gap-3 xl:grid-cols-6" data-testid="indicateurs-globaux">
      <div class="site-kpi min-w-0 bg-sky-50"><span class="site-kpi-icon text-sky-700"><AdminIcon name="users" :size="34" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="ind-personnes">{{ i.personnes.enregistrees }}</dd><dt class="font-semibold">Personnes enregistrées</dt></div></div>
      <div class="site-kpi min-w-0 bg-emerald-50"><span class="site-kpi-icon text-emerald-700"><AdminIcon name="shield" :size="34" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="ind-habilitations">{{ i.habilitations.actives }}</dd><dt class="font-semibold">Habilitations actives</dt><small class="text-slate-600">dont {{ i.habilitations.a_echeance }} à échéance ({{ i.habilitations.jours }} j)</small></div></div>
      <div class="site-kpi min-w-0 bg-indigo-50"><span class="site-kpi-icon text-indigo-700"><AdminIcon name="calendar" :size="34" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="ind-activites">{{ i.activites.en_cours }}</dd><dt class="font-semibold">Activités en cours</dt><small class="text-slate-600">{{ i.activites.a_venir }} à venir</small></div></div>
      <div class="site-kpi min-w-0 bg-amber-50"><span class="site-kpi-icon text-amber-700"><AdminIcon name="qr" :size="34" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="ind-supports">{{ i.supports.cartes_remises + i.supports.badges_associes }}</dd><dt class="font-semibold">Supports actifs</dt><small class="text-slate-600">{{ i.supports.cartes_remises }} cartes remises · {{ i.supports.badges_associes }} badges associés</small></div></div>
      <div class="site-kpi min-w-0 bg-teal-50"><span class="site-kpi-icon text-teal-700"><AdminIcon name="pin" :size="34" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="ind-presents">{{ i.presents.total }}</dd><dt class="font-semibold">Personnes présentes</dt><small class="text-slate-600">identités distinctes · site entier</small></div></div>
      <div class="site-kpi min-w-0 bg-rose-50"><span class="site-kpi-icon text-rose-700"><AdminIcon name="alert" :size="34" /></span><div class="min-w-0 break-words"><dd class="text-3xl font-extrabold" data-testid="ind-alertes">{{ i.alertes.a_traiter + i.alertes.litiges }}</dd><dt class="font-semibold">À traiter</dt><small class="text-slate-600">{{ i.alertes.a_traiter }} alertes · {{ i.alertes.litiges }} cas à décider</small></div></div>
    </dl>

    <!-- G — Alertes et actions prioritaires -->
    <section class="admin-card" aria-labelledby="priorites-titre" data-testid="priorites">
      <h2 id="priorites-titre" class="text-xl font-extrabold">Alertes et actions prioritaires</h2>
      <p v-if="!general.priorites.length" class="mt-3 font-semibold text-green-900" data-testid="aucune-priorite">✓ Aucune situation ne demande d’intervention administrative.</p>
      <ul v-else class="mt-3 space-y-3">
        <li v-for="p in general.priorites" :key="p.key" class="flex flex-wrap items-start gap-3 rounded-xl border border-slate-200 p-3" :data-testid="'priorite-' + p.key">
          <span class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="severity[p.severity].pill">{{ severity[p.severity].label }}</span>
          <div class="min-w-0 grow"><p class="font-bold">{{ p.count > 1 || p.key !== 'sauvegarde' ? p.count + ' · ' : '' }}{{ p.title }}</p><p class="text-sm text-slate-600">{{ p.text }}</p><p class="text-xs font-semibold text-slate-700">Statut : {{ p.status }}</p></div>
          <Link v-if="p.href" :href="p.href" class="site-btn site-btn-outline !min-h-11" :data-testid="'voir-' + p.key">Voir</Link>
          <span v-else class="text-xs text-slate-600" :data-testid="'sans-lien-' + p.key">Relève d’un autre rôle</span>
        </li>
      </ul>
    </section>

    <div class="grid gap-5 xl:grid-cols-2">
      <!-- C — Activités et participations -->
      <section class="admin-card space-y-3" aria-labelledby="act-titre" data-testid="bloc-activites">
        <div class="flex items-center justify-between gap-3"><h2 id="act-titre" class="text-xl font-extrabold">Activités et participations</h2><Link v-if="can('evenements.gerer')" href="/administration/evenements" class="text-sm font-semibold underline" data-testid="vers-registre-activites">Registre des activités</Link></div>
        <p v-if="!general.activites.length" class="text-slate-600" data-testid="aucune-activite-ouverte">Aucune activité ouverte ou à venir.</p>
        <ul v-else class="divide-y" data-testid="liste-activites">
          <li v-for="a in general.activites" :key="a.id" class="space-y-1 py-3" :data-testid="'activite-' + a.id">
            <div class="flex flex-wrap items-center justify-between gap-2"><Link :href="'/administration/evenements?activite=' + a.id" class="font-bold underline">{{ a.name }}</Link><span class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="a.phase === 'en_cours' ? 'bg-green-100 text-green-900' : 'bg-amber-100 text-amber-900'">{{ phase[a.phase] ?? a.phase }}</span></div>
            <p class="text-sm text-slate-600">{{ displayDate(a.starts_at) }} → {{ displayDate(a.ends_at) }}<template v-if="a.site"> · {{ a.site }}</template></p>
            <p class="text-sm" :data-testid="'effectifs-' + a.id">{{ plural(a.participants, 'participant', 'participants') }} · attendus {{ a.attendus }} · arrivées confirmées {{ a.arrivees }} · présents {{ a.presents }}</p>
          </li>
        </ul>
      </section>

      <!-- D — Personnes et habilitations -->
      <section class="admin-card space-y-3" aria-labelledby="pers-titre" data-testid="bloc-personnes">
        <div class="flex items-center justify-between gap-3"><h2 id="pers-titre" class="text-xl font-extrabold">Personnes et habilitations</h2><Link v-if="can('listes.importer')" href="/administration/personnes" class="text-sm font-semibold underline">Registre des personnes</Link></div>
        <p class="text-sm text-slate-700">Une personne enregistrée n’a pas pour autant d’habilitation active : les deux se suivent séparément.</p>
        <dl class="grid grid-cols-2 gap-2 text-sm">
          <div><dt>Personnes enregistrées</dt><dd class="text-xl font-bold" data-testid="pers-enregistrees">{{ general.personnes.enregistrees }}</dd></div>
          <div><dt>Dont actives</dt><dd class="text-xl font-bold">{{ general.personnes.actives }}</dd></div>
          <div><dt>Habilitations à échéance ({{ general.personnes.jours }} j)</dt><dd class="text-xl font-bold" data-testid="pers-echeance">{{ general.personnes.a_echeance }}</dd></div>
          <div><dt>Retirées ou expirées</dt><dd class="text-xl font-bold" data-testid="pers-terminees">{{ general.personnes.retirees_ou_expirees }}</dd></div>
        </dl>
        <p class="text-sm font-semibold">Dossiers à vérifier</p>
        <ul class="text-sm" data-testid="pers-verifications"><li>Noms identiques : {{ general.personnes.verifications.homonymes }}</li><li>Titulaires sans catégorie institutionnelle : {{ general.personnes.verifications.titulaires_sans_categorie }}</li><li>Titulaires sans photo : {{ general.personnes.verifications.titulaires_sans_photo }}</li></ul>
      </section>

      <!-- E — Badges et cartes -->
      <section class="admin-card space-y-3" aria-labelledby="badges-titre" data-testid="bloc-badges">
        <div class="flex items-center justify-between gap-3"><h2 id="badges-titre" class="text-xl font-extrabold">Badges et cartes</h2><Link v-if="can('badges.gerer')" href="/administration/badges" class="text-sm font-semibold underline">Badges et cartes</Link></div>
        <p class="text-sm text-slate-700">Un support émis n’est pas imprimé, remis ni actif tant que ces étapes n’ont pas eu lieu.</p>
        <dl class="grid grid-cols-2 gap-2 text-sm">
          <div><dt>Cartes permanentes émises</dt><dd class="text-xl font-bold" data-testid="cartes-emises">{{ general.badges.cartes.actives }}</dd></div>
          <div><dt>Dont remises (actives)</dt><dd class="text-xl font-bold" data-testid="cartes-remises">{{ general.badges.cartes.remises }}</dd></div>
          <div><dt>Imprimées, non remises</dt><dd class="text-xl font-bold" data-testid="cartes-imprimees-non-remises">{{ general.badges.cartes.imprimees_non_remises }}</dd></div>
          <div><dt>Retirées (révoquées, perdues, remplacées)</dt><dd class="text-xl font-bold" data-testid="cartes-retirees">{{ general.badges.cartes.retirees }}</dd></div>
          <div><dt>Badges temporaires associés</dt><dd class="text-xl font-bold" data-testid="badges-associes">{{ general.badges.temporaires.associes }}</dd></div>
          <div><dt>Badges non restitués</dt><dd class="text-xl font-bold" data-testid="badges-non-restitues">{{ general.badges.temporaires.non_restitues }}</dd></div>
        </dl>
        <p class="text-sm" data-testid="emis-non-imprimes">Supports émis, jamais imprimés : <strong>{{ general.badges.impression.emis_non_imprimes }}</strong></p>
      </section>

      <!-- F — Situation opérationnelle du site -->
      <section class="admin-card space-y-3" aria-labelledby="site-titre" data-testid="bloc-site">
        <div class="flex items-center justify-between gap-3"><h2 id="site-titre" class="text-xl font-extrabold">Situation du site</h2><Link v-if="can('pilotage.consulter')" href="/administration/operations" class="text-sm font-semibold underline" data-testid="vers-operations">Opérations</Link></div>
        <p class="text-sm text-slate-700" data-testid="site-synthese">{{ plural(general.operations.presents.total, 'personne présente', 'personnes présentes') }} (identités distinctes) · 24 h : {{ general.operations.mouvements.entrees }} entrées, {{ general.operations.mouvements.sorties }} sorties, {{ general.operations.mouvements.refus }} accès refusés</p>
        <p class="text-sm" data-testid="site-postes">Postes administrativement actifs : <strong>{{ general.operations.postes.actifs }}</strong> sur {{ general.operations.postes.total }} · appareils synchronisés récemment : <strong>{{ general.operations.postes.appareils_synchronises }}</strong> · silencieux : <strong>{{ general.operations.postes.appareils_silencieux }}</strong></p>
        <p class="text-sm" data-testid="site-visites">Visites annoncées : <strong>{{ general.operations.visites_annoncees.total }}</strong> dont {{ general.operations.visites_annoncees.en_retard }} en retard</p>
        <p class="text-sm font-semibold">Derniers mouvements confirmés</p>
        <ul v-if="general.operations.derniers.length" class="divide-y text-sm" data-testid="derniers-mouvements"><li v-for="(m, k) in general.operations.derniers" :key="k" class="flex flex-wrap justify-between gap-2 py-1.5"><span><strong>{{ time(m.at) }}</strong> {{ m.name ?? 'Personne' }}</span><span>{{ typeLabel[m.type] ?? m.type }}<template v-if="m.checkpoint"> · {{ m.checkpoint }}</template> · {{ m.context }}</span></li></ul>
        <p v-else class="text-sm text-slate-600" data-testid="aucun-mouvement">Aucun mouvement confirmé.</p>
        <p class="text-xs text-slate-600">Le détail et les décisions de contrôle restent dans Opérations et dans l’espace Superviseur.</p>
      </section>
    </div>

    <!-- H — Accès rapides -->
    <section class="admin-card" aria-labelledby="acces-titre" data-testid="acces-rapides">
      <h2 id="acces-titre" class="text-xl font-extrabold">Accès rapides</h2>
      <ul class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6"><li v-for="q in quick" :key="q.key"><Link :href="q.href" class="flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border border-slate-200 p-2 text-center text-sm font-semibold hover:bg-blue-50" :data-testid="'rapide-' + q.key"><AdminIcon :name="q.icon" :size="24" />{{ q.label }}</Link></li></ul>
    </section>
  </div>
</template>
