<script setup lang="ts">
import { ref } from 'vue'; import { Link } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import ZoneForm from '../../components/ZoneForm.vue';
defineProps<{ events: { id: string; name: string }[]; zones: { id: string; name: string; event_id: string; event_name: string; est_accueil: boolean }[] }>();
const editing = ref<string | null>(null);
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link><h1 class="mb-4 text-display font-bold">Administration · Zones</h1><p class="mb-6">Définissez les espaces du site. Les accès se règlent ensuite dans les catégories.</p>
<div class="space-y-6">
<BaseCard><h2 class="mb-5 text-heading font-bold">Créer une zone</h2><ZoneForm :events="events" /></BaseCard>
<BaseCard v-for="zone in zones" :key="zone.id"><h2 class="text-heading font-bold">{{ zone.name }}</h2><p class="mb-4">{{ zone.event_name }}<template v-if="zone.est_accueil"> · Zone d’accueil</template></p><button type="button" class="action" :aria-expanded="editing === zone.id" @click="editing = editing === zone.id ? null : zone.id">{{ editing === zone.id ? 'Fermer' : 'Modifier' }}</button><ZoneForm v-if="editing === zone.id" class="mt-6" :zone="zone" :events="events" /></BaseCard>
</div><p v-if="!zones.length" class="mt-6">Aucune zone enregistrée.</p>
</BaseLayout>
</template>
