<script setup lang="ts">
import BaseLayout from '../layouts/BaseLayout.vue'; import BaseCard from '../components/BaseCard.vue';
defineProps<{ status: number; retryAfter?: number }>();
</script>
<template><BaseLayout><BaseCard><h1 class="mb-4 text-display font-bold">{{ status >= 500 ? 'Service momentanément indisponible' : status === 429 ? 'Trop de tentatives' : 'Accès non autorisé' }}</h1><p>{{ status >= 500 ? 'Réessayez ou contactez le superviseur.' : status === 429 ? `Réessayez dans ${Math.max(1, Math.ceil((retryAfter ?? 60) / 60))} minute(s).` : 'Votre rôle ne permet pas cette action.' }}</p><a class="action mt-6" href="/espace">Revenir à mon espace</a></BaseCard></BaseLayout></template>
