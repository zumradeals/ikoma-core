<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import PilotageLayout from '../../layouts/PilotageLayout.vue';
import CategorySwatch from '../../components/CategorySwatch.vue';
import CancelBadge from '../../components/pilotage/CancelBadge.vue';
import { displayDate } from '../../labels';

type Visitor = { id: string; full_name: string; organisation: string | null; status: string; present: boolean; source: string; access_note: string | null; host: string | null; group: string | null; valid_from: string; valid_until: string; category: { name: string; color: string; priority_circuit: boolean } };
const props = defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; visitor: Visitor; badge: { id: string; code: string; status: string } | null; toRebadge: { code: string; at: string; reason: string | null } | null; refusal: { id: string; reason: string; refused_at: string; decision: string | null; supervisor_decision: string | null } | null; permissions: { annuler: boolean; decider: boolean } }>();
const page = usePage<{ errors: Record<string, string> }>();
const statuses: Record<string, string> = { attendu: 'Attendu', present: 'Présent', sorti: 'Sorti', refuse: 'Accès refusé' };
const label = computed(() => (props.visitor.present ? 'Présent' : statuses[props.visitor.status] ?? props.visitor.status));
const note = ref(''); const choice = ref<'maintenu' | 'autorise' | 'a_revoir'>('maintenu');
function decide() { router.post(`/pilotage/litiges/${props.refusal!.id}/decision`, { decision: choice.value, note: note.value }, { preserveScroll: true }); }
</script>
<template>
  <PilotageLayout active="presents" :event="event" :events="events" :open-alerts="openAlerts" title="Fiche">
    <Link href="/pilotage/presents" class="inline-flex min-h-14 items-center underline">← Retour à la liste</Link>
    <section class="space-y-2 rounded-2xl border-2 border-ikoma-navy bg-white p-4" aria-label="Visiteur">
      <p class="text-2xl font-bold" data-testid="nom">{{ visitor.full_name }}</p>
      <CategorySwatch :name="visitor.category.name" :color="visitor.category.color" :priority="visitor.category.priority_circuit" />
      <p>{{ visitor.organisation || 'Organisation non indiquée' }}</p>
      <p>État : <strong data-testid="etat">{{ label }}</strong> · Reçu par : {{ visitor.host || '—' }} · Groupe : {{ visitor.group || '—' }}</p>
      <p class="text-sm">Valable du {{ displayDate(visitor.valid_from) }} au {{ displayDate(visitor.valid_until) }}</p>
      <p v-if="visitor.access_note" class="rounded-xl bg-amber-100 p-3 font-semibold text-amber-950" data-testid="mention"><span aria-hidden="true">✓</span> Accès autorisé par le superviseur : {{ visitor.access_note }}</p>
    </section>
    <section class="space-y-2 rounded-2xl border-2 border-slate-300 bg-white p-4" aria-label="Badge">
      <h2 class="text-heading font-bold">Badge</h2>
      <p v-if="badge" data-testid="badge">Badge <strong>{{ badge.code }}</strong> · {{ badge.status }}</p>
      <p v-else-if="toRebadge" class="rounded-xl border-2 border-orange-600 bg-orange-50 p-3 font-bold" data-testid="a-rebadger"><span aria-hidden="true">▲</span> À rebadger : le badge {{ toRebadge.code }} a été annulé. L’agent d’accueil doit associer un nouveau badge (vous n’avez pas ce droit).</p>
      <p v-else>Aucun badge associé.</p>
    </section>
    <CancelBadge v-if="badge && permissions.annuler" :visitor-id="visitor.id" />
    <section v-if="refusal" class="space-y-3 rounded-2xl border-2 border-red-700 bg-white p-4" aria-label="Refus">
      <h2 class="text-heading font-bold">Refus d’accès</h2>
      <p>Motif : {{ refusal.reason }} · {{ displayDate(refusal.refused_at) }}</p>
      <p v-if="refusal.decision" data-testid="decision">Décision : <strong>{{ { maintenu: 'refus maintenu', autorise: 'autorisé avec conditions', a_revoir: 'à revoir' }[refusal.decision] }}</strong><template v-if="refusal.supervisor_decision"> · {{ refusal.supervisor_decision }}</template></p>
      <template v-if="permissions.decider">
        <fieldset class="space-y-2">
<legend class="text-lg font-semibold">Votre décision</legend>
          <label v-for="o in [['maintenu', 'Maintenir le refus'], ['autorise', 'Autoriser avec conditions'], ['a_revoir', 'À revoir']]" :key="o[0]" class="flex min-h-14 items-center gap-3 text-lg"><input v-model="choice" type="radio" name="decision" :value="o[0]" class="h-6 w-6"> {{ o[1] }}</label>
        </fieldset>
        <label class="block text-lg font-semibold">Conditions ou remarque{{ choice === 'autorise' ? ' (obligatoire)' : '' }}<textarea v-model="note" class="field" rows="2" maxlength="500"></textarea></label>
        <p v-if="page.props.errors?.pilotage" role="alert" class="font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ page.props.errors.pilotage }}</p>
        <button type="button" class="btn-main" :disabled="choice === 'autorise' && !note.trim()" data-testid="decider" @click="decide">Enregistrer la décision</button>
      </template>
    </section>
  </PilotageLayout>
</template>
