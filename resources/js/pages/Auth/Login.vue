<script setup lang="ts">
import { ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AuthLayout from '../../layouts/AuthLayout.vue';
const form = useForm({ email: '', password: '' });
const page = usePage<{ flash?: { status?: string | null } }>();
const passwordVisible = ref(false);
function submit() {
  form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
  <AuthLayout title="Connexion" eyebrow="Accès professionnel sécurisé">
    <div class="mb-7 flex items-center justify-between gap-4">
      <div>
        <p class="text-sm font-bold uppercase tracking-wider text-[#F75C03]">Bienvenue sur IKOMA ACCESS</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Se connecter</h1>
        <p class="mt-3 text-base text-[#506D94]">Accédez à votre espace selon votre rôle et vos autorisations.</p>
      </div>
    </div>
    <p v-if="page.props.flash?.status" role="status" data-testid="statut" class="mb-5 rounded-lg border border-green-300 bg-green-50 p-4 text-sm font-semibold text-green-900">{{ page.props.flash.status }}</p>
    <form class="space-y-5" @submit.prevent="submit">
      <label class="block text-sm font-bold text-[#0B3C6B]">Adresse électronique
        <input v-model="form.email" type="email" autocomplete="username" inputmode="email" required autofocus placeholder="vous@entreprise.com" class="mt-2 block min-h-14 w-full rounded-lg border border-[#BDD0E4] bg-white px-4 text-base font-normal text-[#0B3C6B] placeholder:text-[#8096AE] focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[#0066B3]">
      </label>
      <div>
        <label for="ikoma-password" class="block text-sm font-bold text-[#0B3C6B]">Mot de passe</label>
        <div class="relative mt-2">
          <input id="ikoma-password" v-model="form.password" :type="passwordVisible ? 'text' : 'password'" autocomplete="current-password" required placeholder="Saisissez votre mot de passe" class="block min-h-14 w-full rounded-lg border border-[#BDD0E4] bg-white py-3 pl-4 pr-24 text-base font-normal text-[#0B3C6B] placeholder:text-[#8096AE] focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[#0066B3]">
          <button type="button" :aria-label="passwordVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" class="absolute inset-y-1 right-2 min-h-12 rounded-lg px-3 text-sm font-semibold text-[#0053A4] hover:bg-[#EDF5FC]" @click="passwordVisible = !passwordVisible">{{ passwordVisible ? 'Masquer' : 'Afficher' }}</button>
        </div>
      </div>
      <div v-if="Object.keys(form.errors).length" role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-800">
        <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
      </div>
      <button type="submit" :disabled="form.processing" class="flex min-h-14 w-full items-center justify-center gap-3 rounded-lg bg-[#F75C03] px-6 text-base font-extrabold text-white shadow-sm transition hover:bg-[#DE5100] disabled:cursor-wait disabled:opacity-60">
        <span>{{ form.processing ? 'Connexion en cours…' : 'Se connecter' }}</span><span aria-hidden="true">→</span>
      </button>
    </form>
    <div class="mt-7 border-t border-[#E3EBF5] pt-6">
      <div class="flex items-start gap-3 rounded-lg bg-[#F1F6FC] p-4 text-sm leading-relaxed text-[#456381]">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-[#0066B3]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
        <p><strong class="text-[#0B3C6B]">Accès nominatif et sécurisé.</strong> Les comptes sont fournis par votre administrateur. La double authentification reste obligatoire pour les administrateurs et superviseurs.</p>
      </div>
      <p class="mt-5 text-center text-sm text-[#506D94]">Besoin d'aide pour votre compte ? Contactez l'administrateur de votre organisation.</p>
    </div>
  </AuthLayout>
</template>
