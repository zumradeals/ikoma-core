<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminGeneral, { type General } from '../../components/admin/AdminGeneral.vue';

/**
 * Tableau de bord de l'administrateur : une seule vue d'ensemble (AdminGeneral), en lecture seule. La préparation pas à pas d'une activité n'est plus affichée ici :
 * chaque fonction se trouve dans son menu (Activités, Badges et cartes, Opérations, Administration) et les alertes renvoient vers elles.
 */
defineProps<{ general?: General | null; event: { id: string; name: string } | null; events: { id: string; name: string }[] }>();
</script>

<template>
  <AdminLayout active="tableau" :event="event" :events="events">
    <AdminGeneral v-if="general" :general="general" />
    <section v-else class="rounded-2xl border border-slate-200 bg-white p-6" data-testid="aucun-evenement">
      <h1 class="text-display font-bold">Tableau de bord</h1>
      <p class="mt-2">Les indicateurs ne sont pas disponibles pour ce compte.</p>
      <Link href="/administration/evenements" class="admin-primary mt-5 inline-flex min-h-14 items-center gap-3" data-testid="creer-premier-evenement">Gérer les activités</Link>
    </section>
  </AdminLayout>
</template>
