<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const steps = ['Recherche', 'Fiche', 'Identité et photo', 'Décision', 'Badge', 'Remise', 'Entrée'];
const strengths = [
  { icon: '⏱', title: 'Moins d’une minute', text: 'Du nom du visiteur au badge remis : sept étapes guidées, un seul geste par écran.' },
  { icon: '⚡', title: 'Sans réseau, sans interruption', text: 'Le poste continue de travailler hors ligne et se synchronise dès que le réseau revient, sans perte ni doublon.' },
  { icon: '🔒', title: 'Données protégées', text: 'Données personnelles chiffrées sur la tablette, accès par rôle, double authentification et journal non modifiable.' },
];
const spaces = [
  { role: 'Agent d’accueil', text: 'Reçoit les visiteurs, contrôle les badges sur site et enregistre les sorties.', links: [{ label: 'Poste d’accueil', href: '/poste' }, { label: 'Contrôle sur site et sortie', href: '/controle' }] },
  { role: 'Superviseur', text: 'Suit les arrivées en direct, traite les alertes et les cas litigieux, dispose de la liste d’évacuation.', links: [{ label: 'Tableau de bord', href: '/pilotage' }, { label: 'Alertes', href: '/pilotage/alertes' }, { label: 'Liste d’évacuation', href: '/pilotage/evacuation' }] },
  { role: 'Administrateur', text: 'Prépare l’événement : listes de visiteurs, zones, catégories, badges, postes et comptes.', links: [{ label: 'Mon espace', href: '/espace' }, { label: 'Importer une liste', href: '/administration/imports' }, { label: 'Badges', href: '/administration/badges' }] },
];
</script>
<template>
  <Head title="IKOMA Accueil — vérification rapide des visiteurs" />
  <div class="min-h-screen bg-slate-50 text-ikoma-navy">
    <a href="#contenu" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:p-3">Aller au contenu</a>
    <header class="border-b-4 border-ikoma-orange bg-ikoma-navy text-white">
      <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
        <p class="flex items-center gap-3 text-xl font-bold"><span aria-hidden="true" class="grid h-10 w-10 place-items-center rounded-lg bg-white text-ikoma-navy">▣</span><span>IKOMA <span class="font-normal">Accueil</span></span></p>
        <a href="/login" class="inline-flex min-h-14 items-center rounded-xl bg-white px-5 font-semibold text-ikoma-navy">Se connecter</a>
      </div>
    </header>

    <main id="contenu">
      <section class="bg-ikoma-navy pb-14 pt-10 text-white" aria-labelledby="titre-principal">
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
          <p class="mb-3 font-semibold uppercase tracking-wide text-orange-200">Vérification rapide des visiteurs</p>
          <h1 id="titre-principal" class="max-w-3xl text-4xl font-bold leading-tight sm:text-5xl">Accueillez, contrôlez et suivez vos visiteurs en toute sécurité</h1>
          <p class="mt-5 max-w-2xl text-xl">De l’arrivée à la sortie : recherche du visiteur, vérification d’identité, badge à QR code, contrôle sur site et suivi des présents en direct, tablette ou smartphone en main.</p>
          <div class="mt-8 flex flex-wrap gap-4">
            <a href="/login" class="inline-flex min-h-16 items-center rounded-xl bg-ikoma-orange px-8 text-xl font-bold text-white">Se connecter</a>
            <a href="#fonctions" class="inline-flex min-h-16 items-center rounded-xl border-2 border-white px-8 text-xl font-semibold text-white">Découvrir les fonctions</a>
          </div>
          <p role="status" class="mt-6 flex items-start gap-3 text-orange-100"><span aria-hidden="true">ⓘ</span><span>Après une première visite, cette page et vos outils d’accueil s’ouvrent même sans réseau.</span></p>
        </div>
      </section>

      <section class="mx-auto -mt-8 max-w-5xl px-4 sm:px-6" aria-label="Points forts">
        <ul class="grid gap-4 md:grid-cols-3">
          <li v-for="item in strengths" :key="item.title" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-3xl" aria-hidden="true">{{ item.icon }}</p>
            <h2 class="mt-2 text-heading font-bold">{{ item.title }}</h2>
            <p class="mt-2">{{ item.text }}</p>
          </li>
        </ul>
      </section>

      <section id="fonctions" class="mx-auto max-w-5xl px-4 py-14 sm:px-6" aria-labelledby="titre-parcours">
        <h2 id="titre-parcours" class="text-display font-bold">Un parcours simple en sept étapes</h2>
        <p class="mt-2 max-w-2xl">À l’accueil, l’agent suit le même chemin pour chaque personne ; le bouton suivant est toujours évident et rien n’est possible dans le désordre.</p>
        <ol class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
          <li v-for="(step, i) in steps" :key="step" class="rounded-xl border-2 border-ikoma-navy bg-white p-3 text-center font-semibold"><span class="block text-2xl font-extrabold text-ikoma-blue">{{ i + 1 }}</span>{{ step }}</li>
        </ol>
      </section>

      <section class="bg-white py-14" aria-labelledby="titre-espaces">
        <div class="mx-auto max-w-5xl px-4 sm:px-6">
          <h2 id="titre-espaces" class="text-display font-bold">Votre espace de travail</h2>
          <p class="mt-2 max-w-2xl">Chaque lien vous demande de vous connecter ; vous ne voyez que ce que votre rôle autorise.</p>
          <div class="mt-8 grid gap-5 md:grid-cols-3">
            <article v-for="space in spaces" :key="space.role" class="flex flex-col rounded-2xl border-2 border-slate-200 p-6">
              <h3 class="text-heading font-bold">{{ space.role }}</h3>
              <p class="mt-2 flex-1">{{ space.text }}</p>
              <ul class="mt-4 space-y-2">
                <li v-for="link in space.links" :key="link.href"><a :href="link.href" class="flex min-h-14 items-center justify-between rounded-xl border-2 border-ikoma-blue px-4 font-semibold text-ikoma-navy">{{ link.label }} <span aria-hidden="true">→</span></a></li>
              </ul>
            </article>
          </div>
        </div>
      </section>

      <section class="mx-auto max-w-5xl px-4 py-14 sm:px-6" aria-labelledby="titre-acces">
        <div class="rounded-2xl border-l-8 border-ikoma-orange bg-white p-6 shadow-sm sm:p-8">
          <h2 id="titre-acces" class="text-heading font-bold">Un accès, un compte nominatif</h2>
          <p class="mt-2 max-w-2xl">Vos identifiants vous sont remis par l’administrateur de votre événement. En cas de difficulté de connexion, adressez-vous à lui.</p>
          <a href="/login" class="mt-5 inline-flex min-h-14 items-center rounded-xl bg-ikoma-blue px-8 text-lg font-bold text-white">Se connecter</a>
        </div>
      </section>
    </main>

    <footer class="border-t border-slate-200 bg-white py-6">
      <p class="mx-auto max-w-5xl px-4 sm:px-6">IKOMA GROUP · Vérification rapide des visiteurs</p>
    </footer>
  </div>
</template>
