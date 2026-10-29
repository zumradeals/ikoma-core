<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const features = [
  { icon: 'person', title: 'Accueil des visiteurs', text: 'Recherche, vérification, validation et remise des badges dans un parcours en sept étapes.' },
  { icon: 'shield', title: 'Accès et zones', text: 'Gestion des zones autorisées, catégories de visiteurs et contrôles des passages.' },
  { icon: 'activity', title: 'Pilotage en temps réel', text: 'Suivi des arrivées, des présences, des alertes et des situations à décider.' },
  { icon: 'group', title: 'Groupes et cars', text: 'Appel, encadreurs, suivi des effectifs, badges et arrivées des groupes.' },
  { icon: 'offline', title: 'Continuité hors réseau', text: 'Parcours terrain prévus pour continuer sur tablette puis se synchroniser au retour du réseau.' },
  { icon: 'list', title: 'Évacuation et journal', text: 'Liste des personnes présentes, export et traçabilité des actions selon les habilitations.' },
];
const steps = [
  { num: '01', title: 'Recherche' }, { num: '02', title: 'Fiche' }, { num: '03', title: 'Identité et photo' },
  { num: '04', title: 'Décision' }, { num: '05', title: 'Badge' }, { num: '06', title: 'Remise' }, { num: '07', title: 'Entrée' },
];
const roles = [
  { tag: 'TERRAIN', title: 'Agent d’accueil', text: 'Recevoir, identifier, remettre les badges et enregistrer les mouvements.' },
  { tag: 'OPÉRATIONS', title: 'Superviseur', text: 'Surveiller les flux, traiter les alertes et préparer les évacuations.' },
  { tag: 'ORGANISATION', title: 'Administrateur', text: 'Organiser les événements, les accès, les utilisateurs, les zones et les postes.' },
];
</script>

