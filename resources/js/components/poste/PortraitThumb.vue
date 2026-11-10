<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import type { PosteRepository } from '../../poste/repository';

/**
 * Portrait de la personne pour l'accueil : lu en ligne seulement (jamais copié sur la tablette) ; sans portrait, hors ligne ou en cas d'échec, l'initiale s'affiche.
 */
const props = defineProps<{ repository: PosteRepository; visitorId: string; name: string; hasPortrait?: boolean }>();
const url = ref<string | null>(null);
let sequence = 0;
function release() { if (url.value) URL.revokeObjectURL(url.value); url.value = null; }
watch(() => [props.visitorId, props.hasPortrait] as const, async ([id, has]) => {
  const mine = ++sequence; release();
  if (!has || !props.repository.portrait) return;
  const blob = await props.repository.portrait(id).catch(() => null);
  if (mine !== sequence || !blob) return;
  url.value = URL.createObjectURL(blob);
}, { immediate: true });
onBeforeUnmount(() => { ++sequence; release(); });
</script>
<template>
  <img v-if="url" :src="url" :alt="'Portrait de ' + name" class="poste-portrait-img" data-testid="portrait" width="132" height="164">
  <span v-else class="agent-search-initial" aria-hidden="true">{{ name.charAt(0) }}</span>
</template>
