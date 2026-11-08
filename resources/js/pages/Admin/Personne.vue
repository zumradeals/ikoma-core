<script setup lang="ts">
import { computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BaseCard from '../../components/BaseCard.vue';
import FormButton from '../../components/FormButton.vue';

type Participation = { id: string; event_id: string; event_name: string; event_kind: string; category: string; status: string | null; valid_from: string; valid_until: string; withdrawn_at: string | null; closed_at: string | null; closure_reason: string | null; holder_id: string | null };
type Assignment = { id: string; badge_code: string; badge_id: string; event_name: string; assigned_at: string; returned_at: string | null; cancelled_at: string | null; cancellation_reason: string | null };
const props = defineProps<{
  person: { id: string; full_name: string; organisation: string | null; status: string };
  participations: Participation[]; assignments: Assignment[]; holder: { identifier: string; status: string } | null;
  events: { id: string; name: string; kind: string }[]; categories: { id: string; event_id: string; name: string }[]; hosts: { id: string; event_id: string; name: string }[];
}>();
const kinds: Record<string, string> = { evenement: 'Événement', stage: 'Stage', visite: 'Visite', permanent: 'Permanent' };
const enrol = useForm({ event_id: props.events[0]?.id ?? '', category_id: '', host_id: '' });
const categories = computed(() => props.categories.filter(c => c.event_id === enrol.event_id));
const hosts = computed(() => props.hosts.filter(h => h.event_id === enrol.event_id));
const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('fr-FR') : '');
const state = (p: Participation) => (p.closed_at ? 'Clôturée' : p.withdrawn_at ? 'Retirée' : ({ attendu: 'Attendue', present: 'Présente', sorti: 'Sortie', refuse: 'Accès refusé' } as Record<string, string>)[p.status ?? 'attendu'] ?? p.status ?? '');
const assignmentState = (a: Assignment) => (a.cancelled_at ? 'Annulé' : a.returned_at ? 'Restitué le ' + day(a.returned_at) : 'En cours');
function submitEnrol() { enrol.transform(d => ({ ...d, host_id: d.host_id || null })).post(`/administration/personnes/${props.person.id}/participations`); }
function close(p: Participation) {
  const reason = window.prompt('Motif de la clôture de cette participation (la personne et ses autres participations sont conservées) :');
  if (reason && reason.trim()) router.post(`/administration/participations/${p.id}/cloturer`, { reason: reason.trim() });
}
</script>
<template>
<AdminLayout active="personnes">
<p class="mb-2"><Link href="/administration/personnes">← Toutes les personnes</Link></p>
<h1 class="mb-2 text-display font-bold" data-testid="nom-personne">{{ person.full_name }}</h1>
<p class="mb-6 text-slate-600"><template v-if="person.organisation">{{ person.organisation }} · </template><template v-if="holder">Personnel permanent ({{ holder.identifier }}) · </template>{{ person.status === 'archive' ? 'Archivée' : 'Identité active' }}</p>
<div class="space-y-6">
<BaseCard>
<h2 class="mb-4 text-heading font-bold">Participations aux activités</h2>
<ul v-if="participations.length" class="space-y-3" data-testid="liste-participations">
<li v-for="p in participations" :key="p.id" class="rounded-xl border border-slate-200 p-4" :data-testid="'participation-' + p.id">
<p class="font-bold">{{ p.event_name }} <span class="text-sm font-normal text-slate-600">({{ kinds[p.event_kind] ?? p.event_kind }})</span></p>
<p class="text-sm text-slate-600">{{ p.category }} · {{ day(p.valid_from) }} – {{ day(p.valid_until) }} · {{ state(p) }}<template v-if="p.closure_reason"> · {{ p.closure_reason }}</template></p>
<button v-if="!p.closed_at && !p.withdrawn_at && !p.holder_id" type="button" class="action mt-3" :data-testid="'cloturer-' + p.id" @click="close(p)">Clôturer cette participation</button>
</li>
</ul>
<p v-else data-testid="aucune-participation">Cette personne ne participe à aucune activité.</p>
</BaseCard>
<BaseCard>
<h2 class="mb-4 text-heading font-bold">Inscrire à une activité</h2>
<form v-if="events.length" class="space-y-4" aria-label="Inscrire à une activité" data-testid="form-inscription" @submit.prevent="submitEnrol">
<label class="block">Activité<select v-model="enrol.event_id" class="field" required><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }} ({{ kinds[event.kind] ?? event.kind }})</option></select></label>
<label class="block">Catégorie (rôle dans cette activité)<select v-model="enrol.category_id" class="field" required><option value="" disabled>Choisir…</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
<label class="block">Hôte (facultatif)<select v-model="enrol.host_id" class="field"><option value="">Aucun</option><option v-for="h in hosts" :key="h.id" :value="h.id">{{ h.name }}</option></select></label>
<p v-for="error in enrol.errors" :key="error" role="alert">{{ error }}</p>
<FormButton :busy="enrol.processing">Inscrire</FormButton>
</form>
<p v-else data-testid="aucune-activite">Aucune activité ouverte à laquelle inscrire cette personne.</p>
</BaseCard>
<BaseCard>
<h2 class="mb-4 text-heading font-bold">Historique des badges</h2>
<ul v-if="assignments.length" class="space-y-2" data-testid="liste-attributions">
<li v-for="a in assignments" :key="a.id" class="rounded-xl border border-slate-200 p-3"><Link :href="'/administration/badges/' + a.badge_id" class="font-bold">{{ a.badge_code }}</Link> · {{ a.event_name }} · remis le {{ day(a.assigned_at) }} · {{ assignmentState(a) }}</li>
</ul>
<p v-else data-testid="aucun-badge">Aucun badge n’a encore été attribué à cette personne.</p>
</BaseCard>
</div>
</AdminLayout>
</template>
