<script setup lang="ts">
defineProps<{ poste: string | null; agent: string; online: boolean; pending: number }>();
</script>
<template>
  <header class="flex flex-wrap items-center gap-x-6 gap-y-2 bg-ikoma-navy px-4 py-3 text-white" aria-label="État du poste">
    <strong class="text-xl">IKOMA <span class="font-normal">Accueil</span></strong>
    <span class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-1 font-semibold" :class="online ? 'text-green-800' : 'text-red-800'" role="status"><span aria-hidden="true">{{ online ? '●' : '✕' }}</span>{{ online ? 'En ligne' : 'Hors ligne' }}</span>
    <span>Opérations en attente : <strong>{{ pending }}</strong></span>
    <span>Poste : <strong>{{ poste ?? 'non choisi' }}</strong></span>
    <span>Agent : <strong>{{ agent }}</strong></span>
  </header>
</template>
