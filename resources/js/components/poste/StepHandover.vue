<script setup lang="ts">
import type { Fiche } from '../../poste/types';
defineProps<{ fiche: Fiche; badgeCode: string; busy: boolean }>();
defineEmits<{ enter: [] }>();
</script>
<template>
  <section aria-labelledby="remise-titre" class="space-y-5">
    <h1 id="remise-titre" class="text-display font-bold">Remettez le badge {{ badgeCode }}</h1>
    <div class="rounded-2xl border-2 border-green-700 bg-green-50 p-5">
      <p class="text-xl font-semibold"><span aria-hidden="true">✓</span> Badge {{ badgeCode }} associé à {{ fiche.full_name }}</p>
      <h2 class="mt-4 text-heading font-bold">{{ fiche.accueil_limite ? 'Zone autorisée (avec accompagnement)' : 'Zones autorisées' }}</h2>
      <ul class="mt-2 grid gap-2 sm:grid-cols-2"><li v-for="zone in fiche.zones" :key="zone" class="rounded-xl bg-white p-3 text-xl font-semibold">{{ zone }}</li></ul>
      <p v-if="!fiche.zones.length" class="mt-2">Aucune zone enregistrée pour cette catégorie : appelez le superviseur.</p>
      <p class="mt-4">Remettez le badge, rappelez les zones autorisées et les consignes du site.<span v-if="fiche.accueil_limite"> Le visiteur doit être accompagné.</span></p>
    </div>
    <button type="button" class="btn-ok" :disabled="busy" @click="$emit('enter')"><span aria-hidden="true">➜</span> Autoriser l’accès</button>
  </section>
</template>
