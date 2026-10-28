<script setup lang="ts">
import type { Poste } from '../../poste/types';
defineProps<{ postes: Poste[] }>();
defineEmits<{ select: [id: string] }>();
</script>
<template>
  <section aria-labelledby="choix-poste" class="space-y-4">
    <h1 id="choix-poste" class="text-display font-bold">Choisissez votre poste d’accueil</h1>
    <p>Le poste détermine l’événement et les badges qui lui sont réservés.</p>
    <ul class="space-y-3">
      <li v-for="poste in postes" :key="poste.id"><button type="button" class="btn-main !justify-between text-left" @click="$emit('select', poste.id)"><span>{{ poste.station || poste.name }}</span><span class="text-lg font-normal">{{ poste.event_name }}</span></button></li>
    </ul>
    <p v-if="!postes.length" role="status">Aucun poste n’est configuré : demandez à l’administrateur d’en créer un.</p>
  </section>
</template>
