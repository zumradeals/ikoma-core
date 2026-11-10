<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AdminIcon from '../components/admin/AdminIcon.vue';
import BrandName from '../components/BrandName.vue';
import BrandLogo from '../components/BrandLogo.vue';
const props = withDefaults(defineProps<{ active: 'home' | 'poste' | 'groupe' | 'controle' | 'securite'; agent?: string; online?: boolean; permissions?: string[]; capabilities?: string[] | null }>(), { agent: '', online: true, permissions: undefined, capabilities: null });
const page = usePage<{ auth?: { name: string; permissions: string[] } | null }>();
const open = ref(false);
const rights = computed(() => props.permissions ?? page.props?.auth?.permissions ?? []);
const items = [
  { key: 'home', label: 'Mon espace', icon: 'home', href: '/espace', permission: null },
  { key: 'poste', label: 'Poste d’accueil', icon: 'users', href: '/poste', permission: 'visiteurs.rechercher' },
  { key: 'groupe', label: 'Groupes et cars', icon: 'bus', href: '/groupe', permission: 'groupes.appel_nominal' },
  { key: 'controle', label: 'Contrôle sur site', icon: 'qr', href: '/controle', permission: 'badges.controler' },
  { key: 'securite', label: 'Sécurité', icon: 'shield', href: '/securite', permission: null },
] as const;
// Capacités du poste (S02) : un poste qui n'a pas la capacité « accueil » (la soute, par exemple) n'affiche pas l'accueil ; le contrôle exige « scan » ou « sortie ».
const byCapability = (key: string) => props.capabilities === null || props.capabilities === undefined || props.active === key || (key === 'poste' ? props.capabilities.includes('accueil') : key === 'controle' ? props.capabilities.some(c => c === 'scan' || c === 'sortie') : true);
const allowed = computed(() => items.filter(item => props.active === item.key || ((item.permission ? rights.value.includes(item.permission) : !!page.props?.auth) && byCapability(item.key))));
</script>
<template>
  <div class="agent-shell" data-testid="agent-layout" :data-active="active">
    <a href="#contenu" class="agent-skip">Aller au contenu</a>
    <header class="agent-header">
      <div class="agent-brand"><span class="agent-logo"><BrandLogo :size="36" /></span><p><BrandName variant="strong" /><span>Agent d’accueil</span></p></div>
      <div class="agent-header-context"><slot name="header-context" /></div>
      <div class="agent-session"><span v-if="active === 'home' || active === 'securite'" class="agent-network" :data-online="online"><span aria-hidden="true">{{ online ? '●' : '✕' }}</span> {{ online ? 'En ligne' : 'Hors ligne' }}</span><span v-if="agent" class="agent-user"><span class="agent-avatar" aria-hidden="true">{{ agent.charAt(0).toUpperCase() }}</span><span>{{ agent }}</span></span><button type="button" class="agent-menu-toggle" :aria-expanded="open" aria-controls="menu-agent" data-testid="menu-agent-toggle" @click="open = !open"><AdminIcon name="menu" /><span>Menu</span></button></div>
    </header>
    <div class="agent-frame">
      <aside class="agent-sidebar"><div class="agent-sidebar-stick"><nav id="menu-agent" aria-label="Navigation de l’agent" :class="{ 'is-open': open }" @keydown.esc="open = false"><p class="agent-nav-caption">Accueil et contrôle</p><template v-for="item in allowed" :key="item.key"><a v-if="online" :href="item.href" :aria-current="active === item.key ? 'page' : undefined" class="agent-nav-link" :data-testid="'agent-nav-' + item.key"><AdminIcon :name="item.icon" :size="24" />{{ item.label }}</a><span v-else class="agent-nav-link" aria-disabled="true" :aria-current="active === item.key ? 'page' : undefined"><AdminIcon :name="item.icon" :size="24" />{{ item.label }}</span></template><p v-if="!online" class="agent-nav-help">Connexion indisponible : poursuivez dans l’écran ouvert. Les autres espaces demandent une connexion.</p></nav><slot name="sidebar-extra" /></div></aside>
      <main id="contenu" class="agent-main" tabindex="-1"><slot name="status" /><slot /></main>
    </div>
  </div>
</template>
