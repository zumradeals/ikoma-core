<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import ProfilePortraitInput from '../../components/ProfilePortraitInput.vue';
type Choice = { id: string; event_id: string; name: string };
type Person = { id: string; event_id: string; full_name: string; organisation: string | null; position: string | null; host_id: string | null; category_id: string; group_id: string | null; valid_from: string; valid_until: string; has_photo: boolean; profile_photo_print_allowed: boolean; revision: string; withdrawn_at: string | null };
const props = defineProps<{ searchQuery?: string; list?: { data: { id: string; full_name: string; organisation: string | null }[]; current_page: number; last_page: number; total: number } | null; withdrawn?: boolean; withdrawnVisitors?: { id: string; full_name: string; organisation: string | null }[]; events: { id: string; name: string; starts_at: string; ends_at: string }[]; eventId: string | null; visitor: Person | null; hosts: Choice[]; categories: Choice[]; groups: Choice[]; editable: boolean; feedback: string | null; results: { id: string; full_name: string; organisation: string | null; host: string | null; homonyms: number }[] }>();
const event = props.events.find(e => e.id === props.eventId); const v = props.visitor;
const localDate = (value: string) => value ? new Date(value).toISOString().slice(0, 16) : '';
const form = useForm({ event_id: v?.event_id ?? props.eventId ?? '', full_name: v?.full_name ?? '', organisation: v?.organisation ?? '', position: v?.position ?? '', host_id: v?.host_id ?? '', category_id: v?.category_id ?? '', group_id: v?.group_id ?? '', valid_from: localDate(v?.valid_from ?? event?.starts_at ?? ''), valid_until: localDate(v?.valid_until ?? event?.ends_at ?? ''), photo: null as File | null, remove_photo: false, photo_print_allowed: v?.profile_photo_print_allowed ?? false });
const withdrawError = ref('');
const query = ref(props.searchQuery ?? ''); const deletedPhoto = ref(false);
const browseEvent = ref(props.eventId ?? '');
function selectListEvent() { query.value = ''; router.get('/administration/invites', { event_id: browseEvent.value }, { preserveState: false }); }
function listPage(n: number) { router.get('/administration/invites', { event_id: props.eventId, page: n }, { preserveState: false }); }
function clearSearch() { query.value = ''; router.get('/administration/invites', { event_id: props.eventId }, { preserveState: false }); }
const choices = (items: Choice[]) => items.filter(c => c.event_id === form.event_id);
const portrait = computed(() => v?.has_photo && !deletedPhoto.value ? `/administration/invites/${v.id}/portrait?v=${encodeURIComponent(v.revision)}` : null);
watch(() => form.event_id, () => { form.category_id = ''; form.host_id = ''; form.group_id = ''; const e = props.events.find(e => e.id === form.event_id); form.valid_from = localDate(e?.starts_at ?? ''); form.valid_until = localDate(e?.ends_at ?? ''); });
function save() { form.post('/administration/invites' + (v ? '/' + v.id : ''), { forceFormData: true, preserveState: false }); }
function search() { router.get('/administration/invites', { event_id: form.event_id, q: query.value }, { preserveState: false }); }
function withdraw() { if (v && window.confirm('Retirer cette fiche de la liste ? Son historique sera conservé.')) router.delete('/administration/invites/' + v.id, { onError: errors => { withdrawError.value = Object.values(errors).join(' '); } }); }
</script>
<template>
  <AdminLayout active="imports" :event="events.find(e => e.id === form.event_id) ?? null">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3"><div><h1>{{ visitor ? 'Fiche de la personne' : 'Ajouter une personne' }}</h1><p class="mt-2">Une fiche unique pour la liste, l’accueil et les badges. Sans fichier Excel.</p></div><Link href="/administration/imports" class="btn-soft">Importer une liste</Link></div>
    <p v-if="feedback" role="status" class="mb-5 rounded-xl border bg-green-50 p-4">{{ feedback }}</p>
    <section class="mb-6 space-y-4 rounded-xl border bg-white p-5" data-testid="liste-invites-visible">
      <h2 class="text-heading font-bold">Liste des invités</h2>
      <label class="block font-semibold">Liste de l’événement
        <select v-model="browseEvent" class="field mt-2" @change="selectListEvent">
          <option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option>
        </select>
      </label>
      <p v-if="list" class="font-semibold" data-testid="compteur-invites">{{ list.total }} personne{{ list.total > 1 ? 's' : '' }} inscrite{{ list.total > 1 ? 's' : '' }} dans cet événement.</p>
      <p v-if="!list || list.total === 0">Aucune personne enregistrée dans cet événement.</p>
      <template v-if="!searchQuery && list && list.total">
        <p class="admin-muted">Les invités sont affichés par ordre alphabétique (20 par page). Ouvrez une fiche pour la modifier.</p>
        <ul class="divide-y divide-slate-200">
          <li v-for="person in list.data" :key="person.id">
            <Link :href="'/administration/invites?visitor=' + person.id" class="block px-2 py-3 hover:bg-slate-50">
              <strong>{{ person.full_name }}</strong><span class="block text-sm">{{ person.organisation || 'Organisation non indiquée' }}</span>
            </Link>
          </li>
        </ul>
        <div v-if="list.last_page > 1" class="flex flex-wrap items-center gap-3" aria-label="Pagination des invités">
          <button type="button" class="btn-soft" :disabled="list.current_page <= 1" @click="listPage(list.current_page - 1)">Précédent</button>
          <span>Page {{ list.current_page }} sur {{ list.last_page }}</span>
          <button type="button" class="btn-soft" :disabled="list.current_page >= list.last_page" @click="listPage(list.current_page + 1)">Suivant</button>
        </div>
      </template>
    </section>
    <div class="grid items-start gap-6 xl:grid-cols-2">
      <form aria-label="Fiche individuelle" class="space-y-5 rounded-xl border bg-white p-6" @submit.prevent="save">
        <p v-if="!editable" role="status">Cette fiche a déjà servi à un contrôle. Ses informations sont conservées pour protéger l’historique.</p>
        <fieldset :disabled="!editable" class="space-y-4">
          <label class="block">Événement<select v-model="form.event_id" class="field" :disabled="!!visitor" required><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select></label>
          <label class="block">Nom complet<input v-model="form.full_name" class="field" maxlength="160" required></label>
          <label class="block">Catégorie<select v-model="form.category_id" class="field" required><option value="">Choisir une catégorie</option><option v-for="c in choices(categories)" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
          <label class="block">Hôte ou service (facultatif)<select v-model="form.host_id" class="field"><option value="">Sans hôte</option><option v-for="h in choices(hosts)" :key="h.id" :value="h.id">{{ h.name }}</option></select></label>
          <label class="block">Organisation (facultatif)<input v-model="form.organisation" class="field" maxlength="160"></label>
          <label class="block">Fonction ou qualité (facultatif)<input v-model="form.position" class="field" maxlength="160"></label>
          <label class="block">Groupe (facultatif)<select v-model="form.group_id" class="field"><option value="">Aucun groupe</option><option v-for="g in choices(groups)" :key="g.id" :value="g.id">{{ g.name }}</option></select></label>
          <label class="block">Début de validité (UTC)<input v-model="form.valid_from" class="field" type="datetime-local" required></label><label class="block">Fin de validité (UTC)<input v-model="form.valid_until" class="field" type="datetime-local" required></label>
          <ProfilePortraitInput :existing="portrait" @selected="form.photo = $event; if ($event) { form.remove_photo = false; deletedPhoto = false; }" @remove="form.remove_photo = true; deletedPhoto = true; form.photo_print_allowed = false" />
          <label class="flex min-h-14 items-center gap-3"><input v-model="form.photo_print_allowed" type="checkbox">Autoriser l’utilisation de ce portrait sur un badge nominatif selon la politique du client</label>
          <p class="text-base">Sans cette autorisation, le portrait reste visible sur la fiche habilitée mais n’est jamais imprimé.</p>
        </fieldset>
        <p v-for="error in form.errors" :key="error" role="alert" class="font-bold text-red-800">{{ error }}</p>
        <button type="submit" class="btn-main" :disabled="!editable || form.processing">{{ form.processing ? 'Enregistrement…' : 'Enregistrer la fiche' }}</button>
        <p v-if="withdrawError" role="alert" class="text-red-800">{{ withdrawError }}</p><div v-if="visitor" class="flex flex-wrap gap-3"><Link :href="`/administration/donnees?entity=personne&id=${visitor.id}`" class="btn-soft">Analyser une suppression</Link><Link :href="'/administration/invites?event_id=' + form.event_id" class="btn-soft">Ajouter une autre personne</Link><button type="button" class="btn-soft" :disabled="!!visitor.withdrawn_at" @click="withdraw">Retirer la fiche</button><Link v-if="!visitor.withdrawn_at" :href="'/administration/badges/modeles?person=' + visitor.id" class="btn-soft">Préparer un badge nominatif</Link></div>
      </form>
      <section class="space-y-5 rounded-xl border bg-white p-6"><h2>Retrouver une personne</h2><Link :href="'/administration/invites?event_id=' + form.event_id + '&withdrawn=1'" class="btn-soft">Consulter les fiches retirées</Link><p v-if="visitor?.withdrawn_at">Fiche retirée · historique conservé</p><ul v-if="withdrawnVisitors?.length"><li v-for="person in withdrawnVisitors" :key="person.id"><Link :href="'/administration/invites?visitor=' + person.id" class="block border-b p-3">{{ person.full_name }} · {{ person.organisation }}</Link></li></ul><form class="space-y-4" @submit.prevent="search"><label class="block">Nom à rechercher<input v-model="query" class="field" minlength="2" maxlength="160" required></label><div class="flex flex-wrap gap-2"><button type="submit" class="btn-soft">Rechercher dans cet événement</button><button v-if="searchQuery" type="button" class="btn-soft" @click="clearSearch">Voir toute la liste</button></div></form><ul v-if="searchQuery" class="space-y-4"><li v-for="person in results" :key="person.id"><Link :href="'/administration/invites?visitor=' + person.id" class="block rounded-lg border p-4"><strong>{{ person.full_name }}</strong><p>{{ person.organisation }} · {{ person.host }}</p><p v-if="person.homonyms > 1">Homonyme : vérifiez l’organisation ou l’hôte.</p></Link></li></ul><p v-if="searchQuery && !results.length">Aucun résultat pour cette recherche dans cet événement.</p><p v-if="!searchQuery">Tapez au moins 2 lettres pour rechercher une personne au-delà des premières pages.</p></section>
    </div>
  </AdminLayout>
</template>
