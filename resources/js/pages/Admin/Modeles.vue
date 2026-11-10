<script setup lang="ts">
import AdminLayout from '../../layouts/AdminLayout.vue';
import BadgesCartesShell from '../../components/admin/BadgesCartesShell.vue';
import CardModelSection from '../../components/admin/CardModelSection.vue';
import type { CardModelProps } from '../../components/admin/card-model';

/** Modèles institutionnels : un modèle par catégorie, pour les cartes permanentes et les badges temporaires. Aucun droit d'accès n'est lié à un modèle. */
defineProps<{ cards: CardModelProps }>();
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
  </AdminLayout>
</template>
