<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormButton from './FormButton.vue';
type Batch = { id: string; name: string; event_id: string; event_name?: string; first_number: number | null; last_number: number | null };
const props = defineProps<{ batches: Batch[]; devices: { id: string; event_id: string; name: string; station: string | null }[]; groups: { id: string; event_id: string; name: string }[] }>();
const form = useForm({ batch_id: props.batches[0]?.id || '', from: '' as number | '', to: '' as number | '', target_type: 'poste', target_id: '', all_available: false });
const batch = computed(() => props.batches.find(b => b.id === form.batch_id));
const targets = computed(() => (form.target_type === 'poste' ? props.devices : props.groups).filter(t => t.event_id === batch.value?.event_id));
watch(() => [form.batch_id, form.target_type], () => { form.target_id = ''; if (form.target_type !== 'poste') form.all_available = false; });
function submit() { form.post('/administration/badges/reservations', { onSuccess: () => form.reset('from', 'to', 'all_available') }); }
</script>
<template>
  <form aria-label="Réserver des badges" class="space-y-5" @submit.prevent="submit">
    <label class="block">Lot<select v-model="form.batch_id" data-testid="reservation-lot" class="field" required><option v-for="item in batches" :key="item.id" :value="item.id">{{ item.event_name ? `${item.event_name} · ${item.name}` : item.name }}</option></select></label>
    <p v-if="batch && batch.first_number !== null">Numéros de ce lot : du {{ batch.first_number }} au {{ batch.last_number }}.</p>
    <label v-if="form.target_type === 'poste'" class="flex items-center gap-3 rounded-lg border p-3 font-semibold" data-testid="reserver-badges-libres">
      <input v-model="form.all_available" type="checkbox" class="h-6 w-6">
      Réserver tous les badges disponibles et non attribués de ce lot au poste choisi
    </label>
    <p v-if="form.all_available" class="text-sm">Aucun badge réservé à un autre poste ou à un groupe ne sera modifié. Les numéros sont sélectionnés automatiquement.</p>
    <label v-if="!form.all_available" class="block">Du badge n°<input v-model.number="form.from" type="number" min="1" class="field" required></label>
    <label v-if="!form.all_available" class="block">Au badge n°<input v-model.number="form.to" type="number" min="1" class="field" required></label>
    <fieldset>
      <legend class="font-semibold">Réserver pour</legend>
      <label class="flex min-h-14 items-center gap-3"><input v-model="form.target_type" type="radio" value="poste" class="h-6 w-6">Un poste d’accueil</label>
      <label class="flex min-h-14 items-center gap-3"><input v-model="form.target_type" type="radio" value="groupe" class="h-6 w-6">Un groupe (car)</label>
    </fieldset>
    <label class="block">{{ form.target_type === 'poste' ? 'Poste' : 'Groupe' }}<select v-model="form.target_id" class="field" required><option value="" disabled>Choisir</option><option v-for="target in targets" :key="target.id" :value="target.id">{{ 'station' in target ? (target.station || target.name) : target.name }}</option></select></label>
    <p v-if="!targets.length">Aucun {{ form.target_type === 'poste' ? 'poste' : 'groupe' }} pour l’événement de ce lot.</p>
    <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
    <p class="text-sm">Après réservation, ouvrez le poste concerné et cliquez sur « Synchroniser maintenant » avant de scanner.</p>
    <FormButton :busy="form.processing || !batches.length">{{ form.all_available ? 'Réserver les badges disponibles' : 'Réserver la plage' }}</FormButton>
  </form>
</template>
