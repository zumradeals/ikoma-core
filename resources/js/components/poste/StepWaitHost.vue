<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import type { Fiche } from '../../poste/types';
const props = defineProps<{ fiche: Fiche; refused: boolean; busy: boolean }>();
const emit = defineEmits<{ refresh: []; next: [] }>();
let timer: number | undefined;
onMounted(() => { timer = window.setInterval(() => { if (!props.refused) emit('refresh'); }, 5000); });
onBeforeUnmount(() => window.clearInterval(timer));
</script>
<template>
  <section aria-labelledby="hote-titre" class="space-y-5">
    <h1 id="hote-titre" class="text-display font-bold">{{ refused ? 'L’hôte a refusé cette visite' : 'En attente de l’accord de l’hôte' }}</h1>
    <div class="rounded-2xl border-2 p-5" :class="refused ? 'border-red-700 bg-red-50' : 'border-ikoma-orange bg-orange-50'">
      <p class="text-xl font-semibold"><span aria-hidden="true">{{ refused ? '✕' : '⏳' }}</span> {{ fiche.full_name }}<template v-if="fiche.host"> · à voir : {{ fiche.host }}</template></p>
      <p class="mt-2">{{ refused ? 'L’entrée est impossible. Informez le visiteur.' : 'Appelez l’hôte ou attendez sa réponse : ni la validation ni l’entrée ne sont possibles avant son accord. Le visiteur reste dans la zone d’accueil, accompagné.' }}</p>
    </div>
    <button v-if="!refused" type="button" class="btn-main" :disabled="busy" @click="$emit('refresh')">Vérifier la réponse de l’hôte</button>
    <button v-else type="button" class="btn-main" @click="$emit('next')">Visiteur suivant</button>
  </section>
</template>
