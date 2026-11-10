<script setup lang="ts">
import { computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BadgesCartesShell from '../../components/admin/BadgesCartesShell.vue';

type RegistryBadge = { id: string; code: string; event_id: string; event_name: string };
const props = defineProps<{
  target: string | null; badges: RegistryBadge[]; events: { id: string; name: string; kind: string }[]; devices: { id: string; event_id: string; name: string; station: string | null }[]; categories: { id: string; event_id: string; name: string; color: string }[];
  transfers: { id: string; code: string; from_name: string; to_name: string; reservation_released: boolean; created_at: string }[];
}>();
const form = useForm<{ badges: string[]; event_id: string; category_id: string; device_id: string }>({ badges: [], event_id: props.target ?? '', category_id: '', device_id: '' });
const categories = computed(() => props.categories.filter(c => c.event_id === form.event_id));
const postes = computed(() => props.devices.filter(d => d.event_id === form.event_id));
// Seuls les badges d'une AUTRE activité que la cible peuvent lui être mis à disposition.
const candidates = computed(() => props.badges.filter(b => b.event_id !== form.event_id));
function chooseTarget() { form.badges = []; form.device_id = ''; form.category_id = ''; router.get('/administration/badges/registre', form.event_id ? { activite: form.event_id } : {}, { preserveState: true, replace: true }); }
function submit() {
  if (!form.badges.length || !form.event_id || !form.category_id) return;
  if (!window.confirm(`Mettre ${form.badges.length} badge(s) à disposition de l’activité choisie ? Leur catégorie deviendra celle choisie et leur réservation actuelle sera levée.`)) return;
  form.transform(d => ({ badges: d.badges, event_id: d.event_id, category_id: d.category_id, device_id: d.device_id || null })).post('/administration/badges/mise-a-disposition', { preserveScroll: true, onSuccess: () => { form.badges = []; } });
}
</script>
<template>
<AdminLayout active="badges">
    <BadgesCartesShell active="lots" />
<h1 class="mb-3 text-display font-bold">Administration · Registre commun des badges</h1>
<p class="mb-5 max-w-4xl">Un badge physique n’appartient à aucune activité : il est <strong>attribué pour un temps</strong> à une participation, <strong>restitué</strong>, puis peut servir une autre activité. Ici, vous mettez des badges <strong>disponibles</strong> à disposition d’une autre activité. Un badge attribué ou non restitué ne peut pas être déplacé.</p>
<section class="admin-card mb-6 space-y-4" data-testid="mise-a-disposition-form">
  <h2>Mettre des badges à disposition</h2>
  <p v-if="form.errors.badges" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3 text-red-900" data-testid="erreur-registre">{{ form.errors.badges }}</p>
  <label class="block font-semibold">Activité qui reçoit les badges
    <select v-model="form.event_id" class="field" data-testid="activite-cible" @change="chooseTarget"><option value="">Choisir une activité</option><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select>
  </label>
  <label v-if="form.event_id" class="block font-semibold">Catégorie des badges dans cette activité
    <select v-model="form.category_id" class="field" data-testid="categorie-cible"><option value="">Choisir une catégorie</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
  </label>
  <label v-if="form.event_id" class="block font-semibold">Réserver à un poste de cette activité (facultatif)
    <select v-model="form.device_id" class="field" data-testid="poste-cible"><option value="">Aucun poste</option><option v-for="d in postes" :key="d.id" :value="d.id">{{ d.station ?? d.name }}</option></select>
  </label>
  <p v-if="form.event_id && !candidates.length" data-testid="aucun-badge">Aucun badge disponible dans une autre activité.</p>
  <ul v-if="form.event_id && candidates.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" data-testid="liste-badges-disponibles">
    <li v-for="b in candidates" :key="b.id"><label class="flex items-center gap-2 rounded-xl border-2 border-slate-300 bg-white px-3 py-2"><input v-model="form.badges" type="checkbox" :value="b.id" :data-testid="'choisir-' + b.code"><span><strong>{{ b.code }}</strong> · {{ b.event_name }}</span></label></li>
  </ul>
  <button type="button" class="btn-main" :disabled="!form.badges.length || !form.event_id || !form.category_id || form.processing" data-testid="mettre-a-disposition" @click="submit">Mettre à disposition ({{ form.badges.length }})</button>
</section>
<section class="admin-card mb-6" data-testid="dernieres-mises-a-disposition">
  <h2>Dernières mises à disposition</h2>
  <p v-if="!transfers.length" class="my-3">Aucune mise à disposition.</p>
  <ul><li v-for="t in transfers" :key="t.id" class="my-2 border-b pb-2">{{ t.code }} : « {{ t.from_name }} » → « {{ t.to_name }} » le {{ t.created_at }}<template v-if="t.reservation_released"> · réservation précédente levée</template></li></ul>
</section>
<Link href="/administration/badges" class="btn-soft">Revenir aux badges</Link>
</AdminLayout>
</template>
