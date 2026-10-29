<script setup lang="ts">
import { stepLabels } from '../../poste/machine';
defineProps<{ current: number }>();
</script>
<template>
  <nav aria-label="Progression du parcours" class="agent-steps">
    <p class="mb-2 text-lg font-semibold">Étape {{ current }} sur 7 · {{ stepLabels[current - 1] }}</p>
    <ol class="agent-step-list">
      <li v-for="(label, i) in stepLabels" :key="label" class="agent-step" :class="i + 1 < current ? 'bg-green-700' : i + 1 === current ? 'bg-ikoma-orange' : 'bg-slate-300'" :aria-current="i + 1 === current ? 'step' : undefined"><span class="agent-step-number" aria-hidden="true">{{ i + 1 }}</span><span class="agent-step-label">{{ label }}</span></li>
    </ol>
  </nav>
</template>
