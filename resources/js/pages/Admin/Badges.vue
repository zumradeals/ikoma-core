<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BadgesCartesShell from '../../components/admin/BadgesCartesShell.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue'; import BadgeState from '../../components/BadgeState.vue'; import CategorySwatch from '../../components/CategorySwatch.vue';
import BadgeLotForm from '../../components/BadgeLotForm.vue'; import BadgeReservationForm from '../../components/BadgeReservationForm.vue'; import BadgeSettingsForm from '../../components/BadgeSettingsForm.vue';
type Batch = { id: string; name: string; event_id: string; event_name: string; quantity: number; margin: number; category_name: string | null; first_number: number | null; last_number: number | null };
const props = defineProps<{
  badges: { data: { id: string; code: string; status: string; batch_name: string; reserved_station: string | null; reserved_group: string | null; category_name: string | null; category_color: string | null; category_priority: boolean | null }[]; total: number; prev_page_url: string | null; next_page_url: string | null };
  batchCounts?: Record<string, { status: string; total: number; reserved: number }[]>; batches: Batch[]; statuses: string[]; filters: { status?: string; batch?: string; reserved?: string };
  events: { id: string; name: string }[]; categories: { id: string; event_id: string; name: string; color: string }[]; devices: { id: string; event_id: string; name: string; station: string | null }[]; groups: { id: string; event_id: string; name: string }[];
  settings: { margin: number; reusable: boolean };
}>();
const form = useForm({ status: props.filters.status || '', batch: props.filters.batch || '', reserved: props.filters.reserved || '' });
// Badges disponibles qui ne sont réservés à aucun poste ni groupe : inutilisables sur tablette tant qu'ils ne sont pas affectés.
const toAssign = computed(() => Object.values(props.batchCounts ?? {}).flat().filter(c => c.status === 'Disponible').reduce((sum, c) => sum + c.total - c.reserved, 0));
const available = computed(() => Object.values(props.batchCounts ?? {}).flat().filter(c => c.status === 'Disponible').reduce((sum, c) => sum + c.total, 0));
</script>
<template>
<AdminLayout active="badges">
    <BadgesCartesShell active="visiteurs" />
<h1 class="mb-3 text-display font-bold">Administration · Badges</h1>
<p class="mb-4 max-w-4xl">Les badges des <strong>visiteurs temporaires</strong>, en trois temps : fabriquer un lot, l’affecter à un poste ou à un groupe, puis suivre. Aucun nom de personne n’apparaît ici. Les cartes des personnes permanentes se préparent depuis <Link href="/administration/titulaires" class="font-semibold underline" data-testid="lien-cartes-permanentes">le registre des personnes permanentes</Link> ou l’Atelier des badges (mode « Cartes permanentes »).</p>
<p class="mb-4 max-w-4xl rounded-xl border border-blue-200 bg-blue-50 p-3" data-testid="rappel-accueil">L’attribution d’un badge à une personne et sa restitution se font à l’accueil, par l’agent : la restitution est un acte distinct de la sortie physique.</p>
<p class="mb-4"><Link href="/administration/badges/registre" class="btn-soft" data-testid="aller-registre">Registre commun : mettre des badges disponibles à disposition d’une autre activité</Link></p>
<nav class="mb-6 flex flex-wrap gap-3" aria-label="Les trois étapes" data-testid="etapes-badges">
  <a href="#fabriquer" class="rounded-xl border-2 border-[#0066B3] bg-white px-5 py-3 font-bold" data-testid="aller-fabriquer"><span class="mr-2 inline-grid size-8 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">1</span>Fabriquer</a>
  <a href="#affecter" class="rounded-xl border-2 px-5 py-3 font-bold" :class="toAssign > 0 ? 'border-amber-600 bg-amber-50' : 'border-slate-300 bg-white'" data-testid="aller-affecter"><span class="mr-2 inline-grid size-8 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">2</span>Affecter <span class="font-normal" data-testid="nb-a-affecter">({{ toAssign }} sans poste)</span></a>
  <a href="#suivre" class="rounded-xl border-2 border-slate-300 bg-white px-5 py-3 font-bold" data-testid="aller-suivre"><span class="mr-2 inline-grid size-8 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">3</span>Suivre <span class="font-normal">({{ available }} disponibles)</span></a>
  <Link href="/administration/atelier" class="action ml-auto" data-testid="ouvrir-atelier">Imprimer dans l’Atelier des badges</Link>
</nav>

