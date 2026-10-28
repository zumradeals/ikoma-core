<script setup lang="ts">
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useAlertSound } from '../pilotage/useAlertSound';

// Les propriétés partagées par le serveur (criticalAlerts…) arrivent aussi aux pages : elles ne doivent pas se retrouver en attributs HTML.
defineOptions({ inheritAttrs: false });
const props = defineProps<{ active: 'tableau' | 'alertes' | 'presents' | 'litiges' | 'evacuation' | 'plus'; event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; title: string; bare?: boolean }>();
const page = usePage<{ criticalAlerts?: number }>();
const sound = useAlertSound(() => props.openAlerts, () => page.props.criticalAlerts ?? 0);
const suffix = computed(() => (props.event && props.events.length > 1 ? `?event=${props.event.id}` : ''));
const items = computed(() => [
  { key: 'tableau', label: 'Tableau', icon: '▦', href: '/pilotage' }, { key: 'alertes', label: 'Alertes', icon: '▲', href: '/pilotage/alertes' }, { key: 'presents', label: 'Présents', icon: '●', href: '/pilotage/presents' },
  { key: 'litiges', label: 'Litiges', icon: '⚖', href: '/pilotage/litiges' }, { key: 'evacuation', label: 'Évacuation', icon: '⚑', href: '/pilotage/evacuation' },
] as const);
function change(event: Event) { router.get(window.location.pathname, { event: (event.target as HTMLSelectElement).value }); }
</script>
<template>
  <div class="min-h-screen bg-slate-50 pb-24 text-ikoma-navy">
    <header class="no-print border-b-4 border-ikoma-orange bg-ikoma-navy px-4 py-3 text-white">
      <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-2">
        <p class="font-bold">IKOMA <span class="font-normal">Pilotage</span></p>
        <button v-if="!bare" type="button" class="inline-flex min-h-14 items-center gap-2 rounded-xl border-2 border-white px-3 font-semibold no-print" :aria-pressed="sound.enabled.value" data-testid="son-alertes" @click="sound.toggle"><span aria-hidden="true">{{ sound.enabled.value ? '♪' : '✕' }}</span> Son des alertes : {{ sound.enabled.value ? 'activé' : 'désactivé' }}</button>
        <Link v-if="!bare" href="/espace" class="inline-flex min-h-14 items-center underline no-print">Mon espace</Link>
      </div>
      <div v-if="event" class="mx-auto max-w-3xl">
        <p v-if="events.length < 2" class="text-lg" data-testid="evenement">{{ event.name }}</p>
        <label v-else class="block text-lg">Événement
          <select class="field !mt-1 !min-h-14 !text-ikoma-navy" :value="event.id" data-testid="evenement" @change="change">
            <option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option>
          </select>
        </label>
      </div>
    </header>
    <main id="contenu" class="mx-auto max-w-3xl space-y-5 px-4 py-5">
      <h1 class="no-print text-display font-bold">{{ title }}</h1>
      <p v-if="!event && !bare" role="status" class="rounded-xl bg-white p-4">Aucun événement n’est encore créé.</p>
      <slot />
    </main>
    <nav v-if="!bare" aria-label="Navigation du pilotage" class="no-print fixed inset-x-0 bottom-0 z-40 border-t-2 border-ikoma-navy bg-white">
      <ul class="mx-auto grid max-w-3xl grid-cols-5">
        <li v-for="item in items" :key="item.key">
          <Link :href="item.href + suffix" class="flex min-h-16 flex-col items-center justify-center px-1 text-sm font-semibold" :class="active === item.key ? 'bg-ikoma-navy text-white' : 'text-ikoma-navy'" :aria-current="active === item.key ? 'page' : undefined" :data-testid="'nav-' + item.key">
            <span aria-hidden="true" class="text-xl leading-none">{{ item.icon }}</span>
            <span>{{ item.label }}<span v-if="item.key === 'alertes' && openAlerts > 0" class="ml-1 rounded-full bg-red-700 px-2 text-white" data-testid="alertes-ouvertes">{{ openAlerts }}</span></span>
          </Link>
        </li>
      </ul>
    </nav>
  </div>
</template>
