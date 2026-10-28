<script setup lang="ts">
import { computed, ref } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import type { Fiche } from '../../poste/types';
const props = defineProps<{ fiche: Fiche; homonyms: number }>();
defineEmits<{ confirm: []; back: [] }>();
const secondCriterion = ref(false);
const notApproved = computed(() => (props.fiche.approval ?? 'approuve') !== 'approuve');
const reason = computed(() => (notApproved.value ? 'L’invité n’est pas approuvé par l’officier de sécurité.' : props.homonyms > 1 && !secondCriterion.value ? 'Plusieurs personnes portent ce nom : confirmez le second critère.' : ''));
</script>
<template>
  <section aria-labelledby="fiche-titre" class="space-y-5">
    <h1 id="fiche-titre" class="text-display font-bold">Vérifiez la fiche avec le visiteur</h1>
    <div class="rounded-2xl border-2 bg-white p-5" :class="fiche.category.priority_circuit ? 'border-ikoma-gold' : 'border-slate-300'">
      <p v-if="fiche.category.priority_circuit" class="mb-3 font-bold text-amber-900"><span aria-hidden="true">★</span> Personnalité VIP : circuit prioritaire, traitement discret</p>
      <p class="text-3xl font-bold">{{ fiche.full_name }}</p>
      <p class="my-3"><CategorySwatch :name="fiche.category.name" :color="fiche.category.color" :priority="fiche.category.priority_circuit" /></p>
      <dl class="grid gap-2 sm:grid-cols-2">
        <div><dt class="font-semibold">Organisation</dt><dd>{{ fiche.organisation || 'Non indiquée' }}</dd></div>
        <div><dt class="font-semibold">Personne reçue</dt><dd>{{ fiche.host || 'Non indiquée' }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-semibold">{{ fiche.accueil_limite ? 'Zone autorisée (accès limité, avec accompagnement)' : 'Zones autorisées' }}</dt><dd>{{ fiche.zones.length ? fiche.zones.join(' · ') : 'Aucune zone enregistrée' }}</dd></div>
      </dl>
      <p v-if="fiche.access_note" class="mt-4 rounded-xl border-2 border-green-700 bg-green-50 p-3 font-bold text-green-900" data-testid="mention-autorisation"><span aria-hidden="true">✓</span> Accès autorisé par le superviseur : {{ fiche.access_note }}</p>
      <p v-if="fiche.a_rebadger" class="mt-4 rounded-xl border-2 border-orange-600 bg-orange-50 p-3 font-bold text-orange-900" data-testid="a-rebadger"><span aria-hidden="true">▲</span> À rebadger : le badge précédent a été annulé, associez un nouveau badge.</p>
      <p v-if="fiche.hors_periode" class="mt-4 rounded-xl border-2 border-red-700 bg-red-50 p-3 font-bold text-red-900"><span aria-hidden="true">⚠</span> Hors de sa période de validité : l’avis de l’hôte ou du superviseur sera demandé à la décision.</p>
    </div>
    <p v-if="notApproved" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4 font-bold text-red-900" data-testid="non-approuve"><span aria-hidden="true">⛔</span> {{ fiche.approval === 'refuse' ? 'L’officier de sécurité n’a pas approuvé cet invité : appelez le superviseur.' : 'Cet invité attend l’approbation de l’officier de sécurité : l’accueil est impossible pour le moment.' }}</p>
    <div v-if="homonyms > 1" class="rounded-xl border-2 border-red-700 bg-red-50 p-4">
      <p class="font-bold text-red-900"><span aria-hidden="true">⚠</span> Homonyme : {{ homonyms }} personnes portent ce nom.</p>
      <label class="mt-3 flex min-h-14 items-center gap-3 text-lg"><input v-model="secondCriterion" type="checkbox" class="h-7 w-7">J’ai vérifié un second critère (organisation, personne reçue ou pièce d’identité)</label>
    </div>
    <div class="grid gap-3 sm:grid-cols-[1fr_2fr]">
      <button type="button" class="btn-soft" @click="$emit('back')">Ce n’est pas lui : retour</button>
      <div><button type="button" class="btn-main" :disabled="!!reason" :aria-describedby="reason ? 'fiche-raison' : undefined" @click="$emit('confirm')">C’est bien cette personne</button><p v-if="reason" id="fiche-raison" class="mt-2">{{ reason }}</p></div>
    </div>
  </section>
</template>
