<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormButton from './FormButton.vue';
const props = defineProps<{ events: { id: string; name: string }[]; zone?: { id: string; name: string; event_id: string; est_accueil?: boolean } }>();
const form = useForm({ event_id: props.zone?.event_id || props.events[0]?.id || '', name: props.zone?.name || '', est_accueil: props.zone?.est_accueil || false });
function submit() {
  if (props.zone) form.put(`/administration/zones/${props.zone.id}`);
  else form.post('/administration/zones', { onSuccess: () => form.reset('name', 'est_accueil') });
}
</script>
<template>
  <form :aria-label="zone ? 'Modifier une zone' : 'Créer une zone'" class="space-y-5" @submit.prevent="submit">
    <label class="block">Événement<select v-model="form.event_id" class="field" required :disabled="!!zone"><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option></select></label>
    <label class="block">Nom de la zone<input v-model="form.name" class="field" maxlength="120" required></label>
    <label class="flex min-h-14 items-center gap-3"><input v-model="form.est_accueil" type="checkbox" class="h-6 w-6">Zone d’accueil (seule zone accessible aux visiteurs non listés)</label>
    <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
    <p v-if="!events.length">Aucun événement disponible.</p>
    <FormButton :busy="form.processing || !events.length">{{ zone ? 'Enregistrer la zone' : 'Créer la zone' }}</FormButton>
  </form>
</template>
