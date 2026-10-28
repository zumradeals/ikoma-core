<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormButton from './FormButton.vue';
type Batch = { id: string; name: string; event_id: string; first_number: number | null; last_number: number | null };
const props = defineProps<{ batches: Batch[]; devices: { id: string; event_id: string; name: string; station: string | null }[]; groups: { id: string; event_id: string; name: string }[] }>();
const form = useForm({ batch_id: props.batches[0]?.id || '', from: '' as number | '', to: '' as number | '', target_type: 'poste', target_id: '' });
const batch = computed(() => props.batches.find(b => b.id === form.batch_id));
const targets = computed(() => (form.target_type === 'poste' ? props.devices : props.groups).filter(t => t.event_id === batch.value?.event_id));
watch(() => [form.batch_id, form.target_type], () => { form.target_id = ''; });
function submit() { form.post('/administration/badges/reservations', { onSuccess: () => form.reset('from', 'to') }); }
</script>
<template>
  <form aria-label="Réserver des badges" class="space-y-5" @submit.prevent="submit">
    <label class="block">Lot<select v-model="form.batch_id" class="field" required><option v-for="item in batches" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
    <p v-if="batch && batch.first_number !== null">Numéros de ce lot : du {{ batch.first_number }} au {{ batch.last_number }}.</p>
    <label class="block">Du badge n°<input v-model.number="form.from" type="number" min="1" class="field" required></label>
    <label class="block">Au badge n°<input v-model.number="form.to" type="number" min="1" class="field" required></label>
    <fieldset>
      <legend class="font-semibold">Réserver pour</legend>
      <label class="flex min-h-14 items-center gap-3"><input v-model="form.target_type" type="radio" value="poste" class="h-6 w-6">Un poste d’accueil</label>
      <label class="flex min-h-14 items-center gap-3"><input v-model="form.target_type" type="radio" value="groupe" class="h-6 w-6">Un groupe (car)</label>
    </fieldset>
    <label class="block">{{ form.target_type === 'poste' ? 'Poste' : 'Groupe' }}<select v-model="form.target_id" class="field" required><option value="" disabled>Choisir</option><option v-for="target in targets" :key="target.id" :value="target.id">{{ 'station' in target ? (target.station || target.name) : target.name }}</option></select></label>
    <p v-if="!targets.length">Aucun {{ form.target_type === 'poste' ? 'poste' : 'groupe' }} pour l’événement de ce lot.</p>
    <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
    <FormButton :busy="form.processing || !batches.length">Réserver la plage</FormButton>
  </form>
</template>
