<script setup lang="ts">
import { computed } from 'vue';
import { useInstitution } from '../composables/useInstitution';

/** Logo de l'installation : celui de l'institution s'il est enregistré, sinon l'icône IKOMA ACCESS d'origine. */
const props = withDefaults(defineProps<{ size?: number; fallback?: string }>(), { size: 36, fallback: '/brand/ikoma-access-icon.svg' });
const institution = useInstitution();
const src = computed(() => institution.value.logoUrl ?? props.fallback);
</script>

<template>
  <img :src="src" alt="" :width="size" :height="size" :class="institution.logoUrl ? 'object-contain' : ''">
</template>
