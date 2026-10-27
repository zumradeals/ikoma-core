<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{ status: string }>();
const appearance = computed(() => ({ Disponible: ['○', 'border-ikoma-blue'], Associé: ['●', 'border-ikoma-navy'], Restitué: ['✓', 'border-ikoma-success'], Annulé: ['⛔', 'border-ikoma-danger'], Clos: ['■', 'border-slate-500'] }[props.status] || ['ⓘ', 'border-slate-500']));
</script>
<template><span class="inline-flex items-center gap-2 rounded-full border-2 bg-white px-4 py-2 font-semibold text-ikoma-navy" :class="appearance[1]"><span aria-hidden="true">{{ appearance[0] }}</span>{{ status }}</span></template>
