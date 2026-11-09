<script setup lang="ts">
import { ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BadgesCartesShell from '../../components/admin/BadgesCartesShell.vue';
import BadgeModelSection from '../../components/admin/BadgeModelSection.vue';
import CardModelSection from '../../components/admin/CardModelSection.vue';
import type { CardModelProps } from '../../components/admin/card-model';

/** Modèles : l'aspect des badges visiteurs (par activité) et des cartes permanentes (par catégorie institutionnelle). Aucun droit d'accès n'est lié à un modèle. */
const props = defineProps<{
  events: { id: string; name: string }[]; preferences: Record<string, { design: string; title: string; primary: string; accent: string; orientation: string }>;
  initialEvent: string | null; section: 'badges' | 'cartes'; cards: CardModelProps;
}>();
const section = ref<'badges' | 'cartes'>(props.section);
</script>

<template>
  <AdminLayout active="modeles">
    <BadgesCartesShell active="modeles" />
    <div class="mb-6 space-y-2" data-testid="page-modeles">
      <h1 class="text-display font-bold">Modèles</h1>
      <p class="max-w-3xl">L’aspect de ce qui s’imprime. Un modèle ne donne <strong>aucun droit d’accès</strong> : il ne change que la présentation.</p>
    </div>
    <!-- Modèles institutionnels : un modèle par catégorie, pour les cartes permanentes ET les badges temporaires (référentiel unique). -->
    <CardModelSection v-bind="cards" base-url="/administration/modeles" :extra-query="{ section: 'cartes' }" />
    <!-- Ancien modèle des badges de lots, par activité : conservé pour le stock existant, hors du parcours normal. -->
    <details class="mt-8 rounded-xl border border-slate-300 bg-white p-4" :open="section === 'badges'" data-testid="modele-historique">
      <summary class="cursor-pointer font-bold" data-testid="modele-type-badges" @click="section = 'badges'">Ancien modèle des badges de lots, par activité (stock existant)</summary>
      <p class="mb-3 mt-2 text-sm">Ce modèle ne sert qu’à l’impression des lots historiques. Les badges préparés pour une personne suivent le modèle institutionnel de sa catégorie.</p>
      <BadgeModelSection :events="events" :preferences="preferences" :initial-event="initialEvent" />
    </details>
  </AdminLayout>
</template>
