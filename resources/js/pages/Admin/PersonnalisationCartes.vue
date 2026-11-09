<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import CardModelSection from '../../components/admin/CardModelSection.vue';
import type { CardModelProps } from '../../components/admin/card-model';

defineProps<CardModelProps>();
</script>

<template>
  <AdminLayout active="modeles-cartes">
    <header class="mb-4 space-y-2">
      <Link href="/administration/titulaires" class="font-semibold underline">← Registre des personnes</Link>
      <h1 class="text-display font-bold">Personnalisation des cartes</h1>
    </header>
    <CardModelSection v-bind="$props" />
  </AdminLayout>
</template>
