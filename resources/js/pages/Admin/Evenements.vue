<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import { displayDate } from '../../labels';

defineProps<{
  events: { id: string; name: string; site: string | null; starts_at: string; ends_at: string }[];
}>();

const form = useForm({ name: '', site: '', starts_at: '', ends_at: '' });
function createEvent() {
  form.post('/administration/evenements', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  });
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
        <h2 id="titre-creer-evenement" class="text-2xl font-bold">Créer un événement</h2>
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
            {{ form.processing ? 'Création en cours…' : 'Créer l’événement' }}
          </button>
        </form>
      </section>

      <section class="admin-card" aria-labelledby="titre-liste-evenements">
        <h2 id="titre-liste-evenements" class="text-2xl font-bold">Événements enregistrés</h2>
        <p v-if="!events.length" class="admin-muted mt-4" data-testid="liste-evenements-vide">Aucun événement pour le moment. Utilisez le formulaire pour commencer.</p>
        <ul v-else class="mt-4 space-y-3" data-testid="liste-evenements">
          <li v-for="event in events" :key="event.id" class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="min-w-0">
              <h3 class="font-bold">{{ event.name }}</h3>
              <p v-if="event.site" class="text-sm text-slate-600">{{ event.site }}</p>
              <p class="mt-1 text-sm text-slate-600">{{ displayDate(event.starts_at) }} – {{ displayDate(event.ends_at) }}</p>
            </div>
            <a :href="'/administration?event=' + encodeURIComponent(event.id)" class="admin-primary inline-flex min-h-12 items-center gap-2" :data-testid="'ouvrir-evenement-' + event.id">
              Ouvrir <AdminIcon name="arrow" :size="18" />
            </a>
          </li>
        </ul>
      </section>
    </div>
  </AdminLayout>
</template>
