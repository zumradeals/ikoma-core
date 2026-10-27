<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormButton from './FormButton.vue';
type Category = { id: string; event_id: string; name: string; color: string; priority_circuit: boolean; zone_ids: string[] };
const props = defineProps<{ events: { id: string; name: string }[]; zones: { id: string; name: string; event_id: string }[]; category?: Category }>();
const form = useForm({ event_id: props.category?.event_id || props.events[0]?.id || '', name: props.category?.name || '', color: props.category?.color || '#0F5AA6', priority_circuit: props.category?.priority_circuit || false, zone_ids: [...(props.category?.zone_ids || [])] });
const allowedZones = computed(() => props.zones.filter(z => z.event_id === form.event_id));
watch(() => form.event_id, () => { form.zone_ids = []; });
function submit() {
  if (props.category) form.put(`/administration/categories/${props.category.id}`);
  else form.post('/administration/categories', { onSuccess: () => form.reset('name', 'zone_ids', 'priority_circuit', 'color') });
}
</script>
<template>
  <form :aria-label="category ? 'Modifier une catégorie' : 'Créer une catégorie'" class="space-y-5" @submit.prevent="submit">
    <label class="block">Événement<select v-model="form.event_id" class="field" required :disabled="!!category"><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option></select></label>
    <label class="block">Nom de la catégorie<input v-model="form.name" class="field" maxlength="120" required></label>
    <label class="block">Couleur de la catégorie<input v-model="form.color" type="color" class="field" required></label>
    <label class="flex min-h-14 items-center gap-3"><input v-model="form.priority_circuit" type="checkbox" class="h-6 w-6">Circuit prioritaire</label>
    <fieldset>
<legend class="text-heading font-semibold">Zones autorisées</legend><p class="my-3">Cochez les espaces accessibles à cette catégorie. Aucune zone n'est autorisée par défaut.</p>
      <label v-for="zone in allowedZones" :key="zone.id" class="flex min-h-14 items-center gap-3"><input v-model="form.zone_ids" type="checkbox" :value="zone.id" class="h-6 w-6">{{ zone.name }}</label>
      <p v-if="!allowedZones.length">Aucune zone pour cet événement.</p>
    </fieldset>
    <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
    <FormButton :busy="form.processing || !events.length">{{ category ? 'Enregistrer la catégorie' : 'Créer la catégorie' }}</FormButton>
  </form>
</template>
