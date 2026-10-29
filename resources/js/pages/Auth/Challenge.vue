<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';
const recovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });
function toggleRecovery() {
  recovery.value = !recovery.value;
  form.reset();
  form.clearErrors();
}
function submit() {
  form.post('/two-factor-challenge', { onFinish: () => form.reset() });
}
</script>

<template>
  <AuthLayout title="Vérification de sécurité" eyebrow="Double authentification">
    <div class="mb-8 flex h-14 w-14 items-center justify-center rounded-xl bg-[#E6F2FF] text-[#0066B3]">
      <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 2 20 6v6c0 5-3.2 8.2-8 10-4.8-1.8-8-5-8-10V6l8-4Z"/><path d="m9 12 2 2 4-4"/></svg>
    </div>
    <h1 class="text-3xl font-extrabold tracking-tight sm:text-4xl">Vérifier votre connexion</h1>
    <p class="mb-8 mt-3 text-base text-[#506D94]">{{ recovery ? 'Saisissez un code de secours enregistré lors de l’activation de la sécurité de votre compte.' : 'Saisissez le code à six chiffres de votre application d’authentification.' }}</p>
    <form class="space-y-5" @submit.prevent="submit">
      <label v-if="!recovery" class="block text-sm font-bold">Code à six chiffres
        <input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" class="mt-2 block min-h-16 w-full rounded-lg border border-[#BDD0E4] bg-white px-4 text-center font-mono text-2xl tracking-[.18em] focus-visible:outline-3 focus-visible:outline-[#0066B3]" required pattern="[0-9]{6}" maxlength="6" placeholder="000000">
      </label>
      <label v-else class="block text-sm font-bold">Code de secours
        <input v-model="form.recovery_code" class="mt-2 block min-h-14 w-full rounded-lg border border-[#BDD0E4] px-4 text-base focus-visible:outline-3 focus-visible:outline-[#0066B3]" required autocomplete="off">
      </label>
      <div v-if="Object.keys(form.errors).length" role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm font-medium text-red-800"><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p></div>
      <button type="submit" :disabled="form.processing" class="flex min-h-14 w-full items-center justify-center rounded-lg bg-[#F75C03] px-6 font-extrabold text-white hover:bg-[#DE5100] disabled:opacity-60">{{ form.processing ? 'Vérification en cours…' : 'Vérifier' }}</button>
    </form>
    <button type="button" class="mt-6 min-h-14 w-full rounded-lg border border-[#C9D9EA] px-4 text-center text-sm font-semibold text-[#0053A4] hover:bg-[#EFF6FF]" @click="toggleRecovery">{{ recovery ? 'Utiliser un code à six chiffres' : 'Utiliser un code de secours' }}</button>
    <p class="mt-6 text-center text-sm text-[#506D94]">Cette étape protège votre espace et ses données.</p>
  </AuthLayout>
</template>
