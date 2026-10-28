<script setup lang="ts">
import { computed } from 'vue';
import type { DeskSummary } from '../../poste/types';

const props = defineProps<{ summary: DeskSummary; online: boolean; syncing?: boolean }>();
const emit = defineEmits<{ sync: []; select: [id: string] }>();

const labels: Record<string, string> = { badge: 'Badges remis', entree: 'Entrées', refuser: 'Accès refusés', identite: 'Pièces d’identité', valider: 'Validations', non_liste: 'Visiteurs non listés', groupe_controle: 'Opérations de groupe', groupe_badge: 'Opérations de groupe', groupe_depart: 'Opérations de groupe', groupe_arrivee: 'Opérations de groupe', groupe_arrivee_fin: 'Opérations de groupe', controle: 'Contrôles', sortie: 'Sorties' };
const hour = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : 'jamais');
const pendingTotal = computed(() => props.summary.pending.reduce((n, p) => n + p.count, 0));
/** Plusieurs types d'opération portent le même libellé : on les additionne pour que la liste reste courte. */
const pendingLines = computed(() => {
  const lines = new Map<string, number>();
  for (const p of props.summary.pending) { const label = labels[p.type] ?? 'Autres opérations'; lines.set(label, (lines.get(label) ?? 0) + p.count); }
  return [...lines].map(([label, count]) => ({ label, count }));
});
const percent = computed(() => (props.summary.badges.total > 0 ? Math.round((props.summary.badges.restants / props.summary.badges.total) * 100) : 0));
</script>
<template>
  <aside aria-label="Mon poste" class="space-y-4" data-testid="panneau-poste">
    <section v-if="pendingTotal > 0" aria-labelledby="envoi-titre" class="space-y-2 rounded-2xl border-2 border-orange-700 bg-white p-4" data-testid="a-envoyer">
      <h2 id="envoi-titre" class="text-lg font-extrabold">À envoyer au serveur</h2>
      <p class="flex items-baseline gap-3"><span class="text-4xl font-extrabold leading-none" data-testid="a-envoyer-total">{{ pendingTotal }}</span><span class="text-slate-700">opération{{ pendingTotal > 1 ? 's' : '' }} en attente</span></p>
      <ul class="space-y-1 text-base"><li v-for="line in pendingLines" :key="line.label" class="flex justify-between"><span>{{ line.label }}</span><strong>{{ line.count }}</strong></li></ul>
      <p class="text-sm text-slate-700">Dernière synchronisation réussie : {{ hour(summary.asOf) }}</p>
      <button type="button" class="btn-main !min-h-14 !bg-orange-700 !text-lg" :disabled="syncing || !online" data-testid="reessayer" @click="emit('sync')">{{ online ? 'Envoyer maintenant' : 'Envoi dès le retour du réseau' }}</button>
    </section>

    <section aria-labelledby="poste-titre" class="space-y-3 rounded-2xl border border-slate-300 bg-white p-4">
      <h2 id="poste-titre" class="text-lg font-extrabold">Mon poste aujourd’hui</h2>
      <p v-if="!online" class="text-sm text-slate-700" data-testid="chiffres-arretes">Chiffres calculés sur la copie de cette tablette (liste de {{ hour(summary.asOf) }}).</p>
      <dl class="grid grid-cols-2 gap-2">
        <div class="rounded-xl bg-slate-100 p-3" data-testid="chiffre-accueillis"><dt class="text-sm font-semibold text-slate-700">Accueillis</dt><dd class="text-3xl font-extrabold leading-tight">{{ summary.accueillis }}</dd></div>
        <div class="rounded-xl bg-slate-100 p-3" data-testid="chiffre-refuses"><dt class="text-sm font-semibold text-slate-700">Refusés</dt><dd class="text-3xl font-extrabold leading-tight">{{ summary.refuses }}</dd></div>
      </dl>
      <div v-if="summary.badges.total > 0" data-testid="badges-poste">
        <p class="flex justify-between gap-2 font-bold"><span>Badges de ce poste</span><span>{{ summary.badges.restants }} restant{{ summary.badges.restants > 1 ? 's' : '' }} sur {{ summary.badges.total }}</span></p>
        <div class="mt-2 h-3 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Badges restants à ce poste" aria-valuemin="0" :aria-valuemax="summary.badges.total" :aria-valuenow="summary.badges.restants"><div class="h-full rounded-full bg-ikoma-blue" :style="{ width: percent + '%' }" /></div>
        <p v-if="summary.lowBadges" class="mt-2 rounded-lg bg-amber-100 px-3 py-2 font-semibold text-amber-950" data-testid="badges-bas"><span aria-hidden="true">⚠</span> Il reste peu de badges : prévenez le superviseur.</p>
      </div>
    </section>

    <section v-if="summary.waiting.length" aria-labelledby="hote-titre" class="space-y-2 rounded-2xl border-2 border-orange-700 bg-white p-4" data-testid="attente-hote">
      <h2 id="hote-titre" class="text-lg font-extrabold"><span aria-hidden="true">◷</span> {{ summary.waiting.length }} visiteur{{ summary.waiting.length > 1 ? 's' : '' }} attend{{ summary.waiting.length > 1 ? 'ent' : '' }} leur hôte</h2>
      <p class="text-sm text-slate-700">Sans réponse après le délai prévu, le superviseur est prévenu.</p>
      <ul class="space-y-2"><li v-for="w in summary.waiting.slice(0, 4)" :key="w.id"><button type="button" class="btn-soft w-full !min-h-14 justify-between" :data-testid="'reprendre-' + w.id" @click="emit('select', w.id)"><span>{{ w.name }}</span><span class="text-sm font-bold">Reprendre</span></button></li></ul>
    </section>

    <section v-if="summary.recent.length" aria-labelledby="derniers-titre" class="space-y-2 rounded-2xl border border-slate-300 bg-white p-4" data-testid="derniers-passages">
      <h2 id="derniers-titre" class="text-lg font-extrabold">Derniers passages</h2>
      <ul class="space-y-1 text-base"><li v-for="(r, i) in summary.recent" :key="i" class="flex justify-between gap-3"><span>{{ r.kind === 'refus' ? 'Accès refusé à ' : 'Badge remis à ' }}{{ r.name }}</span><strong class="text-slate-700">{{ hour(r.at) }}</strong></li></ul>
    </section>
  </aside>
</template>
