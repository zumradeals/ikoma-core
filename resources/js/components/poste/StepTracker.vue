<script setup lang="ts">
import { stepLabels } from '../../poste/machine';
defineProps<{ current: number }>();
</script>
<template>
  <nav aria-label="Progression du parcours">
    <p class="mb-2 text-lg font-semibold">Étape {{ current }} sur 7 · {{ stepLabels[current - 1] }}</p>
    <ol class="flex gap-1">
      <li v-for="(label, i) in stepLabels" :key="label" class="h-3 flex-1 rounded-full" :class="i + 1 < current ? 'bg-green-700' : i + 1 === current ? 'bg-ikoma-orange' : 'bg-slate-300'" :aria-current="i + 1 === current ? 'step' : undefined"><span class="sr-only">{{ label }}</span></li>
    </ol>
  </nav>
</template>
