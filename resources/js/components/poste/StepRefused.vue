<script setup lang="ts">
defineProps<{ name: string }>();
defineEmits<{ next: [] }>();
</script>
<template>
  <section aria-labelledby="refus-titre" class="space-y-5">
    <h1 id="refus-titre" class="text-display font-bold"><span aria-hidden="true">✕</span> Accès refusé</h1>
    <div class="rounded-2xl border-2 border-red-700 bg-red-50 p-5"><p class="text-xl font-semibold">Le refus de {{ name }} est enregistré avec son motif. Aucun badge n’est associé.</p><p class="mt-2">Le superviseur sera alerté et décidera de la suite (écran de pilotage : lot L8).</p></div>
    <button type="button" class="btn-main" @click="$emit('next')">Visiteur suivant</button>
  </section>
</template>
