<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLogo from '../components/BrandLogo.vue';
import { useInstitution } from '../composables/useInstitution';

/**
 * Gabarit de la page de connexion institutionnelle : bandeau, identité à gauche, carte de connexion à droite, pied de page.
 * L'identité (nom, nom complet, logo, couleur) vient de l'institution enregistrée ; sans identité, celle d'IKOMA ACCESS s'affiche.
 * Présentation seulement : aucune règle d'authentification n'est portée ici.
 */
defineProps<{ title: string }>();
const institution = useInstitution();
// Le logo d'origine d'IKOMA ACCESS est foncé : sur le fond marine, il est posé sur un disque blanc. Un logo institutionnel enregistré s'affiche tel quel.
const plated = computed(() => !institution.value.custom || !institution.value.logoUrl);
</script>

<template>
  <Head :title="title + ' — ' + institution.name" />
  <div class="login-page flex min-h-dvh flex-col text-[var(--inst-primary)]">
    <a href="#formulaire-auth" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:p-4">Aller au formulaire</a>

    <header class="login-band text-white" data-testid="bandeau-connexion">
      <div class="flex min-h-16 items-center justify-between gap-4 px-4 sm:min-h-[4.5rem] sm:px-8 lg:px-[4.5rem]">
        <a href="/" :aria-label="'Retour à l\'accueil ' + institution.name" class="flex min-w-0 items-center gap-3 sm:gap-4">
          <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full sm:h-[52px] sm:w-[52px]" :class="plated ? 'bg-white p-1.5' : ''"><BrandLogo :size="52" class="h-full w-full" /></span>
          <span class="hidden h-9 w-px bg-white/45 sm:block" aria-hidden="true"></span>
          <span class="text-xl font-extrabold tracking-wide sm:text-[1.7rem]">{{ institution.name }}</span>
          <span v-if="institution.fullName && institution.fullName !== institution.name" class="hidden min-w-0 truncate text-lg text-white/90 xl:block">{{ institution.fullName }}</span>
        </a>
        <p class="flex shrink-0 items-center gap-2 text-sm font-medium sm:text-base">
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>
          <span class="hidden sm:inline">Accès sécurisé</span>
        </p>
      </div>
      <div class="login-gold h-[3px]" aria-hidden="true"></div>
    </header>

    <div class="grid flex-1 md:grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:grid-rows-1">
      <!-- Identité institutionnelle : bandeau complet centré sur téléphone (la carte de connexion le chevauche), bande compacte sur tablette, composition complète sur ordinateur. -->
      <aside class="login-identity relative flex flex-col items-center justify-center overflow-hidden px-6 pb-16 pt-6 text-center text-white md:flex-row md:gap-6 md:px-8 md:py-6 lg:flex-col lg:gap-0 lg:px-12 lg:py-16" aria-label="Identité de l'institution">
        <div aria-hidden="true" class="login-arcs pointer-events-none absolute inset-0"></div>
        <span class="relative grid h-24 w-24 shrink-0 md:h-20 md:w-20 place-items-center overflow-hidden rounded-full lg:h-56 lg:w-56 xl:h-64 xl:w-64" :class="plated ? 'bg-white p-4 lg:p-8' : ''"><BrandLogo :size="256" class="h-full w-full" /></span>
        <div class="relative mt-3 min-w-0 text-center md:mt-0 md:text-left lg:mt-6 lg:text-center">
          <p class="text-4xl font-extrabold tracking-wide md:text-3xl lg:text-6xl">{{ institution.name }}</p>
          <div class="login-gold mx-auto my-3 block h-px w-32 md:hidden lg:block lg:w-60" aria-hidden="true"></div>
          <p v-if="institution.fullName && institution.fullName !== institution.name" class="max-w-md text-[15px] leading-snug text-white/90 md:text-lg lg:text-[1.7rem] lg:leading-snug">{{ institution.fullName }}</p>
          <p v-else-if="institution.tagline" class="max-w-md text-lg text-white/90 lg:text-2xl">{{ institution.tagline }}</p>
        </div>
      </aside>

      <main id="formulaire-auth" class="login-form-zone relative -mt-11 flex min-w-0 items-start justify-center px-4 pb-8 sm:px-8 md:mt-0 md:items-center md:py-8 lg:px-12 lg:py-16">
        <section class="w-full max-w-[620px] rounded-2xl bg-white p-5 shadow-[0_18px_55px_rgba(11,47,92,.10)] sm:p-9 lg:p-12">
          <slot />
        </section>
      </main>
    </div>

    <footer class="border-t border-[#D9E4F1] bg-[#F4F8FB] px-4 py-4 text-sm text-[#3F5878] sm:px-8 lg:px-14">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p class="flex items-start gap-3">
          <svg class="mt-0.5 h-6 w-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.7.3-1 .8-1 1.5M12 16.6h.01" /></svg>
          <span>Besoin d’aide pour votre compte ? Contactez l’administrateur de votre organisation.</span>
        </p>
        <p class="sm:text-right">© {{ institution.name }} – Tous droits réservés.</p>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.login-page { background: #EBF1F8; }
.login-band { background: color-mix(in srgb, var(--inst-primary) 82%, #000); }
.login-gold { background: linear-gradient(90deg, #C99A2E, #F0C55A 50%, #C99A2E); }
.login-identity { background: linear-gradient(135deg, color-mix(in srgb, var(--inst-primary) 88%, #000) 0%, var(--inst-primary) 55%, color-mix(in srgb, var(--inst-primary) 70%, #fff) 100%); }
.login-arcs {
  background:
    radial-gradient(circle at 85% 15%, rgba(255, 255, 255, .045) 0 22%, transparent 22.3%),
    radial-gradient(circle at 10% 95%, rgba(255, 255, 255, .05) 0 30%, transparent 30.3%);
}
@media (min-width: 1024px) {
  .login-identity::after { content: ''; position: absolute; inset: 0 0 0 auto; width: 18%; background: linear-gradient(90deg, transparent, rgba(235, 241, 248, .55)); pointer-events: none; }
}
</style>
