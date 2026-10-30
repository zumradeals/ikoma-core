<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminIcon from '../components/admin/AdminIcon.vue';
import { wipePilotageLocal } from '../pilotage/evacuation';

type EventRef = { id: string; name: string };
const props = withDefaults(defineProps<{ active: string; event?: EventRef | null; events?: EventRef[] }>(), { event: null, events: () => [] });
const page = usePage<{ auth: { name: string; permissions: string[] }; adminContext?: { event: EventRef } | null; demoLabAvailable?: boolean; flash?: { status?: string } }>();
const eventRef = computed(() => props.event ?? page.props.adminContext?.event ?? null);
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const open = ref(false);
const items = [
  { key: 'tableau', label: 'Tableau de bord', icon: 'home', href: '/administration', permission: 'evenements.gerer' },
  { key: 'evenements', label: 'Événements', icon: 'calendar', href: '/administration/evenements', permission: 'evenements.gerer' },
  { key: 'zones', label: 'Zones', icon: 'map', href: '/administration/zones', permission: 'zones.gerer' },
  { key: 'categories', label: 'Catégories', icon: 'tag', href: '/administration/categories', permission: 'categories.gerer' },
  { key: 'imports', label: 'Liste d’invités', icon: 'list', href: '/administration/imports', permission: 'listes.importer' },
  { key: 'badges', label: 'Badges', icon: 'qr', href: '/administration/badges', permission: 'badges.gerer' },
  { key: 'groupes', label: 'Groupes et cars', icon: 'bus', href: '/administration/groupes', permission: 'groupes.gerer' },
  { key: 'postes', label: 'Postes et tablettes', icon: 'monitor', href: '/administration/postes', permission: 'postes.gerer' },
  { key: 'comptes', label: 'Comptes', icon: 'users', href: '/administration/comptes', permission: 'comptes.gerer' },
  { key: 'journal', label: 'Journal', icon: 'book', href: '/administration/journal', permission: 'journal.consulter' },
  { key: 'securite', label: 'Sécurité', icon: 'shield', href: '/securite', permission: null },
  { key: 'demonstration', label: 'Démonstration et remise à zéro', icon: 'database', href: '/administration/demonstration', permission: 'comptes.gerer' },
  { key: 'donnees', label: 'Gestion des données', icon: 'database', href: '/administration/donnees', permission: 'comptes.gerer' },
  { key: 'conservation', label: 'Conservation des données', icon: 'database', href: '/administration/conservation', permission: 'comptes.gerer' },
];
function change(e: Event) { router.get('/administration', { event: (e.target as HTMLSelectElement).value }); }
</script>

<template>
  <div class="admin-shell" data-testid="admin-layout">
    <a href="#contenu" class="admin-skip">Aller au contenu</a>
    <header class="admin-header">
      <div class="admin-brand"><span class="admin-logo"><img :src="'/brand/ikoma-access-icon.svg'" alt="" width="36" height="36"></span><p><strong>IKOMA</strong> ACCESS</p></div>
      <div class="admin-event">
        <label v-if="eventRef && events.length > 1 && active === 'tableau'" class="admin-event-select"><span class="sr-only">Événement</span>
          <select :value="eventRef.id" data-testid="choix-evenement" @change="change"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </label>
        <p v-else-if="eventRef" data-testid="nom-evenement"><span v-if="active !== 'tableau'" class="admin-event-caption">Événement de référence</span>{{ eventRef.name }}</p>
        <p v-else>Aucun événement enregistré</p>
      </div>
      <div class="admin-session">
<span class="admin-user"><span class="admin-avatar" aria-hidden="true">{{ page.props.auth.name.charAt(0).toUpperCase() }}</span><span>{{ page.props.auth.name }}</span></span>
        <Link as="button" method="post" href="/logout" class="admin-logout" @click="wipePilotageLocal">Se déconnecter</Link>
      </div>
    </header>
    <div class="admin-frame">
      <aside class="admin-sidebar">
        <button type="button" class="admin-menu-toggle" :aria-expanded="open" aria-controls="menu-admin" data-testid="ouvrir-menu" @click="open = !open"><span>Menu administrateur</span><AdminIcon name="menu" /></button>
        <nav id="menu-admin" aria-label="Menu administrateur" :class="{ 'is-open': open }">
          <template v-for="item in items" :key="item.key"><Link v-if="(!item.permission || can(item.permission)) && (item.key !== 'demonstration' || page.props.demoLabAvailable)" :href="item.href" :aria-current="active === item.key ? 'page' : undefined" class="admin-nav-link" :data-testid="'menu-' + item.key" @click="open = false"><AdminIcon :name="item.icon" :size="24" /><span>{{ item.label }}</span></Link></template>
        </nav>
      </aside>
      <main id="contenu" class="admin-main" tabindex="-1">
        <p v-if="page.props.flash?.status" class="admin-flash" role="status">{{ page.props.flash.status }}</p>
        <slot />
      </main>
    </div>
  </div>
</template>
