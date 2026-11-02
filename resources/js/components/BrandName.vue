<script setup lang="ts">
import { useInstitution } from '../composables/useInstitution';

/**
 * Nom de l'installation. Sans identité institutionnelle, le nom IKOMA ACCESS garde la mise en forme d'origine de chaque écran :
 * « strong » = IKOMA en gras puis ACCESS ; « accent » = IKOMA puis ACCESS en couleur d'accent.
 */
withDefaults(defineProps<{ variant?: 'strong' | 'accent' }>(), { variant: 'strong' });
const institution = useInstitution();
</script>

<template>
  <template v-if="institution.custom"><strong v-if="variant === 'strong'">{{ institution.name }}</strong><template v-else>{{ institution.name }}</template></template>
  <template v-else-if="variant === 'strong'"><strong>IKOMA</strong>{{ ' ACCESS' }}</template>
  <template v-else>IKOMA <span class="text-[var(--inst-accent)]">ACCESS</span></template>
</template>
