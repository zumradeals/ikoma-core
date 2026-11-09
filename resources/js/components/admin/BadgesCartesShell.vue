<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

/**
 * Cadre de l'espace « Badges et cartes » : quatre responsabilités et leurs compteurs. Ce n'est pas une page : chaque onglet est la page existante de sa responsabilité.
 * Un onglet n'est affiché que si l'utilisateur détient la permission de sa page d'arrivée ; le serveur reste l'autorité.
 */
type Summary = { visitors: { available: number; to_return: number; batches: number; print_runs: number }; cards: { holders: number; active: number; prints: number } };
defineProps<{ active: 'visiteurs' | 'cartes' | 'modeles' | 'impression' }>();
const page = usePage<{ auth?: { permissions: string[] } | null; badgesCartes?: Summary | null }>();
const can = (permission: string) => page.props.auth?.permissions?.includes(permission) ?? false;
const tabs = [
  { key: 'visiteurs', label: 'Badges visiteurs', hint: 'Lots, stock, historique', href: '/administration/badges', permission: 'badges.gerer' },
  { key: 'cartes', label: 'Cartes permanentes', hint: 'Personnel permanent et cartes', href: '/administration/titulaires', permission: 'listes.importer' },
  { key: 'modeles', label: 'Modèles', hint: 'Aspect des badges et des cartes', href: '/administration/modeles', permission: 'badges.gerer' },
  { key: 'impression', label: 'Impression', hint: 'Préparer, imprimer, suivre', href: '/administration/atelier', permission: 'badges.gerer' },
] as const;
const shown = computed(() => tabs.filter(tab => can(tab.permission)));
const summary = computed(() => page.props.badgesCartes ?? null);
</script>

<template>
  <div class="mb-6 space-y-4" data-testid="cadre-badges-cartes">
    <nav aria-label="Badges et cartes">
      <ul class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <li v-for="tab in shown" :key="tab.key">
          <Link :href="tab.href" class="block min-h-14 rounded-xl border-2 p-3 text-left" :class="active === tab.key ? 'border-blue-600 bg-blue-50' : 'border-slate-300 bg-white'" :aria-current="active === tab.key ? 'page' : undefined" :data-testid="'onglet-' + tab.key">
            <span class="block text-heading font-bold">{{ tab.label }}</span><span class="block text-sm text-slate-700">{{ tab.hint }}</span>
          </Link>
        </li>
      </ul>
    </nav>
    <dl v-if="summary" class="grid grid-cols-2 gap-3 text-sm lg:grid-cols-4" data-testid="compteurs-badges-cartes">
      <div class="rounded-xl border border-slate-200 bg-white p-3"><dt>Badges disponibles</dt><dd class="text-heading font-bold" data-testid="compteur-disponibles">{{ summary.visitors.available }}</dd></div>
      <div class="rounded-xl border border-slate-200 bg-white p-3"><dt>Badges à restituer</dt><dd class="text-heading font-bold" data-testid="compteur-a-restituer">{{ summary.visitors.to_return }}</dd></div>
      <div class="rounded-xl border border-slate-200 bg-white p-3"><dt>Cartes actives</dt><dd class="text-heading font-bold" data-testid="compteur-cartes-actives">{{ summary.cards.active }}</dd></div>
      <div class="rounded-xl border border-slate-200 bg-white p-3"><dt>Tirages enregistrés</dt><dd class="text-heading font-bold" data-testid="compteur-tirages">{{ summary.visitors.print_runs + summary.cards.prints }}</dd></div>
    </dl>
  </div>
</template>
