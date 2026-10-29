<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { actionLabels } from '../../labels';

type Step = { key: string; title: string; status: 'fait' | 'en_cours' | 'a_faire'; detail: string; href: string };
type Watch = { severity: 'haute' | 'normale' | 'info'; title: string; text: string; href: string };
type Tile = { value: number; total?: number; caption: string };
const props = defineProps<{
  event: { id: string; name: string } | null; events: { id: string; name: string }[]; steps: Step[];
  summary: { done: number; total: number; next: { title: string; href: string; text: string } | null };
  tiles: { invites: Tile; badges: Tile; tablettes: Tile; comptes: Tile } | null; watch: Watch[]; recent: { action: string; occurred_at: string }[];
}>();

const statusLook = {
  fait: { label: '✓ Fait', pill: 'bg-green-100 text-green-900', row: 'bg-slate-50' },
  en_cours: { label: '● En cours', pill: 'bg-orange-100 text-orange-950', row: 'border-2 border-ikoma-orange bg-orange-50' },
  a_faire: { label: '○ À faire', pill: 'bg-blue-100 text-blue-950', row: 'bg-slate-50' },
} as const;
const severityLook = {
  haute: { label: 'Important', icon: 'alert', color: 'bg-red-700' },
  normale: { label: 'À prévoir', icon: 'clock', color: 'bg-ikoma-orange' },
  info: { label: 'À savoir', icon: 'info', color: 'bg-ikoma-blue' },
} as const;
const percent = computed(() => (props.summary.total ? Math.round((props.summary.done / props.summary.total) * 100) : 0));
const ready = computed(() => props.summary.total > 0 && props.summary.done === props.summary.total);
const counts = computed(() => {
  const n = (s: Step['status']) => props.steps.filter(x => x.status === s).length;
  return `${n('fait')} faite${n('fait') > 1 ? 's' : ''} · ${n('en_cours')} en cours · ${n('a_faire')} à faire`;
});
const tileList = computed(() => (props.tiles ? [
  { key: 'invites', label: 'Invités attendus', icon: 'users', color: 'bg-ikoma-blue', t: props.tiles.invites },
  { key: 'badges', label: 'Badges libres', icon: 'qr', color: 'bg-ikoma-orange', t: props.tiles.badges },
  { key: 'tablettes', label: 'Tablettes enrôlées', icon: 'tablet', color: 'bg-ikoma-success', t: props.tiles.tablettes },
  { key: 'comptes', label: 'Comptes actifs', icon: 'shield', color: 'bg-ikoma-navy', t: props.tiles.comptes },
] : []));
const time = (at: string) => new Date(at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
</script>

<template>
  <AdminLayout active="tableau" :event="event" :events="events">
    <div v-if="!event" class="rounded-2xl border border-slate-200 bg-white p-6" data-testid="aucun-evenement">
      <h1 class="text-display font-bold">Tableau de bord</h1>
      <p class="mt-2">Aucun événement n’est encore enregistré. Créez votre premier événement pour préparer ensuite les zones, les badges et les tablettes.</p>
      <Link href="/administration/evenements" class="admin-primary mt-5 inline-flex min-h-14 items-center gap-3" data-testid="creer-premier-evenement"><AdminIcon name="plus" :size="20" />Créer mon premier événement</Link>
    </div>

    <template v-else>
      <div class="mb-4 flex justify-end"><Link href="/administration/evenements" class="inline-flex min-h-12 items-center gap-2 rounded-xl border-2 border-ikoma-blue bg-white px-5 py-2 font-bold text-ikoma-navy" data-testid="gerer-evenements"><AdminIcon name="plus" :size="20" />Gérer les événements</Link></div>
      <section aria-labelledby="titre-etat" class="dashboard-hero" data-testid="tableau-admin">
        <div class="min-w-0 flex-1 basis-80">
          <p class="text-sm font-bold uppercase tracking-wide text-orange-200">Bonjour</p>
          <h1 id="titre-etat" class="mt-1 text-3xl font-extrabold leading-tight" data-testid="titre-etat">{{ ready ? 'Votre événement est prêt' : summary.done === 0 ? 'Préparons votre événement' : summary.done < summary.total / 2 ? 'Votre événement se prépare' : 'Votre événement est presque prêt' }}</h1>
          <p class="mt-2 text-lg text-blue-100" data-testid="avancement">
            {{ summary.done }} étape{{ summary.done > 1 ? 's' : '' }} sur {{ summary.total }} {{ summary.done > 1 ? 'sont terminées' : 'est terminée' }}.<template v-if="summary.next"> Prochaine étape : {{ summary.next.title.toLowerCase() }}.</template>
          </p>
          <div class="mt-4 h-3.5 max-w-md overflow-hidden rounded-full bg-blue-900" role="progressbar" aria-label="Avancement de la préparation" aria-valuemin="0" :aria-valuemax="summary.total" :aria-valuenow="summary.done">
            <div class="h-full rounded-full bg-ikoma-orange" :style="{ width: percent + '%' }"></div>
          </div>
          <p class="mt-2 text-sm text-blue-100">{{ summary.done }} sur {{ summary.total }}</p>
        </div>
        <Link v-if="summary.next" :href="summary.next.href" class="admin-primary dashboard-next" data-testid="prochaine-etape">
          {{ summary.next.text }}<AdminIcon name="arrow" :stroke="2.5" />
        </Link>
      </section>

      <section aria-label="Chiffres clés" class="dashboard-tiles">
        <div v-for="tile in tileList" :key="tile.key" class="admin-card dashboard-tile" :data-testid="'tuile-' + tile.key">
          <p class="flex items-center gap-3 font-semibold text-slate-600"><span class="grid size-9 place-items-center rounded-full text-white" :class="tile.color"><AdminIcon :name="tile.icon" :size="20" /></span>{{ tile.label }}</p>
          <p class="mt-3 text-5xl font-extrabold leading-none" data-testid="valeur">{{ tile.t.value }}<span v-if="tile.t.total !== undefined" class="ml-2 text-xl font-semibold text-slate-600">sur {{ tile.t.total }}</span></p>
          <p class="mt-2 text-sm text-slate-600">{{ tile.t.caption }}</p>
        </div>
      </section>

      <div class="dashboard-columns">
        <section aria-labelledby="titre-prep" class="admin-card dashboard-preparation">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="titre-prep" class="text-2xl font-extrabold">Préparer l’événement</h2>
            <span class="text-sm font-semibold text-slate-600" data-testid="decompte">{{ counts }}</span>
          </div>
          <p class="admin-muted mt-2">Suivez l’avancement et accédez directement à chaque module.</p><ol class="mt-4 space-y-3">
            <li v-for="(step, i) in steps" :key="step.key" class="dashboard-step" :class="statusLook[step.status].row" :data-testid="'etape-' + step.key" :data-status="step.status">
              <span class="grid size-10 flex-none place-items-center rounded-full font-extrabold" :class="step.status === 'fait' ? 'bg-ikoma-success text-white' : step.status === 'en_cours' ? 'bg-ikoma-orange text-white' : 'border-2 border-slate-500 bg-white text-slate-600'" aria-hidden="true">
                <AdminIcon v-if="step.status === 'fait'" name="check" :stroke="3" /><template v-else>{{ i + 1 }}</template>
              </span>
              <div class="min-w-0 flex-1 basis-48">
                <p class="font-bold">{{ step.title }}</p>
                <p class="text-sm text-slate-600">{{ step.detail }}</p>
              </div>
              <span class="rounded-full px-3 py-1.5 text-sm font-bold" :class="statusLook[step.status].pill" :data-testid="'statut-' + step.key">{{ statusLook[step.status].label }}</span>
              <Link :href="step.href" class="dashboard-step-action" :class="step.status === 'en_cours' ? 'bg-ikoma-orange font-extrabold text-ikoma-navy' : 'border-2 border-ikoma-blue bg-white text-ikoma-navy'">{{ step.status === 'en_cours' ? 'Continuer' : 'Ouvrir' }}</Link>
            </li>
          </ol>
        </section>

        <div class="min-w-0 space-y-4">
          <section aria-labelledby="titre-surveiller" class="admin-card" data-testid="a-surveiller">
            <h2 id="titre-surveiller" class="text-2xl font-extrabold">À surveiller</h2>
            <p v-if="!watch.length" class="mt-3 flex items-center gap-3 font-semibold text-green-900"><span class="grid size-9 place-items-center rounded-full bg-ikoma-success text-white"><AdminIcon name="check" :size="20" :stroke="3" /></span>Rien à signaler pour le moment.</p>
            <ul v-else class="mt-4 space-y-4">
              <li v-for="(w, i) in watch" :key="i" class="dashboard-watch" :class="'severity-' + w.severity">
                <span class="grid size-9 flex-none place-items-center rounded-full text-white" :class="severityLook[w.severity].color"><AdminIcon :name="severityLook[w.severity].icon" :size="20" :stroke="2.5" /></span>
                <div class="min-w-0">
                  <p class="font-bold"><span class="sr-only">{{ severityLook[w.severity].label }} : </span>{{ w.title }}</p>
                  <p class="text-sm text-slate-600">{{ w.text }}</p>
                  <Link :href="w.href" class="inline-flex min-h-12 items-center text-sm font-bold text-ikoma-blue underline">Voir</Link>
                </div>
              </li>
            </ul>
          </section>

          <section aria-labelledby="titre-journal" class="admin-card">
            <div class="admin-section-heading"><h2 id="titre-journal">Dernières actions</h2><Link href="/administration/journal" class="admin-muted underline">Voir tout le journal →</Link></div>
            <ul v-if="recent.length" class="mt-4 space-y-3" data-testid="dernieres-actions">
              <li v-for="(r, i) in recent" :key="i" class="flex items-center justify-between gap-3 text-sm"><AdminIcon name="book" :size="18" /><span class="min-w-0 grow">{{ actionLabels[r.action] ?? r.action }}</span><span class="flex-none text-slate-600">{{ time(r.occurred_at) }}</span></li>
            </ul>
            <p v-else class="mt-3 text-slate-600">Aucune action enregistrée.</p>
          </section>
        </div>
      </div>
    </template>
  </AdminLayout>
</template>
