<script setup lang="ts">
import { computed, reactive } from 'vue';
import type { DeskContext, UnlistedInput } from '../../poste/types';
const props = defineProps<{ context: DeskContext; busy: boolean }>();
const emit = defineEmits<{ submit: [input: UnlistedInput]; back: [] }>();
const form = reactive<UnlistedInput>({ name: '', organisation: '', host_id: '', category_id: props.context.categories.find(c => c.name === 'Visiteur')?.id ?? '' });
const reason = computed(() => (!form.name.trim() ? 'Indiquez le nom du visiteur.' : !form.host_id ? 'Choisissez la personne à voir.' : !form.category_id ? 'Choisissez la catégorie.' : ''));
</script>
<template>
  <section aria-labelledby="nonliste-titre" class="space-y-5">
    <h1 id="nonliste-titre" class="text-display font-bold">Visiteur non listé : fiche rapide</h1>
    <p>L’accord de l’hôte est obligatoire avant toute entrée. En attendant, l’accès est limité à la zone d’accueil, avec accompagnement.</p>
    <form class="space-y-4" aria-label="Fiche rapide" @submit.prevent="!reason && emit('submit', { ...form })">
      <label class="block text-lg font-semibold">Nom du visiteur<input v-model="form.name" class="field" maxlength="160" required></label>
      <label class="block text-lg font-semibold">Organisation<input v-model="form.organisation" class="field" maxlength="160"></label>
      <label class="block text-lg font-semibold">Personne à voir<select v-model="form.host_id" class="field" required><option value="" disabled>Choisir</option><option v-for="host in context.hosts" :key="host.id" :value="host.id">{{ host.name }}{{ host.service ? ' · ' + host.service : '' }}</option></select></label>
      <label class="block text-lg font-semibold">Catégorie<select v-model="form.category_id" class="field" required><option v-for="category in context.categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
      <div class="grid gap-3 sm:grid-cols-[1fr_2fr]"><button type="button" class="btn-soft" @click="$emit('back')">Retour</button><div><button type="submit" class="btn-main" :disabled="!!reason || busy">Créer la fiche et demander l’accord de l’hôte</button><p v-if="reason" class="mt-2">{{ reason }}</p></div></div>
    </form>
  </section>
</template>
