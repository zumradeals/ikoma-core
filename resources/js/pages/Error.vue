<script setup lang="ts">
import BaseLayout from '../layouts/BaseLayout.vue'; import BaseCard from '../components/BaseCard.vue';
defineProps<{ status: number }>();
</script>
<template><BaseLayout><BaseCard><h1 class="mb-4 text-display font-bold">{{ status === 429 ? 'Trop de tentatives' : 'Accès non autorisé' }}</h1><p>{{ status === 429 ? 'Attendez une minute avant de réessayer.' : 'Votre rôle ne permet pas cette action.' }}</p><a class="action mt-6" href="/espace">Revenir à mon espace</a></BaseCard></BaseLayout></template>
