<script setup lang="ts">
import { useId } from 'vue';
const reasonId = useId();
withDefaults(defineProps<{ disabled?: boolean; reason?: string }>(), { disabled: false, reason: '' });
</script>
<template>
  <div>
    <button type="button" :disabled="disabled" :aria-describedby="disabled && reason ? reasonId : undefined" class="min-h-14 rounded-xl bg-ikoma-blue px-6 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-600"><slot /></button>
    <p v-if="disabled && reason" :id="reasonId" class="mt-2">{{ reason }}</p>
  </div>
</template>
