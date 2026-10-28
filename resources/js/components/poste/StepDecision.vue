<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Avis } from '../../poste/types';
const props = defineProps<{ name: string; horsPeriode: boolean; blockedReason: string | null; busy: boolean }>();
const emit = defineEmits<{ validate: [avis: Avis | undefined]; refuse: [motif: string]; back: [] }>();
const avis = ref<Avis | ''>(''); const refusing = ref(false); const motif = ref('');
const validateReason = computed(() => props.blockedReason ?? (props.horsPeriode && !avis.value ? 'Visiteur hors période : indiquez l’avis obtenu (hôte ou superviseur).' : ''));
</script>
<template>
  <section aria-labelledby="decision-titre" class="space-y-5">
    <h1 id="decision-titre" class="text-display font-bold">Décision pour {{ name }}</h1>
    <fieldset v-if="horsPeriode" class="rounded-xl border-2 border-red-700 bg-red-50 p-4">
      <legend class="px-2 font-bold text-red-900"><span aria-hidden="true">⚠</span> Hors période : de qui avez-vous obtenu l’avis ?</legend>
      <label class="flex min-h-14 items-center gap-3 text-lg"><input v-model="avis" type="radio" value="hote" class="h-7 w-7">Avis de l’hôte</label>
      <label class="flex min-h-14 items-center gap-3 text-lg"><input v-model="avis" type="radio" value="superviseur" class="h-7 w-7">Avis du superviseur</label>
    </fieldset>
    <div v-if="!refusing" class="grid gap-4 sm:grid-cols-2">
      <div><button type="button" class="btn-ok" :disabled="!!validateReason || busy" :aria-describedby="validateReason ? 'decision-raison' : undefined" @click="emit('validate', avis || undefined)"><span aria-hidden="true">✓</span> Valider</button><p v-if="validateReason" id="decision-raison" class="mt-2">{{ validateReason }}</p></div>
      <div><button type="button" class="btn-no" :disabled="busy" @click="refusing = true"><span aria-hidden="true">✕</span> Refuser</button></div>
    </div>
    <form v-else class="space-y-4 rounded-xl border-2 border-red-700 bg-red-50 p-4" aria-label="Refuser le visiteur" @submit.prevent="emit('refuse', motif.trim())">
      <label class="block text-lg font-semibold">Motif du refus (obligatoire)<textarea v-model="motif" rows="3" maxlength="500" class="field" required></textarea></label>
      <div class="grid gap-3 sm:grid-cols-2"><button type="button" class="btn-soft" @click="refusing = false">Annuler</button><button type="submit" class="btn-no" :disabled="!motif.trim() || busy"><span aria-hidden="true">✕</span> Confirmer le refus</button></div>
    </form>
    <button type="button" class="btn-soft" @click="$emit('back')">Retour</button>
  </section>
</template>
