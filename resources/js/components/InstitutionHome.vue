<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BrandLogo from './BrandLogo.vue';
import { useInstitution } from '../composables/useInstitution';

/** Page d'accueil publique d'une installation personnalisée : identité de l'institution et accès à la connexion, sans argumentaire commercial. */
const institution = useInstitution();
</script>

<template>
  <Head :title="institution.homeTitle || institution.name">
    <meta v-if="institution.homeDescription" name="description" :content="institution.homeDescription">
  </Head>
  <div class="flex min-h-dvh flex-col bg-[#F4F7FB] text-[var(--inst-primary)]" data-testid="accueil-institution">
    <a href="#contenu" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:p-4">Aller au contenu</a>
    <header class="border-b border-[#DDE8F3] bg-white">
      <div class="mx-auto flex min-h-[82px] w-full max-w-[1800px] items-center justify-between gap-4 px-5 sm:px-8 lg:px-12">
        <a href="/" class="flex items-center gap-3" :aria-label="institution.name + ' — accueil'">
          <BrandLogo :size="46" class="h-11 w-11" />
          <span class="text-lg font-extrabold tracking-tight sm:text-xl">{{ institution.name }}</span>
        </a>
        <a href="/login" class="inline-flex min-h-12 items-center rounded-lg bg-[var(--inst-primary)] px-5 text-sm font-bold text-white hover:opacity-90">Se connecter</a>
      </div>
    </header>
    <main id="contenu" class="flex flex-1 items-center bg-[var(--inst-primary)] px-5 py-14 text-white sm:px-8 lg:px-12">
      <div class="mx-auto w-full max-w-[1100px]">
        <p v-if="institution.fullName !== institution.name" class="text-sm font-bold uppercase tracking-[.16em] text-white/80">{{ institution.fullName }}</p>
        <h1 class="mt-4 max-w-3xl text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">{{ institution.homeTitle || institution.fullName }}</h1>
        <p v-if="institution.tagline" class="mt-4 text-xl font-semibold text-white/90">{{ institution.tagline }}</p>
        <p v-if="institution.homeDescription" class="mt-6 max-w-2xl text-base leading-relaxed text-white/90 sm:text-xl">{{ institution.homeDescription }}</p>
        <a href="/login" class="mt-9 inline-flex min-h-14 items-center justify-center rounded-lg bg-[var(--inst-accent)] px-8 text-lg font-bold text-white hover:opacity-90">Accéder à mon espace</a>
      </div>
    </main>
    <footer v-if="institution.footer" class="border-t border-[#DDE8F3] bg-white px-5 py-6 text-sm sm:px-8 lg:px-12">{{ institution.footer }}</footer>
  </div>
</template>
