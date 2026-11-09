<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { PosteRepository } from '../../poste/repository';
import { ApiError, type IncidentsView } from '../../poste/types';

/** Refus et incidents pertinents pour ce poste (lecture seule, en ligne uniquement) : contrôles refusés ici et, à l'accueil, accès refusés de l'activité. */
const props = defineProps<{ repository: PosteRepository; reasons?: Record<string, string> }>();
const view = ref<IncidentsView | null>(null);
const state = ref<'chargement' | 'pret' | 'erreur'>('chargement');
const error = ref('');
const hm = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const why = (reason: string | null) => (reason ? props.reasons?.[reason] ?? reason : 'Motif non précisé');

async function load(): Promise<void> {
  state.value = 'chargement'; error.value = '';
  try { view.value = (await props.repository.incidents?.()) ?? null; state.value = 'pret'; }
  catch (e) { error.value = e instanceof ApiError ? e.message : 'La consultation a échoué. Réessayez.'; state.value = 'erreur'; }
}
onMounted(() => { void load(); });
</script>

<template>
  <section class="space-y-3 rounded-2xl border-2 border-slate-300 bg-white p-5" aria-labelledby="incidents-titre" data-testid="incidents-poste">
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="incidents-titre" class="text-2xl font-bold">Refus et incidents</h2><button type="button" class="btn-soft !w-auto" data-testid="incidents-actualiser" @click="load">Actualiser</button></div>
    <p v-if="state === 'chargement'" role="status" data-testid="incidents-chargement">Chargement…</p>
    <div v-else-if="state === 'erreur'" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900" data-testid="incidents-erreur"><span aria-hidden="true">⚠</span> {{ error }}</div>
    <template v-else-if="view">
      <h3 class="text-lg font-bold">Contrôles refusés à ce poste</h3>
      <p v-if="!view.controls.length" data-testid="aucun-controle-refuse">Aucun contrôle refusé à ce poste.</p>
      <ul class="space-y-2" aria-label="Contrôles refusés"><li v-for="c in view.controls" :key="c.id" class="rounded-xl border border-red-300 bg-red-50 p-3" data-testid="controle-refuse"><strong>{{ why(c.reason) }}</strong> · {{ hm(c.occurred_at) }}<template v-if="c.checkpoint"> · {{ c.checkpoint }}</template><template v-if="c.full_name"> · {{ c.full_name }}</template><template v-if="c.badge_code"> · badge {{ c.badge_code }}</template></li></ul>
      <template v-if="view.can_see_refusals">
        <h3 class="text-lg font-bold">Accès refusés à l’accueil</h3>
        <p v-if="!view.refusals.length" data-testid="aucun-refus">Aucun accès refusé.</p>
        <ul class="space-y-2" aria-label="Accès refusés"><li v-for="r in view.refusals" :key="r.id" class="rounded-xl border border-slate-300 p-3" data-testid="refus-accueil"><strong>{{ r.full_name }}</strong> · {{ r.reason }} · {{ hm(r.refused_at) }} · {{ r.decision ? 'décision du superviseur : ' + r.decision : 'en attente de décision' }}</li></ul>
      </template>
    </template>
  </section>
</template>
