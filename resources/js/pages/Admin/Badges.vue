<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue'; import BadgeState from '../../components/BadgeState.vue'; import CategorySwatch from '../../components/CategorySwatch.vue';
import BadgeLotForm from '../../components/BadgeLotForm.vue'; import BadgeReservationForm from '../../components/BadgeReservationForm.vue'; import BadgeSettingsForm from '../../components/BadgeSettingsForm.vue';
type Batch = { id: string; name: string; event_id: string; quantity: number; margin: number; category_name: string | null; first_number: number | null; last_number: number | null };
const props = defineProps<{
  badges: { data: { id: string; code: string; status: string; batch_name: string; reserved_station: string | null; reserved_group: string | null; category_name: string | null; category_color: string | null; category_priority: boolean | null }[]; total: number; prev_page_url: string | null; next_page_url: string | null };
  batches: Batch[]; statuses: string[]; filters: { status?: string; batch?: string; reserved?: string };
  events: { id: string; name: string }[]; categories: { id: string; event_id: string; name: string; color: string }[]; devices: { id: string; event_id: string; name: string; station: string | null }[]; groups: { id: string; event_id: string; name: string }[];
  settings: { margin: number; reusable: boolean };
}>();
const form = useForm({ status: props.filters.status || '', batch: props.filters.batch || '', reserved: props.filters.reserved || '' });
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link><h1 class="mb-4 text-display font-bold">Administration · Badges</h1><p class="mb-6">Fabriquez les lots de badges par catégorie, réservez des plages à un poste ou à un groupe, puis imprimez les étiquettes. Aucun nom de personne n’apparaît sur cet écran.</p>
<div class="mb-8 space-y-6">
<BaseCard><h2 class="mb-5 text-heading font-bold">Générer un lot</h2><BadgeLotForm :events="events" :categories="categories" :margin="settings.margin" /></BaseCard>
<BaseCard><h2 class="mb-5 text-heading font-bold">Réserver une plage de badges</h2><BadgeReservationForm :batches="batches" :devices="devices" :groups="groups" /></BaseCard>
<BaseCard><h2 class="mb-5 text-heading font-bold">Réglages des badges</h2><BadgeSettingsForm :settings="settings" /></BaseCard>
<BaseCard><h2 class="mb-5 text-heading font-bold">Lots et étiquettes</h2><ul class="space-y-4"><li v-for="batch in batches" :key="batch.id" class="flex flex-wrap items-center justify-between gap-4"><span><strong>{{ batch.name }}</strong><br>{{ batch.quantity }} badge(s) dont {{ batch.margin }} de marge<template v-if="batch.first_number !== null"> · n° {{ batch.first_number }} à {{ batch.last_number }}</template></span><a class="action" :href="`/administration/badges/etiquettes?batch=${batch.id}`">Imprimer les étiquettes</a></li></ul><p v-if="!batches.length">Aucun lot pour le moment.</p></BaseCard>
</div>
<BaseCard><form class="space-y-5" aria-label="Filtrer les badges" @submit.prevent="form.get('/administration/badges')"><label class="block">Statut<select v-model="form.status" class="field"><option value="">Tous les statuts</option><option v-for="status in statuses" :key="status">{{ status }}</option></select></label><label class="block">Lot<select v-model="form.batch" class="field"><option value="">Tous les lots</option><option v-for="batch in batches" :key="batch.id" :value="batch.id">{{ batch.name }}</option></select></label><label class="block">Réservation<select v-model="form.reserved" class="field"><option value="">Tous les badges</option><option value="oui">Réservés à un poste ou à un groupe</option><option value="non">Sans réservation</option></select></label><p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p><FormButton :busy="form.processing">Filtrer les badges</FormButton></form></BaseCard>
<p class="my-6">{{ badges.total }} badge(s) correspondant aux filtres</p><ol class="grid gap-4 sm:grid-cols-2"><li v-for="badge in badges.data" :key="badge.id"><BaseCard><h2 class="mb-3 text-heading font-bold">{{ badge.code }}</h2><p class="mb-3"><CategorySwatch v-if="badge.category_name" :name="badge.category_name" :color="badge.category_color || ''" :priority="!!badge.category_priority" /><span v-else>Sans catégorie (lot hérité)</span></p><BadgeState :status="badge.status" /><p class="mt-4">{{ badge.batch_name }}</p><p>{{ badge.reserved_station ? `Poste réservé : ${badge.reserved_station}` : badge.reserved_group ? `Groupe réservé : ${badge.reserved_group}` : 'Sans réservation' }}</p></BaseCard></li></ol><p v-if="!badges.data.length">Aucun badge pour ces filtres.</p><nav class="mt-6 flex gap-4"><Link v-if="badges.prev_page_url" :href="badges.prev_page_url" class="action">Précédent</Link><Link v-if="badges.next_page_url" :href="badges.next_page_url" class="action">Suivant</Link></nav>
</BaseLayout>
</template>
