<script setup lang="ts">
import { ref } from 'vue'; import { Link } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import CategoryForm from '../../components/CategoryForm.vue'; import CategorySwatch from '../../components/CategorySwatch.vue';
defineProps<{ events: { id: string; name: string }[]; zones: { id: string; name: string; event_id: string }[]; categories: { id: string; name: string; color: string; priority_circuit: boolean; event_id: string; event_name: string; zone_ids: string[]; zone_names: string[] }[] }>();
const editing = ref<string | null>(null);
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link><h1 class="mb-4 text-display font-bold">Administration · Catégories</h1><p class="mb-6">Chaque visiteur reçoit les accès de sa catégorie. La couleur est toujours accompagnée d'un libellé.</p>
<div class="space-y-6">
<BaseCard><h2 class="mb-5 text-heading font-bold">Créer une catégorie</h2><CategoryForm :events="events" :zones="zones" /></BaseCard>
<BaseCard v-for="category in categories" :key="category.id"><CategorySwatch :name="category.name" :color="category.color" :priority="category.priority_circuit" /><p class="mt-4">{{ category.event_name }}</p><p class="my-4">Zones autorisées : {{ category.zone_names.join(', ') || 'Aucune' }}</p><button type="button" class="action" :aria-expanded="editing === category.id" @click="editing = editing === category.id ? null : category.id">{{ editing === category.id ? 'Fermer' : 'Modifier' }}</button><CategoryForm v-if="editing === category.id" class="mt-6" :category="category" :events="events" :zones="zones" /></BaseCard>
</div><p v-if="!categories.length" class="mt-6">Aucune catégorie enregistrée.</p>
</BaseLayout>
</template>
