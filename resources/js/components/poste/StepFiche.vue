<script setup lang="ts">
import { computed, ref } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import type { Fiche, SearchResult } from '../../poste/types';
const props = defineProps<{ fiche: Fiche; homonyms: number; others?: SearchResult[] }>();
const when = (iso: string) => new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
defineEmits<{ confirm: []; back: [] }>();
const secondCriterion = ref(false);
const reason = computed(() => (props.homonyms > 1 && !secondCriterion.value ? 'Plusieurs personnes portent ce nom : confirmez le second critère.' : ''));
</script>
<template>
  <section aria-labelledby="fiche-titre" class="space-y-5">
    <h1 id="fiche-titre" class="text-display font-bold">Vérifiez la fiche avec le visiteur</h1>
    <div class="rounded-2xl border-2 bg-white p-5" :class="fiche.category.priority_circuit ? 'border-ikoma-gold' : 'border-slate-300'">
      <p v-if="fiche.category.priority_circuit" class="mb-3 font-bold text-amber-900"><span aria-hidden="true">★</span> Personnalité VIP : circuit prioritaire, traitement discret</p>
      <p class="text-3xl font-bold">{{ fiche.full_name }}</p>
      <p class="my-3"><CategorySwatch :name="fiche.category.name" :color="fiche.category.color" :priority="fiche.category.priority_circuit" /></p>
      <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2" data-testid="fiche-champs">
        <div><dt class="font-semibold text-slate-600">Organisation</dt><dd class="text-xl font-bold">{{ fiche.organisation || 'Non indiquée' }}</dd></div>
        <div><dt class="font-semibold text-slate-600">Personne reçue</dt><dd class="text-xl font-bold">{{ fiche.host || 'Non indiquée' }}</dd></div>
        <div v-if="fiche.valid_from && fiche.valid_until" class="sm:col-span-2" data-testid="fiche-validite"><dt class="font-semibold text-slate-600">Période de validité</dt><dd class="text-xl font-bold">Du {{ when(fiche.valid_from) }} au {{ when(fiche.valid_until) }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-semibold text-slate-600">{{ fiche.accueil_limite ? 'Zone autorisée (accès limité, avec accompagnement)' : 'Zones autorisées' }}</dt>
          <dd v-if="fiche.zones.length" class="mt-1 flex flex-wrap gap-2"><span v-for="zone in fiche.zones" :key="zone" class="poste-zone-chip">{{ zone }}</span></dd>
          <dd v-else class="text-xl font-bold">Aucune zone enregistrée : appelez le superviseur.</dd></div>
      </dl>
      <p v-if="fiche.access_note" class="mt-4 rounded-xl border-2 border-green-700 bg-green-50 p-3 font-bold text-green-900" data-testid="mention-autorisation"><span aria-hidden="true">✓</span> Accès autorisé par le superviseur : {{ fiche.access_note }}</p>
      <p v-if="fiche.a_rebadger" class="mt-4 rounded-xl border-2 border-orange-600 bg-orange-50 p-3 font-bold text-orange-900" data-testid="a-rebadger"><span aria-hidden="true">▲</span> À rebadger : le badge précédent a été annulé, associez un nouveau badge.</p>
      <p v-if="fiche.hors_periode" class="mt-4 rounded-xl border-2 border-red-700 bg-red-50 p-3 font-bold text-red-900"><span aria-hidden="true">⚠</span> Hors de sa période de validité : l’avis de l’hôte ou du superviseur sera demandé à la décision.</p>
    </div>
    <div v-if="homonyms > 1" class="rounded-xl border-2 border-red-700 bg-red-50 p-4">
      <p class="font-bold text-red-900"><span aria-hidden="true">⚠</span> Homonyme : {{ homonyms }} personnes portent ce nom.</p>
      <ul v-if="others && others.length" class="mt-2 space-y-1" data-testid="autres-homonymes"><li v-for="o in others" :key="o.id" class="rounded-lg border border-dashed border-slate-400 bg-white px-3 py-2 text-base text-slate-700">Autre fiche : {{ o.full_name }}<template v-if="o.organisation"> · {{ o.organisation }}</template><template v-if="o.host"> · reçue par {{ o.host }}</template></li></ul>
      <label class="mt-3 flex min-h-14 items-center gap-3 text-lg"><input v-model="secondCriterion" type="checkbox" class="h-7 w-7">J’ai vérifié un second critère (organisation, personne reçue ou pièce d’identité)</label>
    </div>
    <div class="poste-actionbar grid gap-3 sm:grid-cols-[1fr_2fr]">
      <button type="button" class="btn-soft" @click="$emit('back')">Ce n’est pas lui : retour</button>
      <div><button type="button" class="btn-main" :disabled="!!reason" :aria-describedby="reason ? 'fiche-raison' : undefined" @click="$emit('confirm')">C’est bien cette personne</button><p v-if="reason" id="fiche-raison" class="mt-2">{{ reason }}</p></div>
    </div>
  </section>
</template>
