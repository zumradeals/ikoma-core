<script setup lang="ts">
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';

type Entitlement = { id: string; visitor_id: string; person_id: string; full_name: string; zone_id: string; zone: string; kind: 'standard' | 'exceptionnelle'; reason: string | null; valid_from: string; valid_until: string; suspended_at: string | null; suspension_reason: string | null; revoked_at: string | null; revocation_reason: string | null };
const props = defineProps<{
  events: { id: string; name: string }[]; eventId: string | null; q: string; zones: { id: string; name: string }[]; points: { id: string; name: string }[];
  mappings: { id: string; device_id: string; point: string; zone_id: string; zone: string }[]; entitlements: Entitlement[]; participants: { id: string; full_name: string; organisation: string | null }[]; maxHours: number; now: string;
}>();
const mapForm = useForm({ event_id: props.eventId ?? '', device_id: '', zone_id: '' });
const grantForm = useForm({ visitor_id: '', zone_id: '', kind: 'standard', reason: '', valid_from: '', valid_until: '' });
const maxForm = useForm({ max_heures: props.maxHours });
const search = useForm({ q: props.q });
const state = (e: Entitlement) => e.revoked_at ? 'Retirée' : e.suspended_at ? 'Suspendue' : Date.parse(e.valid_until) < Date.parse(props.now) ? 'Expirée' : Date.parse(e.valid_from) > Date.parse(props.now) ? 'À venir' : 'Active';
const chosen = computed(() => props.participants.find(p => p.id === grantForm.visitor_id));
function chooseEvent(id: string) { router.get('/administration/acces', { event: id }); }
function find() { router.get('/administration/acces', { event: props.eventId, q: search.q }, { preserveState: true }); }
function saveMap() { mapForm.event_id = props.eventId ?? ''; mapForm.post('/administration/acces/correspondances', { preserveScroll: true, onSuccess: () => mapForm.reset('device_id', 'zone_id') }); }
function unmap(id: string) { if (window.confirm('Retirer cette correspondance ?')) router.post(`/administration/acces/correspondances/${id}/retirer`, {}, { preserveScroll: true }); }
function grant() { grantForm.post('/administration/acces/habilitations', { preserveScroll: true, onSuccess: () => grantForm.reset('visitor_id', 'reason') }); }
function act(id: string, action: 'suspendre' | 'retirer') {
  const reason = window.prompt(action === 'suspendre' ? 'Motif de la suspension ?' : 'Motif du retrait ?');
  if (reason && reason.trim()) router.post(`/administration/acces/habilitations/${id}/${action}`, { reason }, { preserveScroll: true });
}
function resume(id: string) { router.post(`/administration/acces/habilitations/${id}/reprendre`, {}, { preserveScroll: true }); }
function saveMax() { maxForm.put('/administration/acces/reglage', { preserveScroll: true }); }
</script>
<template>
<AdminLayout active="acces-zones">
<h1 class="mb-3 text-display font-bold">Administration · Accès aux zones</h1>
<p class="mb-4 max-w-4xl">Deux réglages, réservés à l’administrateur. <strong>Quelles zones garde chaque point de contrôle</strong>, puis <strong>les habilitations des participants</strong> en complément de leur catégorie. Une habilitation <strong>exceptionnelle</strong> exige un motif et dure au plus {{ maxHours }} heure(s). Le superviseur peut consulter, jamais modifier. <strong>Suspendre ou retirer une habilitation ne retire que l’accès supplémentaire qu’elle accordait</strong> : les zones de la catégorie de la personne restent autorisées.</p>
<label class="mb-5 block max-w-md font-semibold">Activité
  <select class="field" data-testid="choix-activite" :value="eventId ?? ''" @change="chooseEvent(($event.target as HTMLSelectElement).value)"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select>
</label>
<template v-if="eventId">
<section class="admin-card mb-6 space-y-3" data-testid="correspondances">
  <h2>Points de contrôle et zones</h2>
  <p>Sans correspondance, un point de type « poste » ne juge pas la zone. Un point qui garde plusieurs zones est franchi si la personne est autorisée dans l’une d’elles.</p>
  <ul>
    <li v-for="m in mappings" :key="m.id" class="my-2 flex items-center justify-between gap-3 border-b pb-2" data-testid="correspondance"><span>{{ m.point }} → <strong>{{ m.zone }}</strong></span><button type="button" class="btn-soft" @click="unmap(m.id)">Retirer</button></li>
    <li v-if="!mappings.length">Aucune correspondance.</li>
  </ul>
  <form class="flex flex-wrap items-end gap-3" @submit.prevent="saveMap">
    <label class="font-semibold">Point<select v-model="mapForm.device_id" class="field" data-testid="map-point"><option value="">Choisir</option><option v-for="p in points" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
    <label class="font-semibold">Zone<select v-model="mapForm.zone_id" class="field" data-testid="map-zone"><option value="">Choisir</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option></select></label>
    <button type="submit" class="btn-main" :disabled="!mapForm.device_id || !mapForm.zone_id || mapForm.processing" data-testid="ajouter-correspondance">Associer</button>
  </form>
  <p v-if="mapForm.errors.zone_id" role="alert" class="text-red-800">{{ mapForm.errors.zone_id }}</p>
