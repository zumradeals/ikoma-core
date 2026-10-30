<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import { displayDate } from '../../labels';

defineProps<{
  archives?: boolean;
  events: { id: string; name: string; site: string | null; starts_at: string; ends_at: string; status?: string | null }[];
}>();

const editing = ref<string | null>(null);
const operationError = ref('');
function transition(id: string, action: string) { if (window.confirm(action === 'cloturer' ? 'Clôturer cet événement ? Les présences, tablettes et opérations en attente seront vérifiées.' : 'Archiver cet événement clôturé ?')) router.post(`/administration/evenements/${id}/${action}`, { confirmation: true }, { onError: errors => { operationError.value = Object.values(errors).join(' '); } }); }
const form = useForm({ name: '', site: '', starts_at: '', ends_at: '' });
function createEvent() {
  const options = { preserveScroll: true, onSuccess: () => { form.reset(); editing.value = null; } };
  if (editing.value) form.put('/administration/evenements/' + editing.value, options);
  else form.post('/administration/evenements', options);
}
</script>

<template>
  <AdminLayout active="evenements">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-display font-bold">Administration · Événements</h1>
        <p class="admin-muted mt-2">Créez les événements dont vous souhaitez organiser l’accueil et contrôler les accès.</p>
      </div>
      <span class="rounded-full border border-blue-200 bg-white px-4 py-2 text-sm font-semibold">{{ events.length }} événement{{ events.length > 1 ? 's' : '' }}</span>
    </div>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(340px,2fr)_minmax(320px,3fr)]">
      <section class="admin-card" aria-labelledby="titre-creer-evenement">
        <h2 id="titre-creer-evenement" class="text-2xl font-bold">{{ editing ? "Modifier l’événement" : "Créer un événement" }}</h2>
        <p class="admin-muted mt-2">Une zone d’accueil sera créée automatiquement. Les autres réglages viendront ensuite.</p>
        <form class="mt-6 space-y-5" data-testid="form-creer-evenement" @submit.prevent="createEvent">
          <div>
            <label for="nom-evenement" class="block font-semibold">Nom de l’événement <span aria-hidden="true">*</span></label>
            <input id="nom-evenement" v-model="form.name" class="field" type="text" maxlength="160" placeholder="Ex. Forum des entreprises" required autocomplete="off" :aria-invalid="!!form.errors.name">
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.name }}</p>
          </div>
          <div>
            <label for="site-evenement" class="block font-semibold">Lieu / site (facultatif)</label>
            <input id="site-evenement" v-model="form.site" class="field" type="text" maxlength="255" placeholder="Ex. Abidjan – Cocody" :aria-invalid="!!form.errors.site">
            <p v-if="form.errors.site" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.site }}</p>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label for="debut-evenement" class="block font-semibold">Début <span aria-hidden="true">*</span></label>
              <input id="debut-evenement" v-model="form.starts_at" class="field" type="datetime-local" required :aria-invalid="!!form.errors.starts_at">
              <p v-if="form.errors.starts_at" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.starts_at }}</p>
            </div>
            <div>
              <label for="fin-evenement" class="block font-semibold">Fin <span aria-hidden="true">*</span></label>
              <input id="fin-evenement" v-model="form.ends_at" class="field" type="datetime-local" required :aria-invalid="!!form.errors.ends_at">
              <p v-if="form.errors.ends_at" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.ends_at }}</p>
            </div>
          </div>
          <p class="text-sm text-slate-600">La date de fin doit être postérieure au début. Les heures sont celles du site de l’événement.</p>
          <button type="submit" class="admin-primary flex min-h-14 items-center justify-center gap-2" :disabled="form.processing" data-testid="creer-evenement">
            <AdminIcon name="plus" :size="20" />
            {{ form.processing ? 'Enregistrement…' : (editing ? 'Enregistrer les modifications' : 'Créer l’événement') }}
          </button>
        </form>
      </section>

      <section class="admin-card" aria-labelledby="titre-liste-evenements">
        <Link :href="archives ? '/administration/evenements' : '/administration/evenements?archives=1'" class="btn-soft mb-4">{{ archives ? 'Voir les événements opérationnels' : 'Consulter les archives' }}</Link><p v-if="operationError" role="alert" class="my-4 text-red-800">{{ operationError }}</p><h2 id="titre-liste-evenements" class="text-2xl font-bold">Événements enregistrés</h2>
        <p v-if="!events.length" class="admin-muted mt-4" data-testid="liste-evenements-vide">Aucun événement pour le moment. Utilisez le formulaire pour commencer.</p>
        <ul v-else class="mt-4 space-y-3" data-testid="liste-evenements">
          <li v-for="event in events" :key="event.id" class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="min-w-0">
              <h3 class="font-bold">{{ event.name }}</h3><p>{{ event.status === 'archive' ? 'Archivé' : event.status === 'clos' ? 'Clôturé' : 'Ouvert' }}</p>
              <p v-if="event.site" class="text-sm text-slate-600">{{ event.site }}</p>
              <p class="mt-1 text-sm text-slate-600">{{ displayDate(event.starts_at) }} – {{ displayDate(event.ends_at) }}</p>
            </div>
            <a :href="'/administration?event=' + encodeURIComponent(event.id)" class="admin-primary inline-flex min-h-12 items-center gap-2" :data-testid="'ouvrir-evenement-' + event.id">
              Ouvrir <AdminIcon name="arrow" :size="18" />
            </a>
            <details class="w-full"><summary class="cursor-pointer font-semibold">Actions</summary><div class="mt-3 flex flex-wrap gap-3"><button v-if="!['clos', 'archive'].includes(event.status ?? '')" class="btn-soft" @click="editing = event.id; form.name = event.name; form.site = event.site ?? ''; form.starts_at = new Date(event.starts_at).toISOString().slice(0, 16); form.ends_at = new Date(event.ends_at).toISOString().slice(0, 16)">Modifier</button><button v-if="!['clos', 'archive'].includes(event.status ?? '')" class="btn-soft" @click="transition(event.id, 'cloturer')">Clôturer</button><button v-if="event.status === 'clos'" class="btn-soft" @click="transition(event.id, 'archiver')">Archiver</button><Link :href="`/administration/donnees?entity=evenement&id=${event.id}`" class="btn-soft">Analyser une suppression</Link></div></details>
          </li>
        </ul>
      </section>
    </div>
  </AdminLayout>
</template>
