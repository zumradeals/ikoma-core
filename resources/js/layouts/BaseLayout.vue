<template>
  <div class="min-h-screen">
    <header class="border-b-4 border-ikoma-orange bg-ikoma-navy px-6 py-5 text-white"><div class="mx-auto flex max-w-4xl items-center gap-3 font-bold"><span class="grid size-10 shrink-0 place-items-center rounded-lg bg-white"><img src="/brand/ikoma-access-icon.svg" alt="" width="34" height="34"></span><span>IKOMA ACCESS</span></div></header>
    <main id="contenu" class="mx-auto max-w-4xl px-4 py-10 sm:px-6"><slot /></main>
    <footer class="mx-auto max-w-4xl px-6 pb-8 text-body">IKOMA GROUP · Vérification rapide des visiteurs</footer>
  </div>
</template>
