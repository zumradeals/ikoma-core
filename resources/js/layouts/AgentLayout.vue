<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AdminIcon from '../components/admin/AdminIcon.vue';
const props = withDefaults(defineProps<{ active: 'home' | 'poste' | 'groupe' | 'controle' | 'securite'; agent?: string; online?: boolean; permissions?: string[] }>(), { agent: '', online: true, permissions: undefined });
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
const allowed = computed(() => items.filter(item => props.active === item.key || (item.permission ? rights.value.includes(item.permission) : !!page.props?.auth)));
</script>
<template>
  <div class="agent-shell" data-testid="agent-layout" :data-active="active">
    <a href="#contenu" class="agent-skip">Aller au contenu</a>
    <header class="agent-header">
      <div class="agent-brand"><span class="agent-logo"><img src="/brand/ikoma-access-icon.svg" alt="" width="36" height="36"></span><p><strong>IKOMA</strong> ACCESS<span>Agent d’accueil</span></p></div>
      <div class="agent-header-context"><slot name="header-context" /></div>
      <div class="agent-session"><span v-if="active === 'home' || active === 'securite'" class="agent-network" :data-online="online"><span aria-hidden="true">{{ online ? '●' : '✕' }}</span> {{ online ? 'En ligne' : 'Hors ligne' }}</span><span v-if="agent" class="agent-user"><span class="agent-avatar" aria-hidden="true">{{ agent.charAt(0).toUpperCase() }}</span><span>{{ agent }}</span></span><button type="button" class="agent-menu-toggle" :aria-expanded="open" aria-controls="menu-agent" data-testid="menu-agent-toggle" @click="open = !open"><AdminIcon name="menu" /><span>Menu</span></button></div>
    </header>
    <div class="agent-frame">
      <aside class="agent-sidebar"><nav id="menu-agent" aria-label="Navigation de l’agent" :class="{ 'is-open': open }" @keydown.esc="open = false"><p class="agent-nav-caption">Accueil et contrôle</p><template v-for="item in allowed" :key="item.key"><a v-if="online" :href="item.href" :aria-current="active === item.key ? 'page' : undefined" class="agent-nav-link" :data-testid="'agent-nav-' + item.key"><AdminIcon :name="item.icon" :size="24" />{{ item.label }}</a><span v-else class="agent-nav-link" aria-disabled="true" :aria-current="active === item.key ? 'page' : undefined"><AdminIcon :name="item.icon" :size="24" />{{ item.label }}</span></template><p v-if="!online" class="agent-nav-help">Connexion indisponible : poursuivez dans l’écran ouvert. Les autres espaces demandent une connexion.</p></nav></aside>
      <main id="contenu" class="agent-main" tabindex="-1"><slot name="status" /><slot /></main>
    </div>
  </div>
</template>
