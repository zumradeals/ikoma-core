<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
const page = usePage<{ flash?: { status?: string | null } }>();
</script>
<template>
  <div class="min-h-screen">
    <header class="border-b-4 border-ikoma-orange bg-ikoma-navy px-6 py-5 text-white"><div class="mx-auto max-w-4xl font-bold">IKOMA <span class="font-normal">Accueil</span></div></header>
    <main id="contenu" class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
      <p v-if="page.props?.flash?.status" role="status" class="mb-6 rounded-xl border-2 border-ikoma-blue bg-blue-50 p-4 text-lg font-semibold text-ikoma-navy" data-testid="statut"><span aria-hidden="true">ℹ</span> {{ page.props.flash.status }}</p>
      <slot />
    </main>
    <footer class="mx-auto max-w-4xl px-6 pb-8 text-body">IKOMA GROUP · Vérification rapide des visiteurs</footer>
  </div>
</template>
