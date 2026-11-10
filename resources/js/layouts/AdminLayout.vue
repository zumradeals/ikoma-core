<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminIcon from '../components/admin/AdminIcon.vue';
import BrandLogo from '../components/BrandLogo.vue';
import { wipePilotageLocal } from '../pilotage/evacuation';
import { useInstitution } from '../composables/useInstitution';

const institution = useInstitution();

type EventRef = { id: string; name: string };
const props = withDefaults(defineProps<{ active: string; event?: EventRef | null; events?: EventRef[] }>(), { event: null, events: () => [] });
const page = usePage<{ auth: { name: string; permissions: string[] }; adminContext?: { event: EventRef } | null; demoLabAvailable?: boolean; f06Active?: boolean; flash?: { status?: string } }>();
const eventRef = computed(() => props.event ?? page.props.adminContext?.event ?? null);
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const open = ref(false);
// Sept menus validés, sans accordéon. Chaque menu regroupe des pages qui gardent leur propre route : elles s'affichent en onglets sous le titre du menu
// quand il en compte plusieurs. `match` : pages rattachées à l'entrée (par exemple les onglets de l'espace « Badges et cartes »).
type Item = { key: string; label: string; icon: string; href: string; permission: string | null; match?: string[] };
type Menu = { key: string; label: string; icon: string; items: Item[] };
const visible = (item: Item) => (!item.permission || can(item.permission)) && (item.key !== 'demonstration' || page.props.demoLabAvailable) && (item.key !== 'groupes' || page.props.f06Active);
const menus: Menu[] = [
  { key: 'tableau', label: 'Tableau de bord', icon: 'home', items: [
    { key: 'tableau', label: 'Tableau de bord', icon: 'home', href: '/administration', permission: 'evenements.gerer' },
  ] },
  { key: 'site', label: 'Site et accès', icon: 'pin', items: [
    // Les pages « zones » et « accès des participants » restent atteignables par les sections de Site et accès ; elles n'ajoutent pas de navigation concurrente.
    { key: 'site-acces', label: 'Site et accès', icon: 'pin', href: '/administration/site', permission: 'postes.gerer', match: ['zones', 'acces-zones'] },
  ] },
  { key: 'personnes', label: 'Personnes et habilitations', icon: 'users', items: [
    { key: 'personnes', label: 'Personnes', icon: 'users', href: '/administration/personnes', permission: 'listes.importer', match: ['imports'] },
    { key: 'invites', label: 'Fiches des invités', icon: 'users', href: '/administration/invites', permission: 'listes.importer' },
    { key: 'titulaires', label: 'Personnel permanent', icon: 'qr', href: '/administration/titulaires', permission: 'listes.importer' },
    { key: 'habilitations', label: 'Habilitations par zone', icon: 'shield', href: '/administration/habilitations', permission: 'listes.importer' },
    { key: 'categories-institutionnelles', label: 'Catégories institutionnelles', icon: 'tag', href: '/administration/categories-institutionnelles', permission: 'categories.gerer' },
  ] },
  { key: 'activites', label: 'Activités', icon: 'calendar', items: [
    { key: 'evenements', label: 'Registre des activités', icon: 'calendar', href: '/administration/evenements', permission: 'evenements.gerer' },
    { key: 'categories', label: 'Catégories', icon: 'tag', href: '/administration/categories', permission: 'categories.gerer' },
  ] },
  { key: 'badges-cartes', label: 'Badges et cartes', icon: 'qr', items: [
    { key: 'badges-cartes', label: 'Badges et cartes', icon: 'qr', href: '/administration/badges', permission: 'badges.gerer', match: ['badges', 'modeles', 'modeles-cartes', 'atelier'] },
  ] },
  { key: 'operations', label: 'Opérations', icon: 'monitor', items: [
    { key: 'supervision', label: 'Supervision', icon: 'monitor', href: '/administration/operations', permission: 'pilotage.consulter' },
    { key: 'postes', label: 'Postes et tablettes', icon: 'monitor', href: '/administration/postes', permission: 'postes.gerer' },
    { key: 'groupes', label: 'Groupes et cars', icon: 'bus', href: '/administration/groupes', permission: 'groupes.gerer' },
  ] },
  { key: 'administration', label: 'Administration', icon: 'settings', items: [
    { key: 'identite', label: 'Identité', icon: 'settings', href: '/administration/identite', permission: 'comptes.gerer' },
    { key: 'modules', label: 'Modules', icon: 'settings', href: '/administration/modules', permission: 'comptes.gerer' },
    { key: 'comptes', label: 'Comptes', icon: 'users', href: '/administration/comptes', permission: 'comptes.gerer' },
    { key: 'donnees', label: 'Données et conservation', icon: 'database', href: '/administration/donnees', permission: 'comptes.gerer', match: ['conservation'] },
    { key: 'journal', label: 'Journal', icon: 'book', href: '/administration/journal', permission: 'journal.consulter' },
    { key: 'securite', label: 'Sécurité', icon: 'shield', href: '/securite', permission: null },
    { key: 'demonstration', label: 'Démonstration et remise à zéro', icon: 'database', href: '/administration/demonstration', permission: 'comptes.gerer' },
  ] },
];
const isActive = (item: Item) => item.key === props.active || (item.match ?? []).includes(props.active);
// Un menu n'apparaît que s'il garde au moins une page permise à l'utilisateur ; il mène à sa première page visible.
const shownMenus = computed(() => menus.map(m => ({ ...m, items: m.items.filter(visible) })).filter(m => m.items.length > 0));
const currentMenu = computed(() => shownMenus.value.find(m => m.items.some(isActive)) ?? null);
function change(e: Event) { router.get('/administration', { event: (e.target as HTMLSelectElement).value }); }
</script>