<div class="mb-8 space-y-6">
<BaseCard id="fabriquer">
<p class="mb-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm" data-testid="note-lots-historiques"><strong>Fonctions historiques.</strong> Pour préparer le badge d’une ou de plusieurs personnes, utilisez « Préparer un badge » : sans lot ni réservation. Les lots et réservations ci-dessous restent disponibles pour le stock existant.</p><h2 class="mb-2 text-heading font-bold">1. Fabriquer des badges</h2><p class="mb-4">Choisissez l’événement, la catégorie et le nombre de personnes attendues. Une marge est ajoutée automatiquement.</p><BadgeLotForm :events="events" :categories="categories" :margin="settings.margin" />
<details class="mt-5 rounded-xl border p-4" data-testid="reglages-badges"><summary class="cursor-pointer font-bold">Réglages (marge de fabrication, badge restitué)</summary><div class="mt-4"><BadgeSettingsForm :settings="settings" /></div></details>
</BaseCard>
<BaseCard id="affecter">
<h2 id="reservation-badges" class="mb-2 text-heading font-bold">2. Affecter à un poste ou à un groupe</h2>
<p class="mb-4">Un lot fabriqué n’est pas utilisable tout de suite : pour scanner ses badges sur une tablette, affectez-les à un poste d’accueil (ou à un groupe).<strong v-if="toAssign > 0" class="ml-1 text-amber-800" data-testid="alerte-sans-poste"> {{ toAssign }} badge(s) disponible(s) ne sont encore affectés à aucun poste.</strong></p><BadgeReservationForm :batches="batches" :devices="devices" :groups="groups" />
</BaseCard>
<BaseCard id="suivre"><h2 class="mb-5 text-heading font-bold">Lots et étiquettes</h2><ul class="space-y-4"><li v-for="batch in batches" :key="batch.id" class="flex flex-wrap items-center justify-between gap-4"><span><strong>{{ batch.name }}</strong> · {{ batch.event_name }}<br>{{ batch.quantity }} badge(s) dont {{ batch.margin }} de marge<template v-if="batch.first_number !== null"> · n° {{ batch.first_number }} à {{ batch.last_number }}</template></span><p v-for="count in batchCounts?.[batch.id] ?? []" :key="count.status">{{ count.status }} : {{ count.total }} · réservés : {{ count.reserved }}</p><Link class="btn-soft" :href="`/administration/badges?batch=${batch.id}`">Consulter le lot</Link><Link class="btn-soft" :href="`/administration/donnees?entity=lot&id=${batch.id}`">Analyser une suppression</Link><a class="action" :href="`/administration/atelier?format=etiquettes&batch=${batch.id}`">Imprimer les étiquettes</a></li></ul><p v-if="!batches.length">Aucun lot pour le moment.</p></BaseCard>
</div>
<BaseCard><h2 class="mb-4 text-heading font-bold">Tous les badges</h2><form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end" aria-label="Filtrer les badges" @submit.prevent="form.get('/administration/badges')"><label class="block">Statut<select v-model="form.status" class="field"><option value="">Tous les statuts</option><option v-for="status in statuses" :key="status">{{ status }}</option></select></label><label class="block">Lot<select v-model="form.batch" data-testid="filtre-lot" class="field"><option value="">Tous les lots</option><option v-for="batch in batches" :key="batch.id" :value="batch.id">{{ batch.event_name }} · {{ batch.name }}</option></select></label><label class="block">Réservation<select v-model="form.reserved" class="field"><option value="">Tous les badges</option><option value="oui">Réservés à un poste ou à un groupe</option><option value="non">Sans réservation</option></select></label><FormButton :busy="form.processing">Filtrer les badges</FormButton><p v-for="error in form.errors" :key="error" role="alert" class="sm:col-span-2 lg:col-span-4">{{ error }}</p></form></BaseCard>
<p class="my-4">{{ badges.total }} badge(s) correspondant aux filtres</p>
<div class="overflow-x-auto rounded-xl border bg-white">
<table v-if="badges.data.length" class="w-full text-left" data-testid="table-badges">
<thead><tr class="border-b bg-slate-50"><th class="p-3">Badge</th><th class="p-3">Catégorie</th><th class="p-3">État</th><th class="p-3">Lot</th><th class="p-3">Réservation</th><th class="p-3"><span class="sr-only">Détails</span></th></tr></thead>
<tbody>
<tr v-for="badge in badges.data" :key="badge.id" class="border-b align-top" :data-testid="'badge-' + badge.code">
<th scope="row" class="p-3 font-bold">{{ badge.code }}</th>
<td class="p-3"><CategorySwatch v-if="badge.category_name" :name="badge.category_name" :color="badge.category_color || ''" :priority="!!badge.category_priority" /><span v-else>Sans catégorie (lot hérité)</span></td><td class="p-3"><BadgeState :status="badge.status" /></td><td class="p-3">{{ badge.batch_name }}</td>
<td class="p-3" :class="!badge.reserved_station && !badge.reserved_group && badge.status === 'Disponible' ? 'font-bold text-amber-800' : ''">{{ badge.reserved_station ? `Poste réservé : ${badge.reserved_station}` : badge.reserved_group ? `Groupe réservé : ${badge.reserved_group}` : (badge.status === 'Disponible' ? 'Sans réservation : inutilisable sur tablette' : '—') }}</td>
<td class="p-3"><Link class="btn-soft" :href="`/administration/badges/${badge.id}`">Consulter les détails</Link></td>
</tr>
</tbody>
</table>
</div>
<p v-if="!badges.data.length" class="mt-4">Aucun badge pour ces filtres.</p><nav class="mt-6 flex gap-4"><Link v-if="badges.prev_page_url" :href="badges.prev_page_url" class="action">Précédent</Link><Link v-if="badges.next_page_url" :href="badges.next_page_url" class="action">Suivant</Link></nav>
</AdminLayout>
</template>
