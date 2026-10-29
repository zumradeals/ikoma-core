<script setup lang="ts">
import { ref } from 'vue'; import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue';
type Group = { id: string; name: string; bus_number: string | null; supervisor_id: string | null; supervisor_name: string | null; departed_at: string | null; members: { id: string; full_name: string; category: string }[]; badges_reserves: number; badges_libres: number };
const props = defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; groups: Group[]; mode: 'identite' | 'encadreur'; encadreurs: { id: string; name: string }[]; search: string; candidates: { id: string; full_name: string; organisation: string | null }[] }>();
const create = useForm({ event_id: props.event?.id ?? '', name: '', bus_number: '', supervisor_id: '' });
const mode = useForm({ mode: props.mode });
const q = ref(props.search);
const opened = ref<string | null>(null);
function submitCreate() { create.transform(d => ({ ...d, supervisor_id: d.supervisor_id || null, bus_number: d.bus_number || null })).post('/administration/groupes', { onSuccess: () => create.reset('name', 'bus_number', 'supervisor_id') }); }
function designate(g: Group, id: string) { router.put(`/administration/groupes/${g.id}/encadreur`, { supervisor_id: id || null }, { preserveScroll: true }); }
function attach(g: Group, visitor: string) { router.post(`/administration/groupes/${g.id}/stagiaires`, { visitor_id: visitor }, { preserveScroll: true }); }
function detach(g: Group, visitor: string) { router.delete(`/administration/groupes/${g.id}/stagiaires/${visitor}`, { preserveScroll: true }); }
function look() { router.get('/administration/groupes', { event: props.event?.id, q: q.value }, { preserveState: true, preserveScroll: true }); }
</script>
<template>
<AdminLayout active="groupes">
<h1 class="mb-4 text-display font-bold">Administration · Groupes</h1><p class="mb-6">Créez les cars de stagiaires, désignez l’encadreur de chaque groupe et rattachez les stagiaires. Un stagiaire ne peut appartenir qu’à un seul groupe. Les badges d’un groupe se réservent dans <Link href="/administration/badges" class="underline">Administration · Badges</Link>.</p>
<div class="space-y-6">
<BaseCard>
<h2 class="mb-3 text-heading font-bold">Contrôle de correspondance des stagiaires</h2><p class="mb-4">À l’appel, l’agent vérifie que la personne correspond bien à la liste. Choisissez comment : par la pièce d’identité de chaque stagiaire, ou par la confirmation de l’encadreur du groupe. <strong>Valeur initiale : confirmation de l’encadreur (à confirmer avec le client).</strong></p>
<form class="space-y-4" aria-label="Règle de contrôle" @submit.prevent="mode.put('/administration/groupes/reglage')">
<fieldset class="space-y-3">
<legend class="sr-only">Règle de contrôle</legend>
<label class="flex min-h-14 items-center gap-3"><input v-model="mode.mode" type="radio" value="encadreur" class="size-6" data-testid="mode-encadreur"> Confirmation de l’encadreur</label>
<label class="flex min-h-14 items-center gap-3"><input v-model="mode.mode" type="radio" value="identite" class="size-6" data-testid="mode-identite"> Pièce d’identité de chaque stagiaire</label>
</fieldset>
<FormButton :busy="mode.processing">Enregistrer la règle</FormButton>
</form>
</BaseCard>
<BaseCard>
<h2 class="mb-5 text-heading font-bold">Créer un groupe</h2>
<form class="space-y-5" aria-label="Créer un groupe" @submit.prevent="submitCreate">
<label class="block">Événement<select v-model="create.event_id" class="field" required><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select></label>
<label class="block">Nom du groupe<input v-model="create.name" class="field" maxlength="120" required autocomplete="off"></label>
<label class="block">Numéro du car<input v-model="create.bus_number" class="field" maxlength="60" autocomplete="off"></label>
<label class="block">Encadreur<select v-model="create.supervisor_id" class="field"><option value="">Aucun pour le moment</option><option v-for="u in encadreurs" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
<p v-for="error in create.errors" :key="error" role="alert">{{ error }}</p><FormButton :busy="create.processing">Créer le groupe</FormButton>
</form>
</BaseCard>
<BaseCard v-for="g in groups" :key="g.id">
<h2 class="text-heading font-bold">{{ g.bus_number ?? g.name }} · {{ g.name }}</h2>
<p class="mb-2">{{ g.members.length }} stagiaire(s) · {{ g.badges_reserves }} badge(s) réservé(s) dont {{ g.badges_libres }} libre(s)<template v-if="g.departed_at"> · car parti</template></p>
<label class="mb-4 block">Encadreur<select class="field" :value="g.supervisor_id ?? ''" @change="designate(g, ($event.target as HTMLSelectElement).value)"><option value="">Aucun</option><option v-for="u in encadreurs" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
<button type="button" class="action" :aria-expanded="opened === g.id" @click="opened = opened === g.id ? null : g.id">{{ opened === g.id ? 'Fermer les stagiaires' : 'Gérer les stagiaires' }}</button>
<div v-if="opened === g.id" class="mt-5 space-y-4">
<ul class="space-y-2"><li v-for="m in g.members" :key="m.id" class="flex min-h-14 items-center justify-between gap-3 rounded-xl bg-slate-50 px-4"><span>{{ m.full_name }} · {{ m.category }}</span><button type="button" class="btn-soft" @click="detach(g, m.id)">Retirer</button></li></ul>
<p v-if="!g.members.length">Aucun stagiaire dans ce groupe.</p>
<form class="space-y-3" aria-label="Chercher un stagiaire à ajouter" @submit.prevent="look"><label class="block">Chercher un stagiaire sans groupe<input v-model="q" class="field" autocomplete="off" data-testid="chercher-stagiaire"></label><button type="submit" class="btn-soft">Chercher</button></form>
<ul class="space-y-2"><li v-for="c in candidates" :key="c.id" class="flex min-h-14 items-center justify-between gap-3 rounded-xl bg-white px-4"><span>{{ c.full_name }}<template v-if="c.organisation"> · {{ c.organisation }}</template></span><button type="button" class="action" @click="attach(g, c.id)">Ajouter</button></li></ul>
</div>
</BaseCard>
</div><p v-if="!groups.length" class="mt-6">Aucun groupe enregistré.</p>
</AdminLayout>
</template>
