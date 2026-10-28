<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormButton from './FormButton.vue';
const props = defineProps<{ settings: { margin: number; reusable: boolean } }>();
const form = useForm({ margin: props.settings.margin, reusable: props.settings.reusable ? 'oui' : 'non' });
</script>
<template>
  <form aria-label="Réglages des badges" class="space-y-5" @submit.prevent="form.put('/administration/badges/reglages')">
    <label class="block">Marge de fabrication (en pourcentage)<input v-model.number="form.margin" type="number" min="0" max="100" class="field" required></label>
    <p>Quantité fabriquée = nombre attendu + cette marge, arrondie au badge supérieur.</p>
    <fieldset>
      <legend class="font-semibold">Un badge restitué peut-il servir à nouveau ?</legend>
      <label class="flex min-h-14 items-center gap-3"><input v-model="form.reusable" type="radio" value="non" class="h-6 w-6">Non : usage unique, le badge restitué est clos</label>
      <label class="flex min-h-14 items-center gap-3"><input v-model="form.reusable" type="radio" value="oui" class="h-6 w-6">Oui : le badge restitué redevient disponible</label>
    </fieldset>
    <p>Règle provisoire : à confirmer avec le client.</p>
    <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
    <FormButton :busy="form.processing">Enregistrer les réglages</FormButton>
  </form>
</template>
