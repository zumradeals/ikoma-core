<script setup lang="ts">
import { ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import LoginLayout from '../../layouts/LoginLayout.vue';

const form = useForm({ email: '', password: '' });
const page = usePage<{ flash?: { status?: string | null } }>();
const passwordVisible = ref(false);
function submit() {
  form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
  <LoginLayout title="Connexion">
    <div class="mb-1 h-1.5 w-24 rounded-full bg-[#D4A72C]" aria-hidden="true"></div>
    <h1 class="mt-7 text-3xl font-extrabold tracking-tight sm:text-4xl">Connexion sécurisée</h1>
    <p class="mt-3 text-lg text-[#4D6685]">Accès réservé aux utilisateurs habilités.</p>

    <p v-if="page.props.flash?.status" role="status" data-testid="statut" class="mt-6 rounded-lg border border-green-300 bg-green-50 p-4 text-sm font-semibold text-green-900">{{ page.props.flash.status }}</p>

    <form class="mt-8 space-y-6" @submit.prevent="submit">
      <label class="block text-lg font-bold">Adresse électronique
        <span class="relative mt-2 block">
          <svg class="pointer-events-none absolute left-4 top-1/2 h-6 w-6 -translate-y-1/2 text-[#1B3A63]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3.5 7 8.5 6 8.5-6" /></svg>
          <input v-model="form.email" type="email" name="email" autocomplete="username" inputmode="email" required autofocus placeholder="Ex. : nom@organisation.com" :aria-invalid="form.errors.email ? 'true' : undefined" class="block min-h-14 w-full rounded-lg border border-[#C5D1DD] bg-white py-3 pl-14 pr-4 text-base font-normal text-[var(--inst-primary)] placeholder:text-[#7C8DA3] focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[#0F5AA6]">
        </span>
      </label>

      <div>
        <label for="ikoma-password" class="block text-lg font-bold">Mot de passe</label>
        <div class="relative mt-2">
          <svg class="pointer-events-none absolute left-4 top-1/2 h-6 w-6 -translate-y-1/2 text-[#1B3A63]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>
          <input id="ikoma-password" v-model="form.password" name="password" :type="passwordVisible ? 'text' : 'password'" autocomplete="current-password" required placeholder="Votre mot de passe" :aria-invalid="form.errors.password ? 'true' : undefined" class="block min-h-14 w-full rounded-lg border border-[#C5D1DD] bg-white py-3 pl-14 pr-28 text-base font-normal text-[var(--inst-primary)] placeholder:text-[#7C8DA3] focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[#0F5AA6]">
          <button type="button" :aria-label="passwordVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" :aria-pressed="passwordVisible" class="absolute inset-y-1.5 right-1.5 min-w-[5.5rem] rounded-md border-l border-[#D5DEE8] px-4 text-base font-semibold text-[#0F4C97] hover:bg-[#EDF3FA] focus-visible:outline-3 focus-visible:outline-[#0F5AA6]" @click="passwordVisible = !passwordVisible">{{ passwordVisible ? 'Masquer' : 'Afficher' }}</button>
        </div>
      </div>

      <div v-if="Object.keys(form.errors).length" role="alert" data-testid="erreurs-connexion" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm font-medium text-red-800">
        <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
      </div>

      <button type="submit" :disabled="form.processing" class="flex min-h-14 w-full items-center justify-center gap-3 rounded-lg bg-[#0B3C72] px-6 text-lg font-bold text-white shadow-sm transition hover:bg-[#093260] focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-[#0F5AA6] disabled:cursor-wait disabled:opacity-60">
        <span>{{ form.processing ? 'Connexion en cours…' : 'Se connecter' }}</span><span aria-hidden="true">→</span>
      </button>
    </form>

    <div class="mt-8 flex items-start gap-4 rounded-lg bg-[#E4ECF6] p-4 text-base leading-relaxed text-[#2F4C70]">
      <svg class="mt-0.5 h-6 w-6 shrink-0 text-[#17407A]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>
      <p><strong class="text-[var(--inst-primary)]">Accès nominatif et sécurisé.</strong> Les comptes sont fournis par votre administrateur.</p>
    </div>
  </LoginLayout>
</template>
