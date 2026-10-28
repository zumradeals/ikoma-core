<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue';
const form = useForm({ email: '', password: '' });
const page = usePage<{ flash?: { status?: string | null } }>();
</script>
<template>
<BaseLayout>
<BaseCard>
<h1 class="mb-4 text-display font-bold">Se connecter</h1><p v-if="page.props.flash?.status" role="status" class="mb-4 rounded-xl border-2 border-green-700 bg-green-50 p-4 font-semibold text-green-900" data-testid="statut">{{ page.props.flash.status }}</p><p class="mb-6">Utilisez le compte remis par votre administrateur.</p>
<form class="space-y-5" @submit.prevent="form.post('/login', { onFinish: () => form.reset('password') })">
<label class="block">Adresse électronique<input v-model="form.email" type="email" autocomplete="username" required class="field"></label>
<label class="block">Mot de passe<input v-model="form.password" type="password" autocomplete="current-password" required class="field"></label>
<p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p><FormButton :busy="form.processing">Se connecter</FormButton>
</form>
</BaseCard>
</BaseLayout>
</template>
