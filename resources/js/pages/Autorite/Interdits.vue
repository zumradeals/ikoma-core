<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue';
import { displayDate } from '../../labels';
type Entry = { id: string; full_name: string; reason: string; created_at: string; added_by: string };
defineProps<{ entries: Entry[] }>();
const form = useForm({ full_name: '', reason: '' });
function add() { form.post('/autorite/interdits', { preserveScroll: true, onSuccess: () => form.reset() }); }
function lift(e: Entry) {
  if (!window.confirm(`Lever l’interdiction visant « ${e.full_name} » ?`)) return;
  router.delete(`/autorite/interdits/${e.id}`, { preserveScroll: true });
}
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link>
<h1 class="mb-2 text-display font-bold">Liste d’interdits</h1>
<p class="mb-4">Cette liste vaut pour tous les événements. Quand une personne de la liste se présente, l’agent d’accueil voit seulement « personne signalée : appelez le superviseur » et le superviseur reçoit une alerte critique. <strong>Le motif n’est visible que sur cet écran.</strong> Un nom identique peut être un homonyme : le superviseur décide.</p>
<form class="mb-6 space-y-3 rounded-xl bg-white p-4" data-testid="ajout-interdit" @submit.prevent="add">
  <label class="block font-semibold" for="nom">Nom complet</label>
  <input id="nom" v-model="form.full_name" class="min-h-14 w-full rounded-xl border-2 px-3" maxlength="160" required>
  <p v-if="form.errors.full_name" role="alert" class="text-red-800">{{ form.errors.full_name }}</p>
  <label class="block font-semibold" for="motif">Motif (réservé à l’officier de sécurité)</label>
  <textarea id="motif" v-model="form.reason" class="min-h-24 w-full rounded-xl border-2 px-3 py-2" maxlength="1000" required />
  <p v-if="form.errors.reason" role="alert" class="text-red-800">{{ form.errors.reason }}</p>
  <button type="submit" class="action" :disabled="form.processing">Ajouter à la liste</button>
</form>
<p v-if="!entries.length" class="rounded-xl bg-white p-4" data-testid="liste-vide">Aucune personne n’est sur la liste.</p>
<ul v-else class="space-y-3" data-testid="liste-interdits">
  <li v-for="e in entries" :key="e.id" class="rounded-xl bg-white p-4">
    <p class="font-bold">{{ e.full_name }}</p>
    <p>{{ e.reason }}</p>
    <p class="text-sm">Ajoutée par {{ e.added_by }} le {{ displayDate(e.created_at) }}</p>
    <button type="button" class="action mt-2" data-testid="lever" @click="lift(e)">Lever l’interdiction</button>
  </li>
</ul>
</BaseLayout>
</template>
