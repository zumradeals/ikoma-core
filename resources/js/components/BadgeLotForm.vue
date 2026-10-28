<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormButton from './FormButton.vue';
const props = defineProps<{ events: { id: string; name: string }[]; categories: { id: string; event_id: string; name: string }[]; margin: number }>();
const form = useForm({ event_id: props.events[0]?.id || '', category_id: '', expected: 100 });
const allowed = computed(() => props.categories.filter(c => c.event_id === form.event_id));
watch(() => form.event_id, () => { form.category_id = ''; });
const expected = computed(() => Math.max(0, Math.floor(Number(form.expected) || 0)));
const extra = computed(() => Math.floor((expected.value * props.margin + 99) / 100));
function submit() { form.post('/administration/badges/lots', { onSuccess: () => form.reset('expected') }); }
</script>
<template>
  <form aria-label="Générer un lot de badges" class="space-y-5" @submit.prevent="submit">
    <label class="block">Événement<select v-model="form.event_id" class="field" required><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option></select></label>
    <label class="block">Catégorie des badges<select v-model="form.category_id" class="field" required><option value="" disabled>Choisir une catégorie</option><option v-for="category in allowed" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
    <label class="block">Nombre de personnes attendues<input v-model.number="form.expected" type="number" min="1" max="5000" class="field" required></label>
    <p aria-live="polite">Badges à fabriquer : <strong>{{ expected + extra }}</strong> ({{ expected }} attendus + {{ extra }} de marge à {{ margin }} %)</p>
    <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
    <p v-if="!allowed.length">Aucune catégorie pour cet événement : créez-la d’abord dans « Catégories ».</p>
    <FormButton :busy="form.processing || !form.category_id">Générer le lot</FormButton>
  </form>
</template>
