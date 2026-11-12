<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormButton from './FormButton.vue';
type Category = { id: string; event_id: string; name: string; color: string; priority_circuit: boolean; closure_supervisor_only?: boolean; zone_ids: string[] };
const props = withDefaults(defineProps<{ events: { id: string; name: string }[]; zones: { id: string; name: string }[]; category?: Category; closureAvailable?: boolean }>(), { category: undefined, closureAvailable: false });
const form = useForm({ event_id: props.category?.event_id || props.events[0]?.id || '', name: props.category?.name || '', color: props.category?.color || '#0F5AA6', priority_circuit: props.category?.priority_circuit || false, closure_supervisor_only: props.category?.closure_supervisor_only || false, zone_ids: [...(props.category?.zone_ids || [])] });
// Toute zone est une zone du site : l'activité choisit parmi elles.
function submit() {
  if (props.category) form.put(`/administration/categories/${props.category.id}`);
  else form.post('/administration/categories', { onSuccess: () => form.reset('name', 'zone_ids', 'priority_circuit', 'closure_supervisor_only', 'color') });
}
</script>
<template>
  <form :aria-label="category ? 'Modifier une catégorie' : 'Créer une catégorie'" class="space-y-5" @submit.prevent="submit">
    <label class="block">Événement<select v-model="form.event_id" class="field" required :disabled="!!category"><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option></select></label>
    <label class="block">Nom de la catégorie<input v-model="form.name" class="field" maxlength="120" required></label>
    <label class="block">Couleur de la catégorie<input v-model="form.color" type="color" class="field" required></label>
    <label class="flex min-h-14 items-center gap-3"><input v-model="form.priority_circuit" type="checkbox" class="h-6 w-6">Circuit prioritaire</label>
    <label class="flex min-h-14 items-center gap-3"><input v-model="form.closure_supervisor_only" type="checkbox" class="h-6 w-6" :disabled="!closureAvailable && !props.category?.closure_supervisor_only" data-testid="cloture-reservee">Sortie et restitution réservées au superviseur (clôture VIP)</label>
    <p v-if="!closureAvailable && !props.category?.closure_supervisor_only" class="text-sm" data-testid="cloture-reservee-indisponible">Disponible avec le parcours de clôture du superviseur (à venir) : l’activer aujourd’hui rendrait toute sortie impossible.</p>
    <fieldset>
<legend class="text-heading font-semibold">Zones autorisées</legend><p class="my-3">Cochez les espaces accessibles à cette catégorie. Aucune zone n'est autorisée par défaut.</p>
      <label v-for="zone in zones" :key="zone.id" class="flex min-h-14 items-center gap-3"><input v-model="form.zone_ids" type="checkbox" :value="zone.id" class="h-6 w-6">{{ zone.name }}</label>
      <p v-if="!zones.length">Aucune zone : créez-en dans « Site et accès ».</p>
    </fieldset>
    <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
    <FormButton :busy="form.processing || !events.length">{{ category ? 'Enregistrer la catégorie' : 'Créer la catégorie' }}</FormButton>
  </form>
</template>
