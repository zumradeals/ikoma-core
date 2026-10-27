<script setup lang="ts">
withDefaults(defineProps<{ label: string; symbol: string; tone?: 'standard' | 'vip' }>(), { tone: 'standard' });
</script>
<template><span class="inline-flex items-center gap-2 rounded-full border-2 px-4 py-2 font-semibold text-ikoma-navy" :class="tone === 'vip' ? 'border-ikoma-gold' : 'border-ikoma-blue'"><span aria-hidden="true">{{ symbol }}</span>{{ label }}</span></template>
