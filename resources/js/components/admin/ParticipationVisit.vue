<script lang="ts">
export type VehicleRow = { id: string; plate: string; kind: string; status: string };
export type Visit = { expected_at: string | null; announced_at: string | null; no_show_reason: string | null; vehicles: VehicleRow[] };
</script>
<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

/**
 * Administration d'une participation : annonce de la visite (heure attendue) et véhicules rattachés. Annoncer n'est pas une présence ;
 * l'entrée et la sortie d'un véhicule se font à un poste d'accueil, jamais ici.
 */
const props = defineProps<{ participationId: string; visit: Visit; kinds: Record<string, string>; canAnnounce: boolean }>();
const toLocal = (value: string | null) => (value ? value.slice(0, 16).replace(' ', 'T') : '');
const announce = useForm({ expected_at: toLocal(props.visit.expected_at) });
const vehicle = useForm({ plate: '', kind: 'voiture' });
const remove = useForm({});
const announceError = computed(() => announce.errors.expected_at ?? (announce.errors as Record<string, string>).participation);
const vehicleError = computed(() => vehicle.errors.plate ?? vehicle.errors.kind ?? (vehicle.errors as Record<string, string>).participation);
const when = (iso: string) => new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
const states: Record<string, string> = { annonce: 'annoncé', present: 'présent sur le site' };
</script>
<template>
  <div class="mt-3 space-y-4 rounded-xl bg-slate-50 p-3" :data-testid="'visite-' + participationId">
    <p v-if="!canAnnounce" data-testid="annonce-impossible">La personne est déjà arrivée ou a été traitée : sa visite ne peut plus être annoncée.</p>
    <form v-else class="space-y-2" aria-label="Annoncer la visite" @submit.prevent="announce.post(`/administration/participations/${participationId}/annonce`, { preserveScroll: true })">
      <p class="font-semibold">Visite annoncée</p>
      <p v-if="visit.announced_at && visit.expected_at" data-testid="heure-annoncee">Attendue le {{ when(visit.expected_at) }}. Une visite annoncée n’est pas une présence.</p>
      <p v-else>Aucune heure d’arrivée annoncée.</p>
      <p v-if="visit.no_show_reason" data-testid="absence-constatee">Absence constatée : {{ visit.no_show_reason }}</p>
      <label class="block">Heure d’arrivée attendue<input v-model="announce.expected_at" type="datetime-local" class="field" required data-testid="admin-heure-attendue"></label>
      <p v-if="announceError" role="alert" data-testid="admin-erreur-annonce">{{ announceError }}</p>
      <button type="submit" class="action" :disabled="announce.processing || !announce.expected_at" data-testid="admin-annoncer">{{ visit.announced_at ? 'Modifier l’heure attendue' : 'Annoncer la visite' }}</button>
    </form>
    <div class="space-y-2">
      <p class="font-semibold">Véhicules</p>
      <ul v-if="visit.vehicles.length" class="space-y-1" data-testid="admin-vehicules">
        <li v-for="x in visit.vehicles" :key="x.id" class="flex flex-wrap items-center gap-3">
          <span><strong>{{ x.plate }}</strong> · {{ kinds[x.kind] ?? x.kind }} · {{ states[x.status] ?? x.status }}</span>
          <button v-if="x.status === 'annonce'" type="button" class="action" :disabled="remove.processing" :data-testid="'admin-annuler-vehicule-' + x.id" @click="remove.delete(`/administration/vehicules/${x.id}`, { preserveScroll: true })">Annuler ce véhicule</button>
          <span v-else class="text-sm">Sa sortie se fait à un poste d’accueil.</span>
        </li>
      </ul>
      <p v-else data-testid="admin-aucun-vehicule">Aucun véhicule rattaché.</p>
      <form class="space-y-2" aria-label="Associer un véhicule" @submit.prevent="vehicle.post(`/administration/participations/${participationId}/vehicules`, { preserveScroll: true, onSuccess: () => vehicle.reset('plate') })">
        <label class="block">Plaque d’immatriculation<input v-model="vehicle.plate" class="field uppercase" maxlength="20" required data-testid="admin-plaque"></label>
        <label class="block">Type de véhicule<select v-model="vehicle.kind" class="field" data-testid="admin-type-vehicule"><option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option></select></label>
        <p v-if="vehicleError" role="alert" data-testid="admin-erreur-vehicule">{{ vehicleError }}</p>
        <button type="submit" class="action" :disabled="vehicle.processing || !vehicle.plate.trim()" data-testid="admin-ajouter-vehicule">Associer le véhicule</button>
      </form>
    </div>
  </div>
</template>
