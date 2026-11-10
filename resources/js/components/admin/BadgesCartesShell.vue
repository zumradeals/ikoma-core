<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminIcon from './AdminIcon.vue';

/**
 * Cadre de l'espace « Badges et cartes » : quatre onglets (registre et cartes, modèles institutionnels, impression et historique, lots et réservations) et quatre compteurs réels.
 * Ce n'est pas une page : chaque onglet est la page existante de sa responsabilité. « Préparer un badge » est l'action principale de l'espace de travail, pas un onglet.
 * Un onglet n'est affiché que si l'utilisateur détient la permission de sa page d'arrivée ; le serveur reste l'autorité.
 */
type Summary = {
  visitors: { available: number; to_return: number; batches: number; print_runs: number }; cards: { holders: number; active: number; prints: number };
  totals?: { supports_actifs: number; cartes_permanentes: number; badges_visiteurs: number; tirages_30j: number };
};
const props = defineProps<{ active: 'registre' | 'visiteurs' | 'lots' | 'cartes' | 'modeles' | 'impression' | 'preparer'; titre?: boolean }>();
const page = usePage<{ auth?: { permissions: string[] } | null; badgesCartes?: Summary | null }>();
const can = (permission: string) => page.props.auth?.permissions?.includes(permission) ?? false;
const tabs = [
  { key: 'registre', label: 'Registre et cartes', hint: 'Supports, préparer, consulter', href: '/administration/badges', permission: 'badges.gerer', icon: 'qr' },
  { key: 'modeles', label: 'Modèles institutionnels', hint: 'Aspect des badges et des cartes', href: '/administration/modeles', permission: 'badges.gerer', icon: 'pencil' },
  { key: 'impression', label: 'Impression et historique', hint: 'Suivi des tirages et réimpressions', href: '/administration/atelier', permission: 'badges.gerer', icon: 'print' },
  { key: 'lots', label: 'Lots et réservations', hint: 'Stock de badges, réservations aux postes et aux cars', href: '/administration/badges/lots', permission: 'badges.gerer', icon: 'layers' },
] as const;
const shown = computed(() => tabs.filter(tab => can(tab.permission)));
// La fiche d'un badge et la préparation relèvent de « Registre et cartes » ; les lots, réservations et mises à disposition (stock des visiteurs non annoncés) ont leur onglet.
const current = computed(() => (['visiteurs', 'preparer'].includes(props.active) ? 'registre' : props.active));
const summary = computed(() => page.props.badgesCartes ?? null);
const kpis = computed(() => {
  const t = summary.value?.totals;
  return t ? [
    { key: 'supports-actifs', label: 'Supports actifs', value: t.supports_actifs, tone: 'bg-blue-50 border-blue-200' },
    { key: 'cartes-permanentes', label: 'Cartes permanentes', value: t.cartes_permanentes, tone: 'bg-green-50 border-green-200' },
    { key: 'badges-visiteurs', label: 'Badges visiteurs', value: t.badges_visiteurs, tone: 'bg-violet-50 border-violet-200' },
    { key: 'tirages-30j', label: 'Tirages (30 j)', value: t.tirages_30j, tone: 'bg-slate-50 border-slate-200' },
  ] : [];
});
</script>

<template>
  <div class="mb-4 space-y-3" data-testid="cadre-badges-cartes">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div v-if="titre" class="min-w-0">
        <h1 class="text-display font-bold" data-testid="titre-badges-cartes">Badges et cartes</h1>
        <p class="admin-muted">Création, gestion et impression des badges visiteurs et des cartes permanentes.</p>
      </div>
      <Link v-else-if="can('badges.gerer') && can('listes.importer')" href="/administration/badges" class="admin-primary inline-flex min-h-14 items-center gap-2" data-testid="preparer-badge">Préparer un badge</Link>
      <dl v-if="kpis.length" class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4" data-testid="compteurs-badges-cartes">
        <div v-for="k in kpis" :key="k.key" class="min-w-[8.5rem] rounded-xl border p-2.5" :class="k.tone"><dd class="text-heading font-extrabold leading-tight" :data-testid="'compteur-' + k.key">{{ k.value.toLocaleString('fr-FR') }}</dd><dt class="text-slate-700">{{ k.label }}</dt></div>
      </dl>
    </div>
    <nav aria-label="Badges et cartes">
      <ul class="flex flex-wrap gap-2 border-b border-slate-200">
        <li v-for="tab in shown" :key="tab.key">
          <Link :href="tab.href" class="-mb-px flex min-h-12 items-center gap-2 border-b-4 px-3 font-semibold" :class="current === tab.key ? 'border-blue-600 text-blue-900' : 'border-transparent text-slate-700 hover:border-slate-300'" :aria-current="current === tab.key ? 'page' : undefined" :data-testid="'onglet-' + tab.key" :title="tab.hint">
            <AdminIcon :name="tab.icon" :size="18" />{{ tab.label }}
          </Link>
        </li>
      </ul>
    </nav>
  </div>
</template>
