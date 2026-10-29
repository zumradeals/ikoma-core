<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
defineProps<{ title: string; eyebrow: string }>();
</script>

<template>
  <Head :title="title + ' — IKOMA ACCESS'" />
  <div class="min-h-dvh bg-[#F4F7FB] text-[#0B3C6B]">
    <a href="#formulaire-auth" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:p-4">Aller au formulaire</a>
    <header class="border-b border-[#DCE6F0] bg-white">
      <div class="mx-auto flex w-full max-w-[1800px] items-center justify-between gap-4 px-5 py-4 sm:px-8 lg:px-12">
        <a href="/" aria-label="Retour à l'accueil IKOMA ACCESS" class="flex items-center gap-3">
          <img src="/brand/ikoma-access-icon.svg" alt="" width="44" height="44" class="h-11 w-11 shrink-0">
          <span class="text-lg font-extrabold tracking-tight text-[#0B3C6B] sm:text-xl">IKOMA <span class="text-[#F75C03]">ACCESS</span></span>
        </a>
        <a href="/" class="inline-flex min-h-12 items-center rounded-lg border border-[#C9D9EA] px-4 text-sm font-semibold hover:bg-slate-50">Retour au site <span aria-hidden="true" class="ml-2">↗</span></a>
      </div>
    </header>
    <div class="mx-auto grid min-h-[calc(100dvh-82px)] w-full max-w-[1800px] lg:grid-cols-[minmax(0,1.03fr)_minmax(0,0.97fr)]">
      <aside class="relative overflow-hidden bg-[#0B3C6B] px-6 py-9 text-white sm:px-10 lg:flex lg:flex-col lg:justify-center lg:px-16 lg:py-16 xl:px-24">
        <div aria-hidden="true" class="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full border-[66px] border-white/[0.06]"></div>
        <div class="relative max-w-2xl">
          <div class="mb-5 inline-flex rounded-full border border-white/25 bg-white/10 px-4 py-2 text-xs font-bold uppercase tracking-[0.15em] text-[#FFD6B5]">{{ eyebrow }}</div>
          <p class="max-w-2xl text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl xl:text-5xl">Contrôlez les accès.<br><span class="text-[#FFAE71]">Maîtrisez les visites.</span></p>
          <p class="mt-5 max-w-xl text-base leading-relaxed text-[#DCE9F8] sm:text-lg">L'espace de travail sécurisé pour accueillir, autoriser et superviser les mouvements sur votre site.</p>
          <div class="mt-10 hidden grid-cols-1 gap-3 sm:grid lg:grid-cols-2">
            <div class="rounded-xl border border-white/15 bg-white/[0.07] p-4">
              <p class="font-bold">Accueil guidé</p><p class="mt-1 text-sm text-[#DCE9F8]">Des étapes claires, du visiteur au badge.</p>
            </div>
            <div class="rounded-xl border border-white/15 bg-white/[0.07] p-4">
              <p class="font-bold">Pilotage opérationnel</p><p class="mt-1 text-sm text-[#DCE9F8]">Alertes, présences et situations à traiter.</p>
            </div>
          </div>
        </div>
        <p class="relative mt-12 hidden text-sm text-white/60 lg:block">IKOMA GROUP · Sécuriser, Innover et Servir</p>
      </aside>
      <main id="formulaire-auth" class="flex min-w-0 items-center justify-center px-5 py-10 sm:px-10 lg:px-12 lg:py-16 xl:px-20">
        <section class="w-full max-w-[650px] rounded-2xl border border-[#DEE7F2] bg-white p-6 shadow-[0_18px_55px_rgba(11,60,107,.08)] sm:p-9 lg:p-10">
          <slot />
        </section>
      </main>
    </div>
  </div>
</template>
