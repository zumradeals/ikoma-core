<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ name: string; badgeCode: string; zones: string[]; durationMs: number }>();
defineEmits<{ next: [] }>();
const seconds = computed(() => Math.round(props.durationMs / 1000));
const label = computed(() => `${Math.floor(seconds.value / 60)} min ${String(seconds.value % 60).padStart(2, '0')} s`);
</script>
<template>
  <section aria-labelledby="fin-titre" class="space-y-5">
    <h1 id="fin-titre" class="text-display font-bold"><span aria-hidden="true">✓</span> Entrée enregistrée</h1>
    <div class="rounded-2xl border-2 border-green-700 bg-green-50 p-5">
      <p class="text-2xl font-bold">{{ name }} · badge {{ badgeCode }}</p>
      <p v-if="zones.length" class="mt-2">Zones : {{ zones.join(' · ') }}</p>
      <p class="mt-4 text-xl" data-testid="chrono">Parcours terminé en <strong>{{ label }}</strong></p>
      <p class="font-semibold" :class="seconds <= 60 ? 'text-green-800' : 'text-red-800'"><span aria-hidden="true">{{ seconds <= 60 ? '✓' : '⚠' }}</span> {{ seconds <= 60 ? 'Objectif de 60 secondes tenu' : 'Objectif de 60 secondes dépassé' }}</p>
    </div>
    <button type="button" class="btn-main" @click="$emit('next')">Visiteur suivant</button>
  </section>
</template>
