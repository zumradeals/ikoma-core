<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormButton from './FormButton.vue';
const props = defineProps<{ user: { id: string; name: string }; challenge: { user_id: string; token: string } | null }>();
const prepare = useForm({});
const confirm = useForm({ token: '', password: '', confirmation: false });
function reset() {
  confirm.token = props.challenge?.token ?? '';
  confirm.post(`/administration/comptes/${props.user.id}/2fa/confirmer`, { onFinish: () => confirm.reset() });
}
</script>
<template>
<section class="space-y-4 border-t-2 pt-4">
  <h3 class="font-bold">Réinitialiser le double facteur · {{ user.name }}</h3>
  <p>À utiliser après vérification de l’identité. Toutes les sessions seront fermées et un nouveau double facteur sera exigé.</p>
  <form v-if="challenge?.user_id !== user.id" @submit.prevent="prepare.post(`/administration/comptes/${user.id}/2fa/preparer`)"><FormButton :busy="prepare.processing">Préparer la réinitialisation</FormButton></form>
  <form v-else class="space-y-4" @submit.prevent="reset">
    <label>Votre mot de passe administrateur<input v-model="confirm.password" type="password" class="field" autocomplete="current-password" required></label>
    <label class="flex min-h-14 items-center gap-3"><input v-model="confirm.confirmation" type="checkbox" required>Je confirme l’identité du titulaire et la fermeture de ses sessions.</label>
    <p v-for="error in confirm.errors" :key="error" role="alert">{{ error }}</p>
    <FormButton :busy="confirm.processing">Confirmer la réinitialisation du double facteur</FormButton>
  </form>
</section>
</template>
