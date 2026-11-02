<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue';
import { useInstitution } from '../composables/useInstitution';
const institution = useInstitution();
</script>

<template>
  <div class="min-h-screen">
    <header class="border-b-4 border-ikoma-orange bg-ikoma-navy px-6 py-5 text-white"><div class="mx-auto flex max-w-4xl items-center gap-3 font-bold"><span class="grid size-10 shrink-0 place-items-center rounded-lg bg-white"><BrandLogo :size="34" /></span><span>{{ institution.name }}</span></div></header>
    <main id="contenu" class="mx-auto max-w-4xl px-4 py-10 sm:px-6"><slot /></main>
    <footer class="mx-auto max-w-4xl px-6 pb-8 text-body">{{ institution.custom ? institution.footer : 'IKOMA GROUP · Vérification rapide des visiteurs' }}</footer>
  </div>
</template>
