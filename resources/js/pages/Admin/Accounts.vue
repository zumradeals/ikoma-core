<script setup lang="ts">
import { roleLabels } from '../../labels';
import { useForm, Link } from '@inertiajs/vue3'; import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue'; import AccountRow from '../../components/AccountRow.vue';
defineProps<{ users: { id: string; name: string; email: string; active: boolean; role: string }[]; roles: string[]; idle: number }>();
const form = useForm({ name: '', email: '', password: '', role: 'agent_accueil' }); const settings = useForm({ minutes: 15 });
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link><h1 class="mb-6 text-display font-bold">Administration · Comptes</h1><div class="space-y-6">
<BaseCard>
<h2 class="mb-4 text-heading font-bold">Créer un compte</h2><p class="mb-5">Un rôle par personne. Transmettez le mot de passe par un canal sûr.</p>
<form class="space-y-5" @submit.prevent="form.post('/administration/comptes', { onSuccess: () => form.reset() })"><label>Nom<input v-model="form.name" class="field" required></label><label>Adresse électronique<input v-model="form.email" class="field" type="email" required></label><label>Mot de passe initial<input v-model="form.password" class="field" type="password" autocomplete="new-password" required minlength="12"></label><p>Au moins 12 caractères, majuscule, minuscule, chiffre et symbole.</p><label>Rôle<select v-model="form.role" class="field"><option v-for="role in roles" :key="role" :value="role">{{ roleLabels[role] }}</option></select></label><p v-for="e in form.errors" :key="e" role="alert">{{ e }}</p><FormButton :busy="form.processing">Créer le compte</FormButton></form>
</BaseCard>
<AccountRow v-for="user in users" :key="user.id" :user="user" :roles="roles" />
<BaseCard><h2 class="text-heading font-bold">Déconnexion après inactivité</h2><p>Durée actuelle : {{ idle }} minutes. Réglage commun à tous les comptes.</p><form class="mt-4 space-y-4" @submit.prevent="settings.put('/administration/comptes/inactivite')"><label>Nouvelle durée, en minutes<input v-model="settings.minutes" class="field" type="number" min="1" max="120" required></label><p v-for="e in settings.errors" :key="e" role="alert">{{ e }}</p><FormButton>Enregistrer la durée</FormButton></form></BaseCard>
</div>
</BaseLayout>
</template>
