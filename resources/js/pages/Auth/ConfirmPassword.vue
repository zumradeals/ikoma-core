<script setup lang="ts">
import AdminLayout from '../../layouts/AdminLayout.vue';
import { usePage } from '@inertiajs/vue3';
const page = usePage<{ auth: { isAdmin?: boolean } | null }>();
import { useForm } from '@inertiajs/vue3'; import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue'; const form = useForm({ password: '' });
</script>
<template><component :is="page.props.auth?.isAdmin ? AdminLayout : BaseLayout" active="securite"><BaseCard><h1 class="mb-4 text-display font-bold">Confirmer votre mot de passe</h1><p class="mb-6">Cette confirmation protège vos réglages de sécurité.</p><form class="space-y-5" @submit.prevent="form.post('/user/confirm-password', { onFinish: () => form.reset() })"><label>Mot de passe<input v-model="form.password" type="password" class="field" autocomplete="current-password" required></label><p v-for="e in form.errors" :key="e" role="alert">{{ e }}</p><FormButton :busy="form.processing">Confirmer</FormButton></form></BaseCard></component></template>
