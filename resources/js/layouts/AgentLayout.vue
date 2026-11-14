<script setup lang="ts">
import { computed, inject, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminIcon from '../components/admin/AdminIcon.vue';
import BrandName from '../components/BrandName.vue';
import BrandLogo from '../components/BrandLogo.vue';
import { useInstitution } from '../composables/useInstitution';
const props = withDefaults(defineProps<{ active: 'home' | 'poste' | 'groupe' | 'controle' | 'securite'; agent?: string; online?: boolean; pending?: number | null; permissions?: string[]; capabilities?: string[] | null; syncLabel?: string | null; notifications?: number | null; siteName?: string | null }>(), { agent: '', online: true, pending: null, permissions: undefined, capabilities: null });
const page = usePage<{ auth?: { name: string; permissions: string[] } | null }>();
const open = ref(false);
// Fusion (faisabilité) : dans l'écran déverrouillé du poste, les trois vues se changent par état Vue, sans visite de page ni réseau.
const navigate = inject<((view: 'poste' | 'controle' | 'groupe', mode?: string | null) => void) | null>('agentNavigate', null);
const inGate = (key: string) => navigate !== null && ['poste', 'groupe', 'controle'].includes(key);
const institution = useInstitution();
// État de connexion HONNÊTE : « Tout est envoyé » seulement quand la file est connue et vide ; sinon le nombre d'opérations qui attendent.
const netChip = computed(() => {
  const n = props.pending ?? null;
  const waiting = n !== null && n > 0 ? ` · ${n} à envoyer` : '';
  if (!props.online) return { ok: false, icon: '✕', main: 'Hors ligne', detail: waiting, clean: false };
  if (n !== null && n > 0) return { ok: false, icon: '◷', main: 'En ligne', detail: waiting, clean: false };
  return { ok: true, icon: '●', main: 'En ligne', detail: n === 0 ? ' · Tout est envoyé' : '', clean: true };
});
const rights = computed(() => props.permissions ?? page.props?.auth?.permissions ?? []);
const items = [
  { key: 'home', label: 'Mon espace', short: 'Espace', icon: 'home', href: '/espace', permission: null },
  { key: 'poste', label: 'Mon poste', short: 'Mon poste', icon: 'users', href: '/poste', permission: 'visiteurs.rechercher' },
  { key: 'groupe', label: 'Groupes et cars', short: 'Cars', icon: 'bus', href: '/groupe', permission: 'groupes.appel_nominal' },
  { key: 'controle', label: 'Contrôle sur site', short: 'Contrôle', icon: 'qr', href: '/controle', permission: 'badges.controler' },
  { key: 'securite', label: 'Sécurité', short: 'Sécurité', icon: 'shield', href: '/securite', permission: null },
] as const;
// Capacités du poste (S02) : un poste qui n'a pas la capacité « accueil » (la soute, par exemple) n'affiche pas l'accueil ; le contrôle exige « scan » ou « sortie ».
const byCapability = (key: string) => props.capabilities === null || props.capabilities === undefined || props.active === key || (key === 'poste' ? props.capabilities.includes('accueil') : key === 'controle' ? props.capabilities.some(c => c === 'scan' || c === 'sortie') : true);
const allowed = computed(() => items.filter(item => props.active === item.key || ((item.permission ? rights.value.includes(item.permission) : !!page.props?.auth) && byCapability(item.key))));
</script>
<template>
  <div class="agent-shell" data-testid="agent-layout" :data-active="active">
    <a href="#contenu" class="agent-skip">Aller au contenu</a>
    <header class="agent-header">
      <div class="agent-brand"><span class="agent-logo" :class="{ 'is-plain': institution.custom && !!institution.logoUrl }"><BrandLogo :size="36" /></span><p><BrandName variant="strong" /></p><p v-if="institution.custom && institution.fullName !== institution.name" class="agent-fullname" data-testid="nom-complet">{{ institution.fullName }}</p></div>
      <div class="agent-header-context"><slot name="header-context" /></div>
      <div class="agent-session">
        <span class="agent-network" :data-online="online" :data-clean="netChip.ok" data-testid="etat-connexion"><span aria-hidden="true">{{ netChip.icon }}</span> {{ netChip.main }}<span class="agent-network-detail" :class="{ 'is-clean': netChip.clean }">{{ netChip.detail }}</span></span>
        <span v-if="syncLabel" class="agent-sync-chip" data-testid="entete-synchro"><AdminIcon name="refresh" :size="22" /><span><small>Dernière synchronisation</small><strong>{{ syncLabel }}</strong></span></span>
        <a v-if="notifications" href="#conflits" class="agent-bell" :aria-label="notifications + ' opération(s) refusée(s) par le serveur'" data-testid="cloche"><AdminIcon name="bell" :size="26" /><span class="agent-bell-count">{{ notifications }}</span></a>
        <span v-if="agent" class="agent-user"><span class="agent-avatar" aria-hidden="true">{{ agent.charAt(0).toUpperCase() }}</span><span>{{ agent }}<small class="agent-role">Agent d’accueil</small></span></span>
        <button type="button" class="agent-menu-toggle" :aria-expanded="open" aria-controls="menu-agent" data-testid="menu-agent-toggle" @click="open = !open"><AdminIcon name="menu" /><span>Menu</span></button>
      </div>
    </header>
    <div class="agent-frame">
      <aside class="agent-sidebar"><div class="agent-sidebar-stick"><nav id="menu-agent" aria-label="Navigation de l’agent" :class="{ 'is-open': open }" @keydown.esc="open = false"><p class="agent-nav-caption">Accueil et contrôle</p><template v-for="item in allowed" :key="item.key"><button v-if="inGate(item.key)" type="button" class="agent-nav-link w-full text-left" :aria-current="active === item.key ? 'page' : undefined" :data-testid="'agent-nav-' + item.key" @click="navigate!(item.key as 'poste' | 'controle' | 'groupe'); open = false"><AdminIcon :name="item.icon" :size="24" />{{ item.label }}</button><Link v-else-if="online" :href="item.href" :aria-current="active === item.key ? 'page' : undefined" class="agent-nav-link" :data-testid="'agent-nav-' + item.key"><AdminIcon :name="item.icon" :size="24" />{{ item.label }}</Link><span v-else class="agent-nav-link" aria-disabled="true" :aria-current="active === item.key ? 'page' : undefined"><AdminIcon :name="item.icon" :size="24" />{{ item.label }}</span></template><p v-if="!online" class="agent-nav-help">Connexion indisponible : poursuivez dans l’écran ouvert. Les autres espaces demandent une connexion.</p></nav><slot name="sidebar-extra" /></div><div class="agent-sidebar-site" aria-hidden="true"><SiteIllustration :width="150" /><p><strong>{{ institution.name }}</strong><span v-if="siteName">{{ siteName }}</span></p></div></aside>
      <main id="contenu" class="agent-main" tabindex="-1"><slot name="status" /><slot /></main>
    </div>
    <nav class="agent-bottom" aria-label="Navigation rapide de l’agent" data-testid="agent-bottom"><ul><li v-for="item in allowed" :key="'b' + item.key"><button v-if="inGate(item.key)" type="button" :aria-current="active === item.key ? 'page' : undefined" @click="navigate!(item.key as 'poste' | 'controle' | 'groupe')"><AdminIcon :name="item.icon" :size="22" /><span>{{ item.short }}</span></button><Link v-else-if="online" :href="item.href" :aria-current="active === item.key ? 'page' : undefined"><AdminIcon :name="item.icon" :size="22" /><span>{{ item.short }}</span></Link><span v-else class="agent-bottom-off" aria-disabled="true"><AdminIcon :name="item.icon" :size="22" /><span>{{ item.short }}</span></span></li></ul></nav>
  </div>
</template>
