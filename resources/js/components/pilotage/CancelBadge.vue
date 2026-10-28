<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

/** Annulation d'un badge en deux temps : motif, puis confirmation explicite (l'action est irréversible). */
const props = defineProps<{ visitorId?: string; eventId?: string; label?: string }>();
const page = usePage<{ errors: Record<string, string> }>();
const step = ref<'repos' | 'motif' | 'confirmation'>('repos');
const code = ref(''); const motif = ref(''); const busy = ref(false);
const error = computed(() => page.props.errors?.pilotage ?? null);
const ready = computed(() => motif.value.trim() !== '' && (props.visitorId !== undefined || code.value.trim() !== ''));
function send() {
  busy.value = true;
  router.post('/pilotage/badges/annuler', { visitor_id: props.visitorId, code: props.visitorId ? undefined : code.value, event_id: props.eventId, motif: motif.value, confirmation: true }, {
    preserveScroll: true, onSuccess: () => { step.value = 'repos'; code.value = ''; motif.value = ''; }, onFinish: () => { busy.value = false; },
  });
}
</script>
<template>
  <section class="space-y-3 rounded-2xl border-2 border-red-700 bg-white p-4" aria-label="Annuler un badge" data-testid="annulation">
    <h2 class="text-heading font-bold">{{ label ?? 'Annuler un badge' }}</h2>
    <p v-if="error" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-pilotage"><span aria-hidden="true">⚠</span> {{ error }}</p>
    <template v-if="step === 'repos'">
      <button type="button" class="btn-no" data-testid="annuler-debut" @click="step = 'motif'"><span aria-hidden="true">✕</span> Annuler ce badge</button>
    </template>
    <template v-else-if="step === 'motif'">
      <label v-if="!visitorId" class="block text-lg font-semibold">Code imprimé du badge<input v-model="code" class="field uppercase" autocomplete="off" maxlength="100" data-testid="annuler-code"></label>
      <label class="block text-lg font-semibold">Motif (obligatoire)<textarea v-model="motif" class="field" rows="2" maxlength="500" data-testid="annuler-motif"></textarea></label>
      <div class="flex flex-wrap gap-3">
        <button type="button" class="btn-no !w-auto" :disabled="!ready" data-testid="annuler-suite" @click="step = 'confirmation'">Continuer</button>
        <button type="button" class="btn-soft" @click="step = 'repos'">Retour</button>
      </div>
      <p v-if="!ready" class="text-sm">Le bouton s’active quand le motif{{ visitorId ? '' : ' et le code' }} sont renseignés.</p>
    </template>
    <template v-else>
      <p class="rounded-xl bg-red-50 p-3 text-lg font-bold text-red-900" role="alert"><span aria-hidden="true">⚠</span> Cette annulation est définitive : le badge ne pourra plus servir. Motif : « {{ motif }} ».</p>
      <div class="flex flex-wrap gap-3">
        <button type="button" class="btn-no !w-auto" :disabled="busy" data-testid="annuler-confirmer" @click="send">Confirmer l’annulation</button>
        <button type="button" class="btn-soft" @click="step = 'motif'">Retour</button>
      </div>
    </template>
  </section>
</template>
