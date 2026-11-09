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
    <div class="mb-6 grid gap-4 sm:grid-cols-2" role="group" aria-label="Type de modèle">
      <button type="button" class="rounded-xl border-2 p-4 text-left" :class="section === 'badges' ? 'border-blue-600 bg-blue-50' : 'bg-white'" :aria-pressed="section === 'badges'" data-testid="modele-type-badges" @click="section = 'badges'"><span class="block text-heading font-bold">Badge visiteur</span><span class="block text-sm">Un modèle par activité</span></button>
      <button type="button" class="rounded-xl border-2 p-4 text-left" :class="section === 'cartes' ? 'border-blue-600 bg-blue-50' : 'bg-white'" :aria-pressed="section === 'cartes'" data-testid="modele-type-cartes" @click="section = 'cartes'"><span class="block text-heading font-bold">Carte permanente</span><span class="block text-sm">Un modèle par catégorie institutionnelle</span></button>
    </div>
    <BadgeModelSection v-if="section === 'badges'" :events="events" :preferences="preferences" :initial-event="initialEvent" />
    <CardModelSection v-else v-bind="cards" base-url="/administration/modeles" :extra-query="{ section: 'cartes' }" />
  </AdminLayout>
</template>
