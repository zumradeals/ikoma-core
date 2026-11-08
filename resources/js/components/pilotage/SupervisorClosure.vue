<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/** Clôture d'une personne à clôture réservée au superviseur, en deux temps : motif, puis confirmation explicite (sortie + restitution + clôture, définitives). */
const props = defineProps<{ visitorId: string; offer: { possible: boolean; vehicles_present: number } }>();
const page = usePage<{ errors: Record<string, string> }>();
const step = ref<'repos' | 'motif' | 'confirmation'>('repos');
const reason = ref(''); const busy = ref(false);
const error = computed(() => page.props.errors?.participation ?? page.props.errors?.reason ?? page.props.errors?.confirmation ?? null);
function send() {
  busy.value = true;
  router.post(`/pilotage/visiteurs/${props.visitorId}/cloture-superviseur`, { reason: reason.value, confirmation: true }, {
    preserveScroll: true, onSuccess: () => { step.value = 'repos'; reason.value = ''; }, onFinish: () => { busy.value = false; },
  });
}
</script>
<template>
  <section class="space-y-3 rounded-2xl border-2 border-ikoma-navy bg-white p-4" aria-label="Clôture réservée au superviseur" data-testid="cloture-superviseur">
    <h2 class="text-heading font-bold">Clôture réservée au superviseur</h2>
    <p>La catégorie de cette personne se clôt uniquement par vous : sortie, restitution du badge et clôture de sa participation, en une seule opération.</p>
    <p v-if="error" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-cloture"><span aria-hidden="true">⚠</span> {{ error }}</p>
    <p v-if="!offer.possible" data-testid="cloture-deja">Cette participation est déjà clôturée ou retirée.</p>
    <p v-else-if="offer.vehicles_present > 0" class="rounded-xl border-2 border-orange-600 bg-orange-50 p-3 font-semibold" data-testid="cloture-vehicule"><span aria-hidden="true">▲</span> {{ offer.vehicles_present }} véhicule{{ offer.vehicles_present > 1 ? 's' : '' }} encore présent{{ offer.vehicles_present > 1 ? 's' : '' }} : la sortie du véhicule se fait d’abord à un poste d’accueil.</p>
    <template v-else-if="step === 'repos'">
      <button type="button" class="btn-no" data-testid="cloture-debut" @click="step = 'motif'">Clôturer cette personne</button>
    </template>
    <template v-else-if="step === 'motif'">
      <label class="block text-lg font-semibold">Motif de clôture (obligatoire)<textarea v-model="reason" class="field" rows="2" maxlength="200" data-testid="cloture-motif"></textarea></label>
      <div class="flex flex-wrap gap-3">
        <button type="button" class="btn-no !w-auto" :disabled="!reason.trim()" data-testid="cloture-suite" @click="step = 'confirmation'">Continuer</button>
        <button type="button" class="btn-soft" @click="step = 'repos'">Retour</button>
      </div>
    </template>
    <template v-else>
      <p class="rounded-xl bg-red-50 p-3 text-lg font-bold text-red-900" role="alert"><span aria-hidden="true">⚠</span> La sortie, la restitution du badge et la clôture sont définitives. Motif : « {{ reason }} ».</p>
      <div class="flex flex-wrap gap-3">
        <button type="button" class="btn-no !w-auto" :disabled="busy" data-testid="cloture-confirmer" @click="send">Confirmer la clôture</button>
        <button type="button" class="btn-soft" @click="step = 'motif'">Retour</button>
      </div>
    </template>
  </section>
</template>