</section>
<section class="admin-card mb-6 space-y-3" data-testid="habilitations-participants">
  <h2>Habilitations des participants</h2>
  <form class="flex flex-wrap items-end gap-3" @submit.prevent="find"><label class="font-semibold">Chercher un participant<input v-model="search.q" class="field" minlength="2" data-testid="chercher-participant"></label><button type="submit" class="btn-soft">Chercher</button></form>
  <form class="space-y-3" @submit.prevent="grant">
    <label class="block font-semibold">Participant<select v-model="grantForm.visitor_id" class="field" data-testid="hab-participant"><option value="">Choisir (après recherche)</option><option v-for="p in participants" :key="p.id" :value="p.id">{{ p.full_name }}{{ p.organisation ? ' · ' + p.organisation : '' }}</option></select></label>
    <div class="flex flex-wrap gap-3">
      <label class="font-semibold">Zone<select v-model="grantForm.zone_id" class="field" data-testid="hab-zone"><option value="">Choisir</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option></select></label>
      <label class="font-semibold">Type<select v-model="grantForm.kind" class="field" data-testid="hab-type"><option value="standard">Standard</option><option value="exceptionnelle">Exceptionnelle (motif obligatoire, durée limitée)</option></select></label>
      <label class="font-semibold">Du<input v-model="grantForm.valid_from" type="datetime-local" class="field" data-testid="hab-du"></label>
      <label class="font-semibold">Au<input v-model="grantForm.valid_until" type="datetime-local" class="field" data-testid="hab-au"></label>
    </div>
    <label class="block font-semibold">Motif<input v-model="grantForm.reason" class="field" maxlength="200" data-testid="hab-motif"></label>
    <p v-if="Object.keys(grantForm.errors).length" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 text-red-900" data-testid="hab-erreur">{{ Object.values(grantForm.errors)[0] }}</p>
    <button type="submit" class="btn-main" :disabled="!grantForm.visitor_id || !grantForm.zone_id || grantForm.processing" data-testid="accorder">Accorder{{ chosen ? ' à ' + chosen.full_name : '' }}</button>
  </form>
  <ul data-testid="liste-habilitations">
    <li v-for="e in entitlements" :key="e.id" class="my-3 border-b pb-3" data-testid="habilitation">
    <p><strong>{{ e.full_name }}</strong> → {{ e.zone }} · {{ e.kind === 'exceptionnelle' ? 'Exceptionnelle' : 'Standard' }} · <span data-testid="etat">{{ state(e) }}</span></p>
    <p>Du {{ e.valid_from }} au {{ e.valid_until }}<template v-if="e.reason"> · Motif : {{ e.reason }}</template></p>
    <p v-if="e.suspended_at && !e.revoked_at">Suspendue : {{ e.suspension_reason }}</p><p v-if="e.revoked_at">Retirée : {{ e.revocation_reason }}</p>
    <div v-if="!e.revoked_at" class="mt-2 flex gap-2"><button v-if="!e.suspended_at" type="button" class="btn-soft" @click="act(e.id, 'suspendre')">Suspendre</button><button v-else type="button" class="btn-soft" @click="resume(e.id)">Reprendre</button><button type="button" class="btn-soft" @click="act(e.id, 'retirer')">Retirer</button></div>
    </li>
    <li v-if="!entitlements.length">Aucune habilitation.</li>
  </ul>
</section>
<section class="admin-card mb-6 space-y-3">
  <h2>Durée maximale d’une habilitation exceptionnelle</h2>
  <form class="flex items-end gap-3" @submit.prevent="saveMax"><label class="font-semibold">Heures<input v-model.number="maxForm.max_heures" type="number" min="1" max="168" class="field" data-testid="duree-max"></label><button type="submit" class="btn-main" data-testid="enregistrer-duree">Enregistrer</button></form>
</section>
</template>
</AdminLayout>
</template>
