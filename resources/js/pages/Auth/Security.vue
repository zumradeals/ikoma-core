<script setup lang="ts">
import AgentLayout from '../../layouts/AgentLayout.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BaseLayout from '../../layouts/BaseLayout.vue';
import BaseCard from '../../components/BaseCard.vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage<{ auth: { isAdmin?: boolean; name: string; permissions: string[] } | null }>();
</script>

<template>
  <component :is="page.props.auth?.isAdmin ? AdminLayout : page.props.auth?.permissions.includes('visiteurs.rechercher') ? AgentLayout : BaseLayout" active="securite" :agent="page.props.auth?.name">
    <BaseCard>
      <h1 class="mb-4 text-display font-bold">Sécuriser mon compte</h1>
      <p>La double authentification est désactivée. L'accès reste protégé par le mot de passe personnel, les rôles et les limitations de connexion.</p>
    </BaseCard>
  </component>
</template>
