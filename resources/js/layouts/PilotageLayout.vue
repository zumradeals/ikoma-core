<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminIcon from '../components/admin/AdminIcon.vue';
import { useAlertSound } from '../pilotage/useAlertSound';
import { wipePilotageLocal } from '../pilotage/evacuation';
import { pilotageHref } from '../pilotage/navigation';

defineOptions({ inheritAttrs: false });
type EventRef = { id: string; name: string };
type Context = { event: EventRef | null; events: EventRef[]; openAlerts: number; criticalAlerts: number };
const props = withDefaults(defineProps<{ active: 'tableau' | 'alertes' | 'presents' | 'litiges' | 'evacuation' | 'plus' | 'journal'; event?: EventRef | null; events?: EventRef[]; openAlerts?: number; title?: string; bare?: boolean }>(), { event: null, events: () => [], openAlerts: 0, title: '', bare: false });
const page = usePage<{ auth?: { name: string; permissions: string[] } | null; criticalAlerts?: number; pilotageContext?: Context | null }>();
const currentEvent = computed(() => props.event ?? page.props.pilotageContext?.event ?? null);
const events = computed(() => props.events.length ? props.events : page.props.pilotageContext?.events ?? []);
const openAlerts = computed(() => page.props.pilotageContext?.openAlerts ?? props.openAlerts);
const can = (permission: string) => page.props.auth?.permissions?.includes(permission) ?? false;
const sound = useAlertSound(() => openAlerts.value, () => page.props.criticalAlerts ?? page.props.pilotageContext?.criticalAlerts ?? 0);
const open = ref(false);
const items = [
  { key: 'tableau', label: 'Tableau de bord', short: 'Tableau', icon: 'home', href: '/pilotage', permission: 'pilotage.consulter' },
  { key: 'alertes', label: 'Alertes', short: 'Alertes', icon: 'alert', href: '/pilotage/alertes', permission: 'alertes.consulter' },
  { key: 'presents', label: 'Personnes présentes', short: 'Présents', icon: 'users', href: '/pilotage/presents', permission: 'presences.consulter' },
  { key: 'litiges', label: 'Litiges', short: 'Litiges', icon: 'shield', href: '/pilotage/litiges', permission: 'litiges.decider' },
  { key: 'plus', label: 'Groupes et cars', short: 'Groupes', icon: 'bus', href: '/pilotage/groupes', permission: 'groupes.suivre' },
  { key: 'evacuation', label: 'Évacuation', short: 'Évacuation', icon: 'arrow', href: '/pilotage/evacuation', permission: 'presences.consulter' },
  { key: 'journal', label: 'Journal', short: 'Journal', icon: 'book', href: '/administration/journal', permission: 'journal.consulter' },
] as const;
const allowed = computed(() => items.filter(item => can(item.permission) && (item.key !== 'plus' || can('cars.suivre'))));
const bottom = computed(() => allowed.value.filter(item => !['plus', 'journal'].includes(item.key)));
const href = (path: string) => pilotageHref(path, currentEvent.value?.id);
function change(event: Event) {
  router.get(window.location.pathname, { ...Object.fromEntries(new URLSearchParams(window.location.search)), event: (event.target as HTMLSelectElement).value, page: undefined });
}
</script>
<template>
  <div class="pilotage-shell" :class="{ 'is-bare': bare }" data-testid="pilotage-layout">
    <a href="#contenu" class="pilotage-skip no-print">Aller au contenu</a>
    <header v-if="!bare" class="pilotage-header no-print">
      <div class="pilotage-brand"><span class="pilotage-logo"><img src="/brand/ikoma-access-icon.svg" alt="" width="36" height="36"></span><p><strong>IKOMA</strong> ACCESS <span class="pilotage-caption">Pilotage</span></p></div>
      <div v-if="currentEvent" class="pilotage-event">
        <label v-if="events.length > 1">Événement de référence<select :value="currentEvent.id" data-testid="evenement" @change="change"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select></label>
        <p v-else data-testid="evenement"><span class="pilotage-caption">Événement de référence</span>{{ currentEvent.name }}</p>
      </div>
      <div class="pilotage-session">
        <button type="button" class="pilotage-sound" :title="'Son des alertes : ' + (sound.enabled.value ? 'activé' : 'désactivé')" :aria-pressed="sound.enabled.value" data-testid="son-alertes" @click="sound.toggle"><AdminIcon :name="sound.enabled.value ? 'speaker' : 'speakerOff'" :size="24" /><span>Son des alertes : {{ sound.enabled.value ? 'activé' : 'désactivé' }}</span></button>
        <details class="pilotage-account"><summary :aria-label="'Session de ' + (page.props.auth?.name ?? 'superviseur')"><span class="pilotage-avatar" aria-hidden="true">{{ page.props.auth?.name?.charAt(0).toUpperCase() ?? 'S' }}</span><span class="pilotage-user">{{ page.props.auth?.name ?? 'Session' }}</span><span aria-hidden="true">⌄</span></summary><div><Link href="/securite">Sécurité du compte</Link><Link as="button" method="post" href="/logout" @click="wipePilotageLocal">Se déconnecter</Link></div></details>
        <button type="button" class="pilotage-menu-toggle" :aria-expanded="open" aria-controls="menu-pilotage" data-testid="ouvrir-menu-pilotage" @click="open = !open"><AdminIcon name="menu" /><span>Menu superviseur</span></button>
      </div>
    </header>
    <div class="pilotage-frame">
      <aside v-if="!bare" class="pilotage-sidebar no-print">
        <nav id="menu-pilotage" aria-label="Menu superviseur" :class="{ 'is-open': open }" @keydown.esc="open = false">
          <p class="pilotage-nav-caption">Supervision</p>
          <Link v-for="item in allowed" :key="item.key" :href="href(item.href)" :aria-current="active === item.key ? 'page' : undefined" class="pilotage-nav-link" :data-testid="'menu-' + item.key" @click="open = false"><AdminIcon :name="item.icon" :size="24" /><span>{{ item.label }}</span><span v-if="item.key === 'alertes' && openAlerts" class="pilotage-count">{{ openAlerts }}</span></Link>
        </nav>
      </aside>
      <main id="contenu" class="pilotage-main" tabindex="-1">
        <div v-if="title" class="pilotage-heading no-print"><div><h1>{{ title }}</h1><p v-if="active === 'tableau'" class="pilotage-muted">Surveiller, décider, agir.</p></div><slot name="heading-actions" /></div>
        <p v-if="!currentEvent && !bare && active !== 'journal'" role="status" class="pilotage-card">Aucun événement n’est encore créé.</p>
        <slot />
      </main>
    </div>
    <nav v-if="!bare" aria-label="Navigation du pilotage" class="pilotage-bottom no-print">
<ul>
      <li v-for="item in bottom" :key="item.key"><Link :href="href(item.href)" :aria-current="active === item.key ? 'page' : undefined" :data-testid="'nav-' + item.key"><span class="pilotage-bottom-icon"><AdminIcon :name="item.icon" :size="22" /><span v-if="item.key === 'alertes' && openAlerts" class="pilotage-count" data-testid="alertes-ouvertes">{{ openAlerts }}</span></span><span>{{ item.short }}</span></Link></li>
    </ul>
</nav>
  </div>
</template>
