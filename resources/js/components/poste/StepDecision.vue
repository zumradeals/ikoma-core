<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import type { Avis, Fiche } from '../../poste/types';
const props = defineProps<{ fiche?: Fiche; name: string; horsPeriode: boolean; blockedReason: string | null; busy: boolean; hostAdvice?:string|null; canRequest?:boolean }>();
const emit = defineEmits<{ validate: [avis: Avis | undefined]; refuse: [motif: string]; back: []; requestAdvice: []; refreshAdvice: [] }>();
let timer:number|undefined;onMounted(()=>{timer=window.setInterval(()=>{if(props.hostAdvice==='en_attente' && !props.busy && !document.hidden)emit('refreshAdvice');},2500);});onBeforeUnmount(()=>window.clearInterval(timer));
const avis = ref<Avis | ''>(''); const refusing = ref(false); const motif = ref('');
const validateReason = computed(() => props.blockedReason ?? (props.horsPeriode && props.hostAdvice==='refuse' && avis.value!=='superviseur' ? 'L’hôte a refusé : appelez le superviseur.' : props.horsPeriode && !avis.value && props.hostAdvice!=='autorise' ? 'Visiteur hors période : indiquez l’avis obtenu (hôte ou superviseur).' : ''));
const checks = computed(() => {
  const f = props.fiche; if (!f) return [];
  return [
    { ok: true, label: 'Fiche confirmée avec le visiteur' },
    { ok: f.identity_done, label: 'Pièce d’identité contrôlée' },
    { ok: f.photo_done, label: 'Photo du visiteur prise' },
    { ok: !f.hors_periode, label: f.hors_periode ? 'Hors de sa période de validité : un avis est nécessaire' : 'Dans sa période de validité' },
  ];
});
</script>
<template>
  <section aria-labelledby="decision-titre" class="space-y-5">
    <h1 id="decision-titre" class="text-display font-bold">Décision pour {{ name }}</h1>
    <div v-if="checks.length" class="rounded-2xl border-2 border-slate-300 bg-white p-5" data-testid="verifications">
      <h2 class="mb-2 text-heading font-bold">Ce qui a été vérifié</h2>
      <ul class="divide-y divide-slate-200">
        <li v-for="c in checks" :key="c.label" class="flex min-h-14 items-center gap-3 text-xl font-semibold" :data-ok="c.ok ? 'oui' : 'non'">
          <span class="poste-check-dot" :class="c.ok ? 'poste-check-ok' : 'poste-check-warn'" aria-hidden="true">{{ c.ok ? '✓' : '!' }}</span>{{ c.label }}
        </li>
      </ul>
    </div>
    <div v-if="horsPeriode && canRequest" class="space-y-4 rounded-xl border-2 border-ikoma-orange p-4"><p v-if="hostAdvice==='autorise'" role="status">✓ L’hôte autorise cette visite hors période.</p><p v-else-if="hostAdvice==='refuse'" role="status">✕ L’hôte n’autorise pas cette visite. Appelez le superviseur.</p><p v-else-if="hostAdvice==='en_attente'" role="status">⏳ Demande envoyée à l’hôte. En attente de sa réponse.</p><button v-else class="btn-main" :disabled="busy" @click="emit('requestAdvice')">Demander l’avis de l’hôte</button></div>
    <fieldset v-if="horsPeriode" class="rounded-xl border-2 border-red-700 bg-red-50 p-4">
      <legend class="px-2 font-bold text-red-900"><span aria-hidden="true">⚠</span> Hors période : de qui avez-vous obtenu l’avis ?</legend>
      <label class="flex min-h-14 items-center gap-3 text-lg"><input v-model="avis" type="radio" value="hote" class="h-7 w-7">Avis de l’hôte</label>
      <label class="flex min-h-14 items-center gap-3 text-lg"><input v-model="avis" type="radio" value="superviseur" class="h-7 w-7">Avis du superviseur</label>
    </fieldset>
    <div v-if="!refusing" class="poste-actionbar poste-actionbar-decision">
      <button type="button" class="btn-soft" @click="$emit('back')">Retour</button>
      <div class="poste-actionbar-right">
        <div><button type="button" class="btn-no" :disabled="busy" @click="refusing = true"><span aria-hidden="true">✕</span> Refuser</button></div>
        <div><button type="button" class="btn-ok" :disabled="!!validateReason || busy" :aria-describedby="validateReason ? 'decision-raison' : undefined" @click="emit('validate', avis || (hostAdvice==='autorise'?'hote':undefined))"><span aria-hidden="true">✓</span> Valider l’entrée</button><p v-if="validateReason" id="decision-raison" class="mt-2">{{ validateReason }}</p></div>
      </div>
    </div>
    <form v-else class="space-y-4 rounded-xl border-2 border-red-700 bg-red-50 p-4" aria-label="Refuser le visiteur" @submit.prevent="emit('refuse', motif.trim())">
      <label class="block text-lg font-semibold">Motif du refus (obligatoire)<textarea v-model="motif" rows="3" maxlength="500" class="field" required></textarea></label>
      <div class="grid gap-3 sm:grid-cols-2"><button type="button" class="btn-soft" @click="refusing = false">Annuler</button><button type="submit" class="btn-no" :disabled="!motif.trim() || busy"><span aria-hidden="true">✕</span> Confirmer le refus</button></div>
    </form>
    <button v-if="refusing" type="button" class="btn-soft" @click="$emit('back')">Retour</button>
  </section>
</template>
