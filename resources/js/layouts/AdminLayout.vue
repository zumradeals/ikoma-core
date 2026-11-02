<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminIcon from '../components/admin/AdminIcon.vue';
import BrandName from '../components/BrandName.vue';
import BrandLogo from '../components/BrandLogo.vue';
import { wipePilotageLocal } from '../pilotage/evacuation';

type EventRef = { id: string; name: string };
const props = withDefaults(defineProps<{ active: string; event?: EventRef | null; events?: EventRef[] }>(), { event: null, events: () => [] });
const page = usePage<{ auth: { name: string; permissions: string[] }; adminContext?: { event: EventRef } | null; demoLabAvailable?: boolean; f06Active?: boolean; flash?: { status?: string } }>();
const eventRef = computed(() => props.event ?? page.props.adminContext?.event ?? null);
const can = (permission: string) => page.props.auth.permissions.includes(permission);
const open = ref(false);
// Le menu est regroupé par usage : cinq rubriques au lieu de vingt entrées. Seule la rubrique de la page en cours est dépliée.
type Item = { key: string; label: string; icon: string; href: string; permission: string | null };
type Group = { key: string; label: string; items: Item[] };
const visible = (item: Item) => (!item.permission || can(item.permission)) && (item.key !== 'demonstration' || page.props.demoLabAvailable) && (item.key !== 'groupes' || page.props.f06Active);
const groups: Group[] = [
  { key: 'evenement', label: 'Événement', items: [
    { key: 'evenements', label: 'Événements', icon: 'calendar', href: '/administration/evenements', permission: 'evenements.gerer' },
    { key: 'zones', label: 'Zones', icon: 'map', href: '/administration/zones', permission: 'zones.gerer' },
    { key: 'categories', label: 'Catégories', icon: 'tag', href: '/administration/categories', permission: 'categories.gerer' },
    { key: 'imports', label: 'Liste d’invités', icon: 'list', href: '/administration/imports', permission: 'listes.importer' },
    { key: 'groupes', label: 'Groupes et cars', icon: 'bus', href: '/administration/groupes', permission: 'groupes.gerer' },
  ] },
  { key: 'badges', label: 'Badges et cartes', items: [
    { key: 'atelier', label: 'Atelier des badges', icon: 'qr', href: '/administration/atelier', permission: 'badges.gerer' },
    { key: 'badges', label: 'Badges visiteurs', icon: 'qr', href: '/administration/badges', permission: 'badges.gerer' },
    { key: 'titulaires', label: 'Personnes et cartes permanentes', icon: 'users', href: '/administration/titulaires', permission: 'listes.importer' },
    { key: 'modeles-cartes', label: 'Personnalisation des cartes', icon: 'tag', href: '/administration/titulaires/modeles', permission: 'badges.gerer' },
    { key: 'habilitations', label: 'Habilitations et zones', icon: 'shield', href: '/administration/habilitations', permission: 'listes.importer' },
    { key: 'categories-institutionnelles', label: 'Catégories institutionnelles', icon: 'tag', href: '/administration/categories-institutionnelles', permission: 'categories.gerer' },
  ] },
  { key: 'terrain', label: 'Sur le terrain', items: [
    { key: 'postes', label: 'Postes et tablettes', icon: 'monitor', href: '/administration/postes', permission: 'postes.gerer' },
  ] },
  { key: 'reglages', label: 'Réglages', items: [
    { key: 'identite', label: 'Identité', icon: 'settings', href: '/administration/identite', permission: 'comptes.gerer' },
    { key: 'modules', label: 'Modules', icon: 'settings', href: '/administration/modules', permission: 'comptes.gerer' },
    { key: 'comptes', label: 'Comptes', icon: 'users', href: '/administration/comptes', permission: 'comptes.gerer' },
  ] },
  { key: 'suivi', label: 'Suivi et données', items: [
    { key: 'journal', label: 'Journal', icon: 'book', href: '/administration/journal', permission: 'journal.consulter' },
    { key: 'securite', label: 'Sécurité', icon: 'shield', href: '/securite', permission: null },
    { key: 'donnees', label: 'Gestion des données', icon: 'database', href: '/administration/donnees', permission: 'comptes.gerer' },
    { key: 'conservation', label: 'Conservation des données', icon: 'database', href: '/administration/conservation', permission: 'comptes.gerer' },
    { key: 'demonstration', label: 'Démonstration et remise à zéro', icon: 'database', href: '/administration/demonstration', permission: 'comptes.gerer' },
  ] },
];
const home: Item = { key: 'tableau', label: 'Tableau de bord', icon: 'home', href: '/administration', permission: 'evenements.gerer' };
const shownGroups = computed(() => groups.map(g => ({ ...g, items: g.items.filter(visible) })).filter(g => g.items.length > 0));
const showHome = computed(() => visible(home));
// Rubriques dépliées : celle de la page en cours, plus celles que l'utilisateur ouvre lui-même.
const opened = ref<string[]>([]);
const isOpen = (g: Group) => opened.value.includes(g.key) || g.items.some(i => i.key === props.active);
function toggle(g: Group, event: Event) {
  event.preventDefault();
  opened.value = isOpen(g) && !g.items.some(i => i.key === props.active) ? opened.value.filter(k => k !== g.key) : [...opened.value.filter(k => k !== g.key), g.key];
}
function change(e: Event) { router.get('/administration', { event: (e.target as HTMLSelectElement).value }); }
</script>

<template>
  <div class="admin-shell" data-testid="admin-layout">
    <a href="#contenu" class="admin-skip">Aller au contenu</a>
    <header class="admin-header">
      <div class="admin-brand"><span class="admin-logo"><BrandLogo :size="36" /></span><p><BrandName variant="strong" /></p></div>
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
          <Link v-if="showHome" :href="home.href" :aria-current="active === home.key ? 'page' : undefined" class="admin-nav-link" data-testid="menu-tableau" @click="open = false"><AdminIcon :name="home.icon" :size="24" /><span>{{ home.label }}</span></Link>
          <section v-for="group in shownGroups" :key="group.key" class="admin-nav-group" :data-testid="'groupe-' + group.key">
            <button type="button" class="admin-nav-heading" :aria-expanded="isOpen(group)" :data-testid="'rubrique-' + group.key" @click="toggle(group, $event)"><span>{{ group.label }}</span><span aria-hidden="true">{{ isOpen(group) ? '–' : '+' }}</span></button>
            <div v-show="isOpen(group)" class="admin-nav-items">
              <Link v-for="item in group.items" :key="item.key" :href="item.href" :aria-current="active === item.key ? 'page' : undefined" class="admin-nav-link" :data-testid="'menu-' + item.key" @click="open = false"><AdminIcon :name="item.icon" :size="22" /><span>{{ item.label }}</span></Link>
            </div>
          </section>
        </nav>
      </aside>
      <main id="contenu" class="admin-main" tabindex="-1">
        <p v-if="page.props.flash?.status" class="admin-flash" role="status">{{ page.props.flash.status }}</p>
        <slot />
      </main>
    </div>
  </div>
</template>
