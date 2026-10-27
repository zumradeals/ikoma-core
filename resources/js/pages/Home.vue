<script setup lang="ts">
import BaseLayout from '../layouts/BaseLayout.vue';
import BaseCard from '../components/BaseCard.vue';
import StatusBanner from '../components/StatusBanner.vue';
</script>
<template>
  <BaseLayout>
    <BaseCard>
      <p class="mb-3 font-semibold text-ikoma-blue">Bienvenue</p>
      <h1 class="mb-6 text-display font-bold leading-tight">IKOMA Accueil — en construction</h1>
      <p class="mb-8">Les fondations de l'application sont en préparation.</p>
      <StatusBanner message="Page provisoire : aucune opération de contrôle des visiteurs n'est disponible." />
    </BaseCard>
  </BaseLayout>
</template>
