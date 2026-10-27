<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ name: string; color: string; priority?: boolean }>();
const border = computed(() => /^#[0-9a-f]{6}$/i.test(props.color) ? props.color : '#0F5AA6');
</script>
<template><span class="inline-flex items-center gap-2 rounded-full border-2 bg-white px-4 py-2 font-semibold text-ikoma-navy" :style="{ borderColor: border }"><span aria-hidden="true">{{ priority ? '★' : '●' }}</span>{{ name }}<span v-if="priority">· Prioritaire</span></span></template>
