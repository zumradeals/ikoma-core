<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminIcon from '../components/admin/AdminIcon.vue';
import { wipePilotageLocal } from '../pilotage/evacuation';

defineProps<{ active: string; event: { id: string; name: string } | null; events: { id: string; name: string }[] }>();
const page = usePage<{ auth: { name: string; permissions: string[] } }>();
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const open = ref(false);
const items = [
  { key: 'tableau', label: 'Tableau de bord', icon: 'home', href: '/administration', permission: 'evenements.gerer' },
  { key: 'zones', label: 'Zones', icon: 'map', href: '/administration/zones', permission: 'zones.gerer' },
  { key: 'categories', label: 'Catégories', icon: 'tag', href: '/administration/categories', permission: 'categories.gerer' },
  { key: 'imports', label: 'Liste d’invités', icon: 'list', href: '/administration/imports', permission: 'listes.importer' },
  { key: 'badges', label: 'Badges', icon: 'qr', href: '/administration/badges', permission: 'badges.gerer' },
  { key: 'groupes', label: 'Groupes et cars', icon: 'bus', href: '/administration/groupes', permission: 'groupes.gerer' },
  { key: 'postes', label: 'Tablettes', icon: 'tablet', href: '/administration/postes', permission: 'postes.gerer' },
  { key: 'comptes', label: 'Comptes', icon: 'users', href: '/administration/comptes', permission: 'comptes.gerer' },
  { key: 'profil', label: 'Profil de sécurité', icon: 'shield', href: '/administration/profil-securite', permission: 'securite.gerer' },
  { key: 'journal', label: 'Journal', icon: 'book', href: '/administration/journal', permission: 'journal.consulter' },
];
function change(e: Event) { router.get(window.location.pathname, { event: (e.target as HTMLSelectElement).value }); }
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-ikoma-navy">
    <header class="bg-ikoma-navy px-4 py-3 text-white sm:px-8">
      <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <span class="grid size-11 place-items-center rounded-xl bg-ikoma-orange" aria-hidden="true"><AdminIcon name="qr" :size="26" /></span>
          <p class="text-xl"><strong class="font-extrabold">IKOMA</strong> Accueil</p>
        </div>
        <div class="flex min-w-0 max-w-full flex-wrap items-center gap-3">
          <label v-if="event && events.length > 1" class="flex min-w-0 max-w-full items-center gap-2 text-sm font-medium">Événement
            <select class="min-h-12 min-w-0 max-w-full rounded-xl border-0 bg-white px-3 text-base font-semibold text-ikoma-navy" :value="event.id" data-testid="choix-evenement" @change="change">
              <option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option>
            </select>
          </label>
          <p v-else-if="event" class="min-w-0 break-words text-base font-semibold" data-testid="nom-evenement">{{ event.name }}</p>
          <span class="flex min-w-0 items-center gap-2 text-base"><span class="grid size-10 place-items-center rounded-full bg-white font-extrabold text-ikoma-navy" aria-hidden="true">{{ page.props.auth.name.charAt(0).toUpperCase() }}</span>{{ page.props.auth.name }}</span>
          <Link as="button" method="post" href="/logout" class="inline-flex min-h-12 items-center rounded-xl border-2 border-white px-4 font-semibold" @click="wipePilotageLocal">Se déconnecter</Link>
        </div>
      </div>
    </header>

    <div class="mx-auto flex max-w-7xl flex-wrap items-start gap-6 px-4 py-6 sm:px-8">
      <div class="w-full lg:w-60 lg:flex-none">
        <button type="button" class="flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border-2 border-ikoma-blue bg-white px-4 text-lg font-bold lg:hidden" :aria-expanded="open" aria-controls="menu-admin" data-testid="ouvrir-menu" @click="open = !open">
          <span>Menu</span><AdminIcon name="menu" :size="26" :stroke="2.5" />
        </button>
        <nav id="menu-admin" aria-label="Menu administrateur" class="mt-3 flex-col gap-1 rounded-2xl border border-slate-200 bg-white p-3 lg:mt-0 lg:flex" :class="open ? 'flex' : 'hidden lg:flex'">
          <template v-for="item in items" :key="item.key">
            <Link v-if="can(item.permission)" :href="item.href" :aria-current="active === item.key ? 'page' : undefined" class="flex min-h-14 items-center gap-3 rounded-xl px-4 font-semibold" :class="active === item.key ? 'bg-ikoma-orange font-bold text-white' : 'text-ikoma-navy'" :data-testid="'menu-' + item.key">
              <span :class="active === item.key ? 'text-white' : 'text-ikoma-blue'"><AdminIcon :name="item.icon" /></span>{{ item.label }}
            </Link>
          </template>
        </nav>
      </div>
      <main id="contenu" class="min-w-0 grow basis-[34rem] space-y-6"><slot /></main>
    </div>
  </div>
</template>