<template>
  <Head title="IKOMA ACCESS — Contrôlez les accès. Maîtrisez les visites.">
    <meta name="description" content="IKOMA ACCESS, plateforme professionnelle de gestion des visiteurs, des badges, des accès et de la supervision opérationnelle.">
  </Head>
  <div class="min-h-screen bg-[#F4F7FB] text-[#0B3C6B]">
    <a href="#contenu" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-white focus:p-4">Aller au contenu</a>

    <header class="sticky top-0 z-40 border-b border-[#DDE8F3] bg-white/95 shadow-sm backdrop-blur">
      <div class="mx-auto flex min-h-[82px] w-full max-w-[1800px] items-center justify-between gap-4 px-5 sm:px-8 lg:px-12">
        <a href="/" class="flex shrink-0 items-center gap-3" aria-label="IKOMA ACCESS — accueil">
          <img :src="'/brand/ikoma-access-icon.svg'" alt="" width="46" height="46" class="h-11 w-11">
          <span class="text-lg font-extrabold tracking-tight sm:text-xl">IKOMA <span class="text-[#F75C03]">ACCESS</span></span>
        </a>
        <nav aria-label="Navigation principale" class="hidden items-center gap-7 text-sm font-semibold text-[#345B82] lg:flex">
          <a href="#solutions" class="hover:text-[#F75C03]">Fonctionnalités</a>
          <a href="#fonctionnement" class="hover:text-[#F75C03]">Fonctionnement</a>
          <a href="#espaces" class="hover:text-[#F75C03]">Espaces de travail</a>
          <a href="#contact" class="hover:text-[#F75C03]">Contact</a>
        </nav>
        <a href="/login" class="inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-lg bg-[#0B3C6B] px-4 text-sm font-bold text-white transition hover:bg-[#0054A0] sm:px-6">Se connecter <span aria-hidden="true">↗</span></a>
      </div>
    </header>

    <main id="contenu">
      <section class="relative overflow-hidden bg-[#0B3C6B] text-white" aria-labelledby="titre-principal">
        <div aria-hidden="true" class="pointer-events-none absolute -right-52 -top-60 h-[700px] w-[700px] rounded-full border-[120px] border-white/[0.045]"></div>
        <div class="relative mx-auto grid w-full max-w-[1800px] items-center gap-12 px-5 py-14 sm:px-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,.9fr)] lg:px-12 lg:py-24 xl:gap-20">
          <div class="max-w-3xl">
            <div class="mb-6 inline-flex items-center gap-3 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-xs font-extrabold uppercase tracking-[.16em] text-[#FFD7BA]">
              <span class="h-2.5 w-2.5 rounded-full bg-[#F75C03]" aria-hidden="true"></span> Plateforme de gestion des visiteurs & des accès
            </div>
            <h1 id="titre-principal" class="text-[clamp(2.35rem,4vw,4.8rem)] font-extrabold leading-[1.1] tracking-tight">
              Contrôlez les accès.<br><span class="text-[#FF9C59]">Maîtrisez les visites.</span>
            </h1>
            <p class="mt-7 max-w-2xl text-base leading-relaxed text-[#DFEBF8] sm:text-xl">
              Une seule plateforme pour accueillir vos visiteurs, contrôler les autorisations, suivre les présences et superviser votre site — de l’arrivée à la sortie.
            </p>
            <div class="mt-9 flex flex-wrap gap-4">
              <a href="mailto:ikomagroup2@gmail.com?subject=Demande%20de%20pr%C3%A9sentation%20IKOMA%20ACCESS" class="inline-flex min-h-14 items-center justify-center gap-3 rounded-lg bg-[#F75C03] px-7 font-extrabold text-white transition hover:bg-[#DE5100]">Demander une présentation <span aria-hidden="true">↗</span></a>
              <a href="#solutions" class="inline-flex min-h-14 items-center justify-center rounded-lg border border-white/70 px-7 font-bold text-white transition hover:bg-white/10">Découvrir les fonctionnalités</a>
            </div>
            <div class="mt-10 flex flex-wrap gap-x-6 gap-y-3 text-sm text-white/80">
              <span class="flex items-center gap-2"><span class="text-[#FF9C59]" aria-hidden="true">✓</span> Accueil et badges QR</span>
              <span class="flex items-center gap-2"><span class="text-[#FF9C59]" aria-hidden="true">✓</span> Contrôle par zones</span>
              <span class="flex items-center gap-2"><span class="text-[#FF9C59]" aria-hidden="true">✓</span> Supervision et évacuation</span>
            </div>
          </div>

          <div class="relative min-w-0" aria-label="Aperçu illustratif des trois espaces IKOMA ACCESS">
            <div class="absolute -bottom-6 left-7 right-7 top-8 rounded-3xl bg-[#F75C03]/20 blur-3xl" aria-hidden="true"></div>
            <div class="relative overflow-hidden rounded-2xl border border-white/20 bg-[#F7FAFE] text-[#0B3C6B] shadow-[0_30px_85px_rgba(0,13,32,.35)]">
              <div class="flex min-h-14 items-center justify-between gap-3 border-b border-[#DDE7F3] bg-white px-5">
                <div class="flex items-center gap-2"><img :src="'/brand/ikoma-access-icon.svg'" alt="" class="h-8 w-8"><span class="text-sm font-extrabold">IKOMA <span class="text-[#F75C03]">ACCESS</span></span></div>
                <span class="rounded-full bg-[#E7F5ED] px-3 py-1 text-xs font-bold text-[#14733F]">Plateforme unifiée</span>
              </div>
              <div class="grid grid-cols-[84px_minmax(0,1fr)] sm:grid-cols-[132px_minmax(0,1fr)]">
                <div class="space-y-4 border-r border-[#E0EAF5] bg-white px-2 py-5 sm:px-3">
                  <div class="rounded-lg bg-[#FFEBDE] px-2 py-3 text-center text-[10px] font-bold text-[#A83D04] sm:text-xs">Vue globale</div>
                  <div class="rounded-lg bg-[#F3F7FC] px-2 py-3 text-center text-[10px] font-semibold sm:text-xs">Accueil</div>
                  <div class="rounded-lg bg-[#F3F7FC] px-2 py-3 text-center text-[10px] font-semibold sm:text-xs">Accès</div>
                  <div class="rounded-lg bg-[#F3F7FC] px-2 py-3 text-center text-[10px] font-semibold sm:text-xs">Pilotage</div>
                </div>
                <div class="min-w-0 space-y-5 p-4 sm:p-6">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <div><p class="text-xs text-[#617A97]">Espace de travail</p><p class="mt-1 text-lg font-extrabold sm:text-xl">Vue d'ensemble</p></div>
                    <div class="rounded-lg bg-[#E7F2FF] px-3 py-2 text-xs font-semibold text-[#07529D]">Multi-rôles</div>
                  </div>
                  <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl border border-[#DFEAF5] bg-white p-3 sm:p-4"><div class="mb-3 h-8 w-8 rounded-lg bg-[#E7F3FF] p-2 text-[#0066B3]"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5v14"/></svg></div><p class="text-sm font-bold">Accueil</p><p class="mt-1 text-xs text-[#617A97]">Visiteurs & badges</p></div>
                    <div class="rounded-xl border border-[#DFEAF5] bg-white p-3 sm:p-4"><div class="mb-3 h-8 w-8 rounded-lg bg-[#E9F7EF] p-2 text-[#15814A]"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m12 2 8 4v6c0 5-3 8-8 10-5-2-8-5-8-10V6l8-4Z"/></svg></div><p class="text-sm font-bold">Contrôle</p><p class="mt-1 text-xs text-[#617A97]">Zones & passages</p></div>
                    <div class="rounded-xl border border-[#DFEAF5] bg-white p-3 sm:p-4"><div class="mb-3 h-8 w-8 rounded-lg bg-[#FFF0E4] p-2 text-[#D65407]"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12h4l3-7 4 14 3-7h4"/></svg></div><p class="text-sm font-bold">Supervision</p><p class="mt-1 text-xs text-[#617A97]">Alertes & suivi</p></div>
                    <div class="rounded-xl border border-[#DFEAF5] bg-white p-3 sm:p-4"><div class="mb-3 h-8 w-8 rounded-lg bg-[#EEEAFE] p-2 text-[#6955B1]"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></div><p class="text-sm font-bold">Traçabilité</p><p class="mt-1 text-xs text-[#617A97]">Journal & présence</p></div>
                  </div>
                  <p class="text-right text-[11px] text-[#7187A0]">Aperçu illustratif — sans données réelles</p>
                </div>
              </div>
            </div>
            <div class="absolute -bottom-6 -left-5 hidden rounded-xl border border-[#DCE8F4] bg-white px-5 py-4 text-[#0B3C6B] shadow-xl sm:block"><p class="text-xs text-[#617A97]">Une interface pour chaque rôle</p><p class="mt-1 font-extrabold">Administration · Terrain · Pilotage</p></div>
          </div>
        </div>
      </section>

      <section class="border-b border-[#DFE8F2] bg-white" aria-label="Domaines couverts">
        <div class="mx-auto grid w-full max-w-[1800px] grid-cols-2 gap-4 px-5 py-6 text-center text-xs font-bold text-[#496786] sm:grid-cols-4 sm:px-8 sm:text-sm lg:px-12">
          <span>IDENTIFICATION</span><span>CONTRÔLE D'ACCÈS</span><span>SUPERVISION</span><span>TRAÇABILITÉ</span>
        </div>
      </section>

      <section id="solutions" class="scroll-mt-24 py-16 lg:py-24">
        <div class="mx-auto w-full max-w-[1800px] px-5 sm:px-8 lg:px-12">
          <p class="text-sm font-extrabold uppercase tracking-[.16em] text-[#F75C03]">Une solution complète</p>
          <div class="mt-3 flex flex-wrap items-end justify-between gap-6">
            <h2 class="max-w-3xl text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl xl:text-5xl">Du premier accueil à la dernière sortie.</h2>
            <p class="max-w-xl text-base leading-relaxed text-[#506D94]">Des outils complémentaires, pensés pour les responsables de site comme pour les équipes sur le terrain.</p>
          </div>
          <div class="mt-10 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="item in features" :key="item.title" class="group rounded-2xl border border-[#DFE8F3] bg-white p-7 shadow-[0_6px_20px_rgba(11,60,107,.035)] transition hover:-translate-y-1 hover:shadow-lg">
              <div class="mb-6 flex h-12 w-12 items-center justify-center rounded-xl bg-[#E9F3FF] text-[#0056A5]">
                <svg v-if="item.icon === 'person'" width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/></svg>
                <svg v-else-if="item.icon === 'shield'" width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 2 20 6v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4Z"/><path d="m9 12 2 2 4-4"/></svg>
                <svg v-else-if="item.icon === 'activity'" width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 12h5l3-8 4 16 3-8h3"/></svg>
                <svg v-else-if="item.icon === 'group'" width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M17 6a3 3 0 0 1 0 6m2 2a6 6 0 0 1 3 5v2"/></svg>
                <svg v-else-if="item.icon === 'offline'" width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 9a14 14 0 0 1 18 0M6 13a10 10 0 0 1 8-2M8.5 17a5 5 0 0 1 5 0M3 3l18 18"/></svg>
                <svg v-else width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 4h12M8 10h12M8 16h12M4 4h.01M4 10h.01M4 16h.01"/></svg>
              </div>
              <h3 class="text-xl font-extrabold">{{ item.title }}</h3>
              <p class="mt-3 leading-relaxed text-[#506D94]">{{ item.text }}</p>
            </article>
          </div>
        </div>
      </section>

      <section id="fonctionnement" class="scroll-mt-24 bg-white py-16 lg:py-24">
        <div class="mx-auto w-full max-w-[1800px] px-5 sm:px-8 lg:px-12">
          <div class="grid gap-10 lg:grid-cols-[minmax(0,.75fr)_minmax(0,1.25fr)] lg:gap-16">
            <div>
              <p class="text-sm font-extrabold uppercase tracking-[.16em] text-[#F75C03]">Une expérience guidée</p>
              <h2 class="mt-3 text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl">Sept étapes. Un parcours maîtrisé.</h2>
              <p class="mt-5 max-w-xl leading-relaxed text-[#506D94]">À l’accueil, chaque personne suit un chemin clair. Les contrôles applicables restent soumis aux règles et aux décisions configurées par l’organisation.</p>
            </div>
            <ol class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
              <li v-for="step in steps" :key="step.num" class="rounded-xl border border-[#DFE8F3] bg-[#F7FAFD] p-5">
                <span class="text-2xl font-black text-[#F75C03]">{{ step.num }}</span><p class="mt-3 font-bold">{{ step.title }}</p>
              </li>
            </ol>
          </div>
          <p class="mt-6 text-sm text-[#506D94]">La vérification de la pièce d'identité est configurable par l'administrateur, selon les besoins de l'événement.</p>
        </div>
      </section>

      <section id="espaces" class="scroll-mt-24 py-16 lg:py-24">
        <div class="mx-auto w-full max-w-[1800px] px-5 sm:px-8 lg:px-12">
          <p class="text-sm font-extrabold uppercase tracking-[.16em] text-[#F75C03]">Trois espaces complémentaires</p>
          <h2 class="mt-3 max-w-3xl text-3xl font-extrabold tracking-tight sm:text-4xl">À chaque équipe, son espace de travail.</h2>
          <div class="mt-10 grid gap-5 lg:grid-cols-3">
            <article v-for="role in roles" :key="role.title" class="flex flex-col rounded-2xl border border-[#DFE8F3] bg-white p-7">
              <span class="mb-8 inline-flex self-start rounded-md bg-[#EAF3FF] px-3 py-2 text-xs font-extrabold tracking-wider text-[#0059AC]">{{ role.tag }}</span>
              <h3 class="text-2xl font-extrabold">{{ role.title }}</h3>
              <p class="mt-3 flex-1 leading-relaxed text-[#506D94]">{{ role.text }}</p>
              <div class="mt-8 border-t border-[#E0EAF5] pt-5"><span class="text-sm font-bold text-[#F75C03]">Interface dédiée et sécurisée</span></div>
            </article>
          </div>
        </div>
      </section>

      <section id="contact" class="scroll-mt-24 bg-[#0B3C6B] py-14 text-white lg:py-20">
        <div class="mx-auto flex w-full max-w-[1800px] flex-col items-start justify-between gap-8 px-5 sm:px-8 lg:flex-row lg:items-center lg:px-12">
          <div><p class="text-sm font-bold uppercase tracking-[.16em] text-[#FFB387]">IKOMA ACCESS par IKOMA GROUP</p><h2 class="mt-3 max-w-3xl text-3xl font-extrabold sm:text-4xl">Prêt à moderniser l’accueil et le contrôle de votre site ?</h2><p class="mt-4 max-w-2xl text-[#DCE9F8]">Échangeons sur votre organisation, vos zones, vos visiteurs et votre environnement de travail.</p></div>
          <a href="mailto:ikomagroup2@gmail.com?subject=Demande%20de%20pr%C3%A9sentation%20IKOMA%20ACCESS" class="inline-flex min-h-14 shrink-0 items-center justify-center rounded-lg bg-[#F75C03] px-7 font-extrabold text-white hover:bg-[#DE5100]">Demander une présentation <span aria-hidden="true" class="ml-3">→</span></a>
        </div>
      </section>
    </main>

    <footer class="bg-[#082B50] text-white">
      <div class="mx-auto flex w-full max-w-[1800px] flex-col justify-between gap-5 px-5 py-8 text-sm sm:px-8 lg:flex-row lg:items-center lg:px-12">
        <div class="flex items-center gap-3"><img :src="'/brand/ikoma-access-icon.svg'" alt="" class="h-9 w-9"><p><strong>IKOMA ACCESS</strong><span class="block text-[#BDCEE2]">Contrôlez les accès. Maîtrisez les visites.</span></p></div>
        <p class="text-[#BDCEE2]">Une solution IKOMA GROUP · Cocody Angré Soleil 3, Abidjan</p>
        <a href="/login" class="font-semibold underline underline-offset-4">Connexion à l'application</a>
      </div>
    </footer>
  </div>
</template>