<template>
  <div class="admin-shell" data-testid="admin-layout">
    <a href="#contenu" class="admin-skip">Aller au contenu</a>
    <header class="admin-header">
      <div class="admin-brand"><span class="admin-logo"><BrandLogo :size="36" /></span><p class="admin-brand-name"><span><strong>IKOMA</strong> ACCESS</span><small v-if="institution.custom">{{ institution.name }}</small></p></div>
      <div v-if="institution.custom && institution.fullName" class="admin-institution" data-testid="institution-entete"><p class="admin-institution-name">{{ institution.fullName }}</p><p v-if="institution.tagline" class="admin-institution-tagline">{{ institution.tagline }}</p></div>
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
          <Link v-for="m in shownMenus" :key="m.key" :href="m.items[0].href" :aria-current="currentMenu?.key === m.key ? 'page' : undefined" class="admin-nav-link" :data-testid="'menu-' + m.key" @click="open = false"><AdminIcon :name="m.icon" :size="24" /><span>{{ m.label }}</span><AdminIcon name="chevron" :size="16" class="admin-nav-chevron" /></Link>
        </nav>
        <p v-if="institution.custom" class="admin-sidebar-foot" data-testid="pied-menu"><BrandLogo :size="44" /><strong>{{ institution.name }}</strong></p>
      </aside>
      <main id="contenu" class="admin-main" tabindex="-1">
        <nav v-if="currentMenu && currentMenu.items.length > 1" class="admin-tabs" :aria-label="currentMenu.label" data-testid="sous-menus">
          <Link v-for="item in currentMenu.items" :key="item.key" :href="item.href" :aria-current="isActive(item) ? 'page' : undefined" class="admin-tab" :data-testid="'sous-menu-' + item.key">{{ item.label }}</Link>
        </nav>
        <p v-if="page.props.flash?.status" class="admin-flash" role="status">{{ page.props.flash.status }}</p>
        <slot />
      </main>
    </div>
  </div>
</template>
