<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import { actionLabels, displayDate } from '../../labels';

/**
 * Activités (V1) : registre des activités + fiche en panneau. Lecture composée par ActivityRegistry ; chaque opération appelle la route existante
 * (inscription, clôture de participation, création, modification, clôture et archivage de l'activité). Aucune règle n'est portée par l'écran.
 */
type EventRow = {
  id: string; name: string; site: string | null; kind?: string; starts_at: string; ends_at: string; status?: string | null; phase?: string;
  participants?: number | string; participants_actifs?: number | string;
};
type Choice = { id: string; name: string };
type Profile = {
  event: { id: string; name: string; site: string | null; kind: string; starts_at: string; ends_at: string; status: string | null; closed: boolean; anonymized: boolean };
  counts: { participants: number; actifs: number; presents: number; clotures: number; groupes: number; vehicules: number; badges_attribues: number; badges_restitues: number; habilitations: number };
  categories: (Choice & { participants: number })[]; groups: { id: string; name: string; bus_number: string | null; supervisor: string | null; members: number }[];
  zones: (Choice & { habilitations: number })[]; journal: { id: number; action: string; at: string }[];
  enrol: { categories: Choice[]; hosts: Choice[] } | null;
};
type Participant = { id: string; person_id: string | null; full_name: string; organisation: string | null; category: string; group: string | null; status: string; habilitations: number; badge: string | null; can_close: boolean };
type Found = { id: string; full_name: string; organisation: string | null; status: string; participations: number | string; permanent: boolean };

const props = defineProps<{
  archives?: boolean; events: EventRow[];
  kpis?: { en_cours: number; participants_actifs: number; a_venir: number; cloturees: number };
  selected?: Profile | null;
  participants?: { data: Participant[]; meta: { current_page: number; last_page: number; total: number } } | null; participantQuery?: string;
  can?: { participants: boolean; journal: boolean; groupes: boolean; badges: boolean; site: boolean; zones?: boolean };
}>();

const kinds: Record<string, string> = { evenement: 'Événement', stage: 'Stage', visite: 'Visite' };
const phases: Record<string, string> = { a_venir: 'À venir', en_cours: 'En cours', terminee: 'Dates dépassées', cloturee: 'Clôturée', archivee: 'Archivée' };
const phaseOf = (e: { phase?: string; status?: string | null }) => e.phase ?? (e.status === 'archive' ? 'archivee' : e.status === 'clos' ? 'cloturee' : 'en_cours');
const phaseClass = (p: string) => (p === 'en_cours' ? 'bg-green-100 text-green-900' : p === 'a_venir' ? 'bg-amber-100 text-amber-900' : 'bg-slate-200 text-slate-700');
const partStatus: Record<string, string> = { attendu: 'Attendue', present: 'Présente', sorti: 'Sortie', refuse: 'Accès refusé', cloturee: 'Clôturée' };

// --- Création / modification (routes existantes) ---
const editing = ref<string | null>(null);
const creating = ref(false);
const operationError = ref('');
const form = useForm({ name: '', site: '', kind: 'evenement', starts_at: '', ends_at: '' });
function createEvent() {
  const options = { preserveScroll: true, onSuccess: () => { form.reset(); editing.value = null; creating.value = false; } };
  if (editing.value) form.put('/administration/evenements/' + editing.value, options);
  else form.post('/administration/evenements', options);
}
function startCreate() { editing.value = null; form.reset(); creating.value = !creating.value; }
function startEdit(e: { id: string; name: string; site: string | null; starts_at: string; ends_at: string }) {
  editing.value = e.id; creating.value = true;
  form.name = e.name; form.site = e.site ?? ''; form.starts_at = new Date(e.starts_at).toISOString().slice(0, 16); form.ends_at = new Date(e.ends_at).toISOString().slice(0, 16);
}
function transition(id: string, action: string) {
  if (window.confirm(action === 'cloturer' ? 'Clôturer cette activité ? Les présences, tablettes et opérations en attente seront vérifiées. Les habilitations permanentes ne sont pas modifiées.' : 'Archiver cette activité clôturée ?')) {
    router.post(`/administration/evenements/${id}/${action}`, { confirmation: true }, { onError: errors => { operationError.value = Object.values(errors).join(' '); } });
  }
}

// --- Registre : recherche, filtres et pagination sur la liste chargée ---
const search = ref(''); const nature = ref(''); const etat = ref(''); const page = ref(1);
const PER = 8;
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return props.events.filter(e => (!q || e.name.toLowerCase().includes(q) || (e.site ?? '').toLowerCase().includes(q) || (kinds[e.kind ?? ''] ?? '').toLowerCase().includes(q))
    && (!nature.value || e.kind === nature.value) && (!etat.value || phaseOf(e) === etat.value));
});
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER)));
const visible = computed(() => filtered.value.slice((page.value - 1) * PER, page.value * PER));
watch([search, nature, etat], () => { page.value = 1; });

// --- Fiche (panneau) ---
const TABS = [{ key: 'ensemble', label: 'Vue d’ensemble', icon: 'info' }, { key: 'participants', label: 'Participants', icon: 'users' }, { key: 'groupes', label: 'Groupes et cars', icon: 'bus' },
  { key: 'acces', label: 'Accès et badges', icon: 'shield' }, { key: 'historique', label: 'Historique et clôture', icon: 'clock' }] as const;
type TabKey = typeof TABS[number]['key'];
const tab = ref<TabKey>('ensemble');
const tabs = computed(() => TABS.filter(t => (t.key !== 'participants' || props.can?.participants) && (t.key !== 'groupes' || (props.selected && (props.selected.counts.groupes > 0 || props.selected.counts.vehicules > 0)))));
function onTabKey(event: KeyboardEvent) {
  const list = tabs.value; const index = list.findIndex(t => t.key === tab.value);
  const next = event.key === 'ArrowRight' ? index + 1 : event.key === 'ArrowLeft' ? index - 1 : null;
  if (next === null) return;
  event.preventDefault(); tab.value = list[(next + list.length) % list.length].key;
  nextTick(() => (panel.value?.querySelector('[role="tab"][aria-selected="true"]') as HTMLElement | null)?.focus());
}
const panel = ref<HTMLElement | null>(null);
const wide = ref(false); let media: MediaQueryList | null = null;
const onMedia = () => { wide.value = media?.matches ?? false; };
onMounted(() => {
  if (typeof window === 'undefined') return;
  if (typeof window.matchMedia === 'function') { media = window.matchMedia('(min-width: 1360px)'); onMedia(); media.addEventListener?.('change', onMedia); }
  const wanted = new URLSearchParams(window.location.search).get('onglet');
  if (wanted && TABS.some(t => t.key === wanted)) tab.value = wanted as TabKey;
});
onBeforeUnmount(() => media?.removeEventListener?.('change', onMedia));
const drawerOpen = computed(() => props.selected != null && !wide.value);
const LAZY = ['selected', 'participants', 'participantQuery', 'can', 'flash'];
function open(e: EventRow) {
  tab.value = 'ensemble'; resetOps();
  router.get('/administration/evenements', { activite: e.id, ...(props.archives ? { archives: 1 } : {}) }, { only: LAZY, preserveState: true, preserveScroll: true, replace: true });
}
function closePanel() { router.get('/administration/evenements', props.archives ? { archives: 1 } : {}, { only: LAZY, preserveState: true, preserveScroll: true, replace: true }); }
const partQuery = ref(props.participantQuery ?? '');
function loadParticipants(p = 1) {
  if (!props.selected) return;
  router.get('/administration/evenements', { activite: props.selected.event.id, q: partQuery.value.trim() || undefined, page: p > 1 ? p : undefined, ...(props.archives ? { archives: 1 } : {}) },
    { only: LAZY, preserveState: true, preserveScroll: true, replace: true });
}

// --- Inscription : rechercher → choisir une identité existante → inscrire (route existante) ---
const enrolOpen = ref(false); const personQuery = ref(''); const found = ref<Found[]>([]); const searched = ref(false); const chosen = ref<Found | null>(null);
const enrolForm = ref({ category_id: '', host_id: '' });
const errors = ref<string[]>([]);
function resetOps() { enrolOpen.value = false; personQuery.value = ''; found.value = []; searched.value = false; chosen.value = null; closing.value = null; reason.value = ''; errors.value = []; }
async function lookup() {
  errors.value = []; chosen.value = null;
  const q = personQuery.value.trim();
  if (q.length < 2) { errors.value = ['Saisissez au moins deux caractères.']; return; }
  try {
    const r = await fetch('/administration/personnes/recherche?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    found.value = r.ok ? (await r.json()).results : []; searched.value = true;
  } catch { errors.value = ['La recherche est indisponible.']; }
}
const onError = (e: Record<string, string>) => { errors.value = Object.values(e); };
function enrol() {
  if (!props.selected || !chosen.value) return;
  errors.value = [];
  router.post(`/administration/personnes/${chosen.value.id}/participations`, { event_id: props.selected.event.id, category_id: enrolForm.value.category_id, host_id: enrolForm.value.host_id || null, retour: 'activites' },
    { preserveScroll: true, onSuccess: () => resetOps(), onError });
}
function toggleEnrol() { enrolOpen.value = !enrolOpen.value; enrolForm.value = { category_id: props.selected?.enrol?.categories[0]?.id ?? '', host_id: '' }; }

// --- Clôture d'une participation (route existante : motif obligatoire, refus tant qu'un badge ou un véhicule est présent) ---
const closing = ref<string | null>(null); const reason = ref('');
// Préparer les badges : sélection de participants, puis le MÊME parcours que « Badges et cartes » (aucune règle ici).
const picked = ref<string[]>([]);
const canPrepare = computed(() => !!props.can?.participants && !!props.can?.badges && !!props.selected && !props.selected.event.closed);
function togglePick(id: string) { picked.value = picked.value.includes(id) ? picked.value.filter(x => x !== id) : [...picked.value, id]; }
function pickAll() { picked.value = (props.participants?.data ?? []).filter(p => p.can_close).map(p => p.id); }
const prepareHref = computed(() => props.selected ? `/administration/badges?activite=${encodeURIComponent(props.selected.event.id)}${picked.value.length ? '&ids=' + picked.value.join(',') : ''}` : '#');
function closeParticipation(p: Participant) {
  errors.value = [];
  router.post(`/administration/participations/${p.id}/cloturer`, { reason: reason.value.trim(), retour: 'activites' }, { preserveScroll: true, onSuccess: () => { closing.value = null; reason.value = ''; }, onError });
}
watch(() => props.selected?.event.id, () => { resetOps(); partQuery.value = ''; picked.value = []; });
const n = (v: number | string | undefined) => Number(v ?? 0);
</script>

<template>
  <AdminLayout active="evenements">
    <div class="space-y-5">
      <header class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-display font-bold">Activités</h1>
          <p class="admin-muted mt-2">Stages, formations, événements et visites : une activité est un contexte temporaire qui utilise le site. Elle ne crée ni ne possède les postes et ne modifie jamais les habilitations permanentes.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Link v-if="can?.participants" href="/administration/imports" class="site-btn site-btn-outline" data-testid="importer-participants"><AdminIcon name="arrow" :size="18" />Importer (CSV)</Link>
          <button type="button" class="site-btn site-btn-accent" :aria-expanded="creating" data-testid="nouvelle-activite" @click="startCreate"><AdminIcon name="plus" :size="18" />Nouvelle activité</button>
        </div>
      </header>

      <dl v-if="kpis" class="grid grid-cols-2 gap-3 xl:grid-cols-4" data-testid="indicateurs-activites">
        <div class="site-kpi bg-sky-50"><span class="site-kpi-icon text-sky-700"><AdminIcon name="calendar" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-en-cours">{{ kpis.en_cours }}</dd><dt class="font-semibold text-slate-800">Activités en cours</dt></div></div>
        <div class="site-kpi bg-emerald-50"><span class="site-kpi-icon text-emerald-700"><AdminIcon name="users" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-participants">{{ kpis.participants_actifs }}</dd><dt class="font-semibold text-slate-800">Participants actifs</dt></div></div>
        <div class="site-kpi bg-amber-50"><span class="site-kpi-icon text-amber-700"><AdminIcon name="clock" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-a-venir">{{ kpis.a_venir }}</dd><dt class="font-semibold text-slate-800">Activités à venir</dt></div></div>
        <div class="site-kpi bg-slate-100"><span class="site-kpi-icon text-slate-600"><AdminIcon name="check" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-cloturees">{{ kpis.cloturees }}</dd><dt class="font-semibold text-slate-800">Activités clôturées</dt></div></div>
      </dl>

      <!-- Création / modification : les champs et les routes existent déjà -->
      <section v-show="creating" class="admin-card" aria-labelledby="titre-creer-evenement">
        <h2 id="titre-creer-evenement" class="text-2xl font-bold">{{ editing ? 'Modifier l’activité' : 'Créer une activité' }}</h2>
        <p class="admin-muted mt-2">Une zone d’accueil sera créée automatiquement. Les autres réglages viendront ensuite.</p>
        <form class="mt-6 grid gap-5 md:grid-cols-2" data-testid="form-creer-evenement" @submit.prevent="createEvent">
          <div>
            <label for="nom-evenement" class="block font-semibold">Nom de l’activité <span aria-hidden="true">*</span></label>
            <input id="nom-evenement" v-model="form.name" class="field" type="text" maxlength="160" placeholder="Ex. Stage de sécurité opérationnelle" required autocomplete="off" :aria-invalid="!!form.errors.name">
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.name }}</p>
          </div>
          <div>
            <label for="nature-evenement" class="block font-semibold">Nature de l’activité</label>
            <select id="nature-evenement" v-model="form.kind" class="field" :disabled="!!editing" data-testid="nature-evenement"><option value="evenement">Événement</option><option value="stage">Stage</option><option value="visite">Visite</option></select>
            <p class="mt-1 text-sm text-slate-600">Chaque activité est indépendante : une même personne peut participer à plusieurs stages, événements ou visites.</p>
          </div>
          <div>
            <label for="site-evenement" class="block font-semibold">Lieu / site (facultatif)</label>
            <input id="site-evenement" v-model="form.site" class="field" type="text" maxlength="255" placeholder="Ex. Salle de formation 1" :aria-invalid="!!form.errors.site">
            <p v-if="form.errors.site" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.site }}</p>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label for="debut-evenement" class="block font-semibold">Début <span aria-hidden="true">*</span></label>
              <input id="debut-evenement" v-model="form.starts_at" class="field" type="datetime-local" required :aria-invalid="!!form.errors.starts_at">
              <p v-if="form.errors.starts_at" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.starts_at }}</p>
            </div>
            <div>
              <label for="fin-evenement" class="block font-semibold">Fin <span aria-hidden="true">*</span></label>
              <input id="fin-evenement" v-model="form.ends_at" class="field" type="datetime-local" required :aria-invalid="!!form.errors.ends_at">
              <p v-if="form.errors.ends_at" class="mt-1 text-sm text-red-700" role="alert">{{ form.errors.ends_at }}</p>
            </div>
          </div>
          <p class="text-sm text-slate-600 md:col-span-2">La date de fin doit être postérieure au début. Les heures sont celles du site de l’activité.</p>
          <button type="submit" class="admin-primary flex min-h-14 items-center justify-center gap-2 md:col-span-2" :disabled="form.processing" data-testid="creer-evenement">
            <AdminIcon name="plus" :size="20" />
            {{ form.processing ? 'Enregistrement…' : (editing ? 'Enregistrer les modifications' : 'Créer l’événement') }}
          </button>
        </form>
      </section>

      <p v-if="operationError" role="alert" class="rounded-xl border border-red-300 bg-red-50 p-3 font-semibold text-red-900" data-testid="erreur-operation">{{ operationError }}</p>

      <div class="pers-grid">
        <!-- Registre des activités -->
        <section class="site-card" aria-labelledby="registre-activites" data-testid="registre-activites">
          <header class="site-card-head"><h2 id="registre-activites"><AdminIcon name="list" :size="22" />Registre des activités ({{ events.length }})</h2></header>
          <div class="space-y-3 px-4 pb-4">
            <Link :href="archives ? '/administration/evenements' : '/administration/evenements?archives=1'" class="btn-soft">{{ archives ? 'Voir les activités opérationnelles' : 'Consulter les archives' }}</Link>
            <div role="search" class="space-y-3">
              <label class="block"><span class="sr-only">Rechercher une activité</span><input v-model="search" class="field !mt-0" maxlength="160" placeholder="Rechercher (intitulé, lieu, nature)" data-testid="recherche-activite"></label>
              <div class="grid gap-2 sm:grid-cols-2">
                <label class="block"><span class="sr-only">Nature</span><select v-model="nature" class="field !mt-0" data-testid="filtre-nature"><option value="">Toutes les natures</option><option value="evenement">Événement</option><option value="stage">Stage</option><option value="visite">Visite</option></select></label>
                <label class="block"><span class="sr-only">État</span><select v-model="etat" class="field !mt-0" data-testid="filtre-etat"><option value="">Tous les états</option><option value="en_cours">En cours</option><option value="a_venir">À venir</option><option value="terminee">Dates dépassées</option><option value="cloturee">Clôturée</option><option value="archivee">Archivée</option></select></label>
              </div>
            </div>
          </div>
          <p v-if="!events.length" class="px-4 pb-4 admin-muted" data-testid="liste-evenements-vide">Aucun événement pour le moment. Utilisez « Nouvelle activité » pour commencer.</p>
          <p v-else-if="!filtered.length" class="px-4 pb-4" data-testid="aucune-activite">Aucune activité ne correspond.</p>
          <ul v-else class="site-table" data-testid="liste-evenements">
            <li class="site-row site-row-head act-row" aria-hidden="true"><span>Activité</span><span>Période</span><span>Participants</span><span>État</span></li>
            <li v-for="event in visible" :key="event.id" class="site-row act-row" :class="selected?.event.id === event.id ? 'bg-blue-50' : ''" :data-testid="'activite-' + event.id">
              <button type="button" class="pers-name" :aria-expanded="selected?.event.id === event.id" :aria-label="'Consulter la fiche de ' + event.name" :data-testid="'selectionner-activite-' + event.id" @click="open(event)">
                <span class="min-w-0 text-left"><strong class="block">{{ event.name }}</strong><small class="block text-slate-600"><span v-if="event.kind" data-testid="nature-activite">{{ kinds[event.kind] ?? event.kind }}</span><template v-if="event.site"> · {{ event.site }}</template></small></span>
              </button>
              <span class="text-sm">{{ displayDate(event.starts_at) }}<br>→ {{ displayDate(event.ends_at) }}</span>
              <span class="text-sm" :data-testid="'effectif-' + event.id">{{ n(event.participants_actifs) }} actif<template v-if="n(event.participants_actifs) > 1">s</template><small class="block text-slate-600">{{ n(event.participants) }} inscrit<template v-if="n(event.participants) > 1">s</template></small></span>
              <span class="flex flex-wrap items-center gap-2">
                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="phaseClass(phaseOf(event))" :data-testid="'etat-' + event.id">{{ phases[phaseOf(event)] }}</span>
                <a :href="'/administration?event=' + encodeURIComponent(event.id)" class="text-sm font-semibold underline" :data-testid="'ouvrir-evenement-' + event.id">Tableau</a>
              </span>
              <details class="act-actions" :data-testid="'actions-' + event.id">
                <summary class="cursor-pointer font-semibold">Actions</summary>
                <div class="mt-3 flex flex-wrap gap-3">
                  <button v-if="!['clos', 'archive'].includes(event.status ?? '')" type="button" class="btn-soft" @click="startEdit(event)">Modifier</button>
                  <button v-if="!['clos', 'archive'].includes(event.status ?? '')" type="button" class="btn-soft" @click="transition(event.id, 'cloturer')">Clôturer</button>
                  <button v-if="event.status === 'clos'" type="button" class="btn-soft" @click="transition(event.id, 'archiver')">Archiver</button>
                  <Link :href="`/administration/donnees?entity=evenement&id=${event.id}`" class="btn-soft">Analyser une suppression</Link>
                </div>
              </details>
            </li>
          </ul>
          <nav v-if="pages > 1" class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm" aria-label="Pagination du registre des activités" data-testid="pagination-activites">
            <span>Page {{ page }} sur {{ pages }} · {{ filtered.length }} activité{{ filtered.length > 1 ? 's' : '' }}</span>
            <span class="flex gap-2">
              <button type="button" class="site-btn site-btn-outline !min-h-11" :disabled="page <= 1" @click="page--">Précédent</button>
              <button type="button" class="site-btn site-btn-outline !min-h-11" :disabled="page >= pages" @click="page++">Suivant</button>
            </span>
          </nav>
        </section>

        <p v-if="!selected" class="pers-placeholder site-card p-6 text-slate-600" data-testid="fiche-activite-vide">Sélectionnez une activité pour consulter sa fiche : participants, groupes, accès, badges et historique.</p>

        <button v-if="drawerOpen" type="button" class="pers-backdrop" aria-label="Fermer le panneau" tabindex="-1" data-testid="fond-panneau" @click="closePanel" />

        <!-- Fiche de l'activité -->
        <aside v-if="selected" ref="panel" class="pers-fiche" :role="wide ? undefined : 'dialog'" :aria-modal="wide ? undefined : 'true'" aria-label="Fiche de l’activité" data-testid="fiche-activite" @keydown.esc="closePanel">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-heading font-bold" data-testid="fiche-titre">{{ selected.event.name }}</h2>
              <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-700"><span class="rounded-full border px-2 py-0.5 text-xs font-semibold" data-testid="fiche-nature">{{ kinds[selected.event.kind] ?? selected.event.kind }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="phaseClass(phaseOf({ status: selected.event.status }))">{{ selected.event.closed ? (selected.event.status === 'archive' ? 'Archivée' : 'Clôturée') : 'Ouverte' }}</span></p>
            </div>
            <button type="button" class="btn-soft !min-h-11 !px-3 !py-1 text-sm" data-testid="fermer-fiche" @click="closePanel">Fermer</button>
          </div>

          <div class="site-tabs" role="tablist" aria-label="Sections de la fiche" @keydown="onTabKey">
            <button v-for="t in tabs" :id="'onglet-act-' + t.key" :key="t.key" type="button" role="tab" class="site-tab pers-tab" :aria-selected="tab === t.key" :tabindex="tab === t.key ? 0 : -1" :data-testid="'onglet-' + t.key" @click="tab = t.key"><AdminIcon :name="t.icon" :size="18" class="pers-tab-icon" />{{ t.label }}</button>
          </div>

          <p v-for="e in errors" :key="e" role="alert" class="rounded-lg border border-red-300 bg-red-50 p-2 text-sm font-semibold text-red-900" data-testid="erreur-fiche">{{ e }}</p>

          <!-- Vue d'ensemble -->
          <section v-if="tab === 'ensemble'" role="tabpanel" aria-labelledby="onglet-act-ensemble" class="space-y-4" data-testid="panneau-ensemble">
            <dl class="site-info">
              <div><dt>Période</dt><dd>{{ displayDate(selected.event.starts_at) }} → {{ displayDate(selected.event.ends_at) }}</dd></div>
              <div v-if="selected.event.site"><dt>Lieu</dt><dd>{{ selected.event.site }}</dd></div>
              <div><dt>Participants</dt><dd data-testid="eff-participants">{{ selected.counts.participants }} inscrit{{ selected.counts.participants > 1 ? 's' : '' }} · {{ selected.counts.actifs }} actif{{ selected.counts.actifs > 1 ? 's' : '' }}</dd></div>
              <div><dt>Présents sur site</dt><dd>{{ selected.counts.presents }}</dd></div>
              <div><dt>Participations clôturées</dt><dd>{{ selected.counts.clotures }}</dd></div>
            </dl>
            <div v-if="selected.categories.length">
              <h3 class="font-bold">Catégories de participation</h3>
              <p class="text-sm text-slate-600">Propres à cette activité ; elles ne deviennent jamais une catégorie permanente de la personne.</p>
              <ul class="mt-2 flex flex-wrap gap-2"><li v-for="c in selected.categories" :key="c.id" class="rounded-full border px-3 py-1 text-sm">{{ c.name }} · {{ c.participants }}</li></ul>
            </div>
            <div class="flex flex-wrap gap-3">
              <a :href="'/administration?event=' + encodeURIComponent(selected.event.id)" class="site-btn site-btn-outline" data-testid="fiche-tableau">Tableau de bord de l’activité</a>
              <button v-if="!selected.event.closed" type="button" class="site-btn site-btn-outline" data-testid="fiche-modifier" @click="startEdit(selected.event)">Modifier l’activité</button>
            </div>
          </section>

          <!-- Participants -->
          <section v-else-if="tab === 'participants' && can?.participants" role="tabpanel" aria-labelledby="onglet-act-participants" class="space-y-4" data-testid="panneau-participants">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h3 class="text-lg font-bold">Participants ({{ participants?.meta.total ?? 0 }})</h3>
              <button v-if="selected.enrol" type="button" class="site-btn site-btn-accent !min-h-11" :aria-expanded="enrolOpen" data-testid="ajouter-participant" @click="toggleEnrol"><AdminIcon name="plus" :size="18" />Ajouter un participant</button>
              <p v-else class="text-sm text-slate-600" data-testid="inscription-fermee">Inscriptions fermées : activité clôturée ou archivée.</p>
            </div>

            <div v-if="enrolOpen && selected.enrol" class="space-y-3 rounded-xl border border-blue-200 bg-blue-50 p-4" data-testid="formulaire-inscription">
              <p class="text-sm text-slate-700">Recherchez d’abord la personne : une seule identité par personne. Si elle n’existe pas, <Link href="/administration/personnes" class="font-semibold underline" data-testid="creer-personne">créez-la explicitement dans « Personnes »</Link> puis revenez l’inscrire.</p>
              <form class="flex gap-2" role="search" aria-label="Rechercher une personne à inscrire" @submit.prevent="lookup">
                <label class="block grow"><span class="sr-only">Nom de la personne</span><input v-model="personQuery" class="field !mt-0" maxlength="160" placeholder="Nom de la personne" data-testid="recherche-personne-inscription"></label>
                <button type="submit" class="site-btn site-btn-blue" data-testid="lancer-recherche-personne">Rechercher</button>
              </form>
              <p v-if="searched && !found.length" data-testid="aucune-personne-trouvee">Aucune personne trouvée.</p>
              <ul v-if="found.length" class="divide-y rounded-lg border bg-white" data-testid="resultats-personnes">
                <li v-for="p in found" :key="p.id" class="flex items-center justify-between gap-3 p-3" :class="chosen?.id === p.id ? 'bg-blue-100' : ''">
                  <span class="min-w-0"><strong class="block">{{ p.full_name }}</strong><small class="text-slate-600">{{ p.organisation ?? '—' }}<template v-if="p.permanent"> · personnel permanent</template> · {{ n(p.participations) }} participation{{ n(p.participations) > 1 ? 's' : '' }}</small></span>
                  <button type="button" class="site-btn site-btn-outline !min-h-11" :disabled="p.status !== 'actif'" :data-testid="'choisir-personne-' + p.id" @click="chosen = p">{{ p.status === 'actif' ? 'Choisir' : 'Archivée' }}</button>
                </li>
              </ul>
              <form v-if="chosen" class="space-y-3" data-testid="choix-categorie" @submit.prevent="enrol">
                <p class="font-semibold">Inscrire {{ chosen.full_name }} à « {{ selected.event.name }} »</p>
                <label class="block">Catégorie de participation <span aria-hidden="true">*</span>
                  <select v-model="enrolForm.category_id" class="field" required data-testid="categorie-inscription"><option value="" disabled>Choisir une catégorie</option><option v-for="c in selected.enrol.categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                <label v-if="selected.enrol.hosts.length" class="block">Hôte ou service (facultatif)
                  <select v-model="enrolForm.host_id" class="field"><option value="">Sans hôte</option><option v-for="h in selected.enrol.hosts" :key="h.id" :value="h.id">{{ h.name }}</option></select></label>
                <p v-if="!selected.enrol.categories.length" class="text-sm text-amber-900" role="status">Cette activité n’a pas encore de catégorie de participation : créez-en une dans « Catégories ».</p>
                <button type="submit" class="site-btn site-btn-blue" :disabled="!enrolForm.category_id" data-testid="inscrire">Inscrire à l’activité</button>
              </form>
            </div>

            <div v-if="canPrepare" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-blue-200 bg-blue-50 p-3" data-testid="preparer-badges-bloc">
              <p class="text-sm"><strong data-testid="nb-choisis">{{ picked.length }}</strong> participant{{ picked.length > 1 ? 's' : '' }} choisi{{ picked.length > 1 ? 's' : '' }} pour les badges.</p>
              <span class="flex flex-wrap gap-2">
                <button type="button" class="site-btn site-btn-outline !min-h-11" data-testid="choisir-tous" @click="pickAll">Choisir les participants actifs</button>
                <Link :href="prepareHref" class="site-btn site-btn-accent !min-h-11" data-testid="preparer-badges">Préparer les badges</Link>
              </span>
            </div>
            <form class="flex gap-2" role="search" aria-label="Rechercher un participant" @submit.prevent="loadParticipants()">
              <label class="block grow"><span class="sr-only">Rechercher un participant</span><input v-model="partQuery" class="field !mt-0" maxlength="160" placeholder="Rechercher un participant (nom, organisation)" data-testid="recherche-participant"></label>
              <button type="submit" class="site-btn site-btn-blue">Rechercher</button>
            </form>
            <p v-if="!participants?.data.length" data-testid="aucun-participant">Aucun participant{{ participantQuery ? ' ne correspond' : ' inscrit à cette activité' }}.</p>
            <ul v-else class="divide-y rounded-xl border" data-testid="liste-participants">
              <li v-for="p in participants.data" :key="p.id" class="space-y-2 p-3" :data-testid="'participant-' + p.id">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <label v-if="canPrepare && p.can_close" class="flex min-h-11 items-center gap-2"><input type="checkbox" class="h-6 w-6" :checked="picked.includes(p.id)" :aria-label="'Choisir ' + p.full_name + ' pour un badge'" :data-testid="'choisir-badge-' + p.id" @change="togglePick(p.id)"></label>
                  <div class="min-w-0 grow"><strong class="block">{{ p.full_name }}</strong><small class="text-slate-600">{{ p.organisation ?? '—' }} · {{ p.category }}<template v-if="p.group"> · {{ p.group }}</template></small></div>
                  <span class="rounded-full px-2.5 py-0.5 text-xs font-bold" :class="p.status === 'cloturee' ? 'bg-slate-200 text-slate-700' : p.status === 'present' ? 'bg-green-100 text-green-900' : 'bg-sky-100 text-sky-900'" :data-testid="'statut-' + p.id">{{ partStatus[p.status] ?? p.status }}</span>
                </div>
                <p class="text-sm text-slate-700"><span :data-testid="'habilitations-' + p.id">{{ p.habilitations }} habilitation{{ p.habilitations > 1 ? 's' : '' }} d’activité effective{{ p.habilitations > 1 ? 's' : '' }}</span> · <span :data-testid="'badge-' + p.id">{{ p.badge ? 'Badge ' + p.badge : 'Aucun badge attribué' }}</span></p>
                <div class="flex flex-wrap gap-2">
                  <Link v-if="p.person_id" :href="'/administration/personnes?personne=' + p.person_id" class="site-btn site-btn-outline !min-h-11" :data-testid="'fiche-personne-' + p.id">Fiche et habilitations</Link>
                  <button v-if="p.can_close && !selected.event.closed" type="button" class="site-btn site-btn-outline !min-h-11" :data-testid="'cloturer-participation-' + p.id" @click="closing = closing === p.id ? null : p.id; reason = ''; errors = []">Clôturer la participation</button>
                </div>
                <form v-if="closing === p.id" class="space-y-2 rounded-lg bg-slate-50 p-3" @submit.prevent="closeParticipation(p)">
                  <p class="text-sm text-slate-700">La personne, ses autres participations et ses habilitations permanentes sont conservées. Un badge encore attribué ou un véhicule présent bloque la clôture : une clôture ne vaut jamais restitution ni sortie.</p>
                  <label class="block text-sm font-semibold">Motif (obligatoire)<input v-model="reason" class="field" maxlength="200" required :data-testid="'motif-cloture-' + p.id"></label>
                  <button type="submit" class="site-btn site-btn-blue !min-h-11" :disabled="!reason.trim()" :data-testid="'confirmer-cloture-' + p.id">Confirmer la clôture</button>
                </form>
              </li>
            </ul>
            <nav v-if="participants && participants.meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="Pagination des participants" data-testid="pagination-participants">
              <span>Page {{ participants.meta.current_page }} sur {{ participants.meta.last_page }}</span>
              <span class="flex gap-2">
                <button type="button" class="site-btn site-btn-outline !min-h-11" :disabled="participants.meta.current_page <= 1" @click="loadParticipants(participants.meta.current_page - 1)">Précédent</button>
                <button type="button" class="site-btn site-btn-outline !min-h-11" :disabled="participants.meta.current_page >= participants.meta.last_page" @click="loadParticipants(participants.meta.current_page + 1)">Suivant</button>
              </span>
            </nav>
          </section>

          <!-- Groupes et cars -->
          <section v-else-if="tab === 'groupes'" role="tabpanel" aria-labelledby="onglet-act-groupes" class="space-y-3" data-testid="panneau-groupes">
            <p v-if="!selected.groups.length" class="text-slate-600">Aucun groupe pour cette activité.</p>
            <ul v-else class="divide-y rounded-xl border"><li v-for="g in selected.groups" :key="g.id" class="p-3"><strong class="block">{{ g.name }}</strong><small class="text-slate-600">{{ g.members }} membre{{ g.members > 1 ? 's' : '' }}<template v-if="g.bus_number"> · car {{ g.bus_number }}</template><template v-if="g.supervisor"> · encadreur : {{ g.supervisor }}</template></small></li></ul>
            <p class="text-sm text-slate-700">{{ selected.counts.vehicules }} véhicule{{ selected.counts.vehicules > 1 ? 's' : '' }} annoncé{{ selected.counts.vehicules > 1 ? 's' : '' }} ou présent{{ selected.counts.vehicules > 1 ? 's' : '' }}. Les véhicules se déclarent depuis la fiche d’un participant ; leur contrôle relève des postes.</p>
            <Link v-if="can?.groupes" href="/administration/groupes" class="site-btn site-btn-outline" data-testid="gerer-groupes">Gérer les groupes et cars</Link>
          </section>

          <!-- Accès et badges : synthèse, sans duplication des moteurs -->
          <section v-else-if="tab === 'acces'" role="tabpanel" aria-labelledby="onglet-act-acces" class="space-y-4" data-testid="panneau-acces">
            <p class="text-sm text-slate-700">Synthèse des droits d’accès <strong>liés aux participations</strong> de cette activité. Les habilitations permanentes appartiennent au profil permanent de la personne et ne sont ni listées ni modifiées ici.</p>
            <dl class="site-info">
              <div><dt>Habilitations d’activité effectives</dt><dd data-testid="eff-habilitations">{{ selected.counts.habilitations }}</dd></div>
              <div><dt>Badges attribués</dt><dd data-testid="eff-badges">{{ selected.counts.badges_attribues }}</dd></div>
              <div><dt>Badges restitués</dt><dd>{{ selected.counts.badges_restitues }}</dd></div>
            </dl>
            <ul v-if="selected.zones.length" class="divide-y rounded-xl border" data-testid="zones-activite"><li v-for="z in selected.zones" :key="z.id" class="flex justify-between gap-3 p-3"><span>{{ z.name }}</span><strong>{{ z.habilitations }}</strong></li></ul>
            <p class="flex flex-wrap gap-x-4 text-sm font-semibold">
              <Link v-if="can?.site" href="/administration/site" class="underline">Site et accès</Link>
              <Link v-if="can?.badges" href="/administration/badges" class="underline" data-testid="vers-badges">Badges et cartes</Link>
              <Link v-if="can?.zones" :href="`/administration/acces?event=${selected.event.id}`" class="underline" data-testid="vers-acces">Habilitations des participants</Link>
            </p>
          </section>

          <!-- Historique et clôture -->
          <section v-else role="tabpanel" aria-labelledby="onglet-act-historique" class="space-y-4" data-testid="panneau-historique">
            <div v-if="!selected.event.closed" class="space-y-2 rounded-xl border p-4">
              <h3 class="font-bold">Clôturer l’activité</h3>
              <p class="text-sm text-slate-700">Les présences, tablettes et opérations en attente sont vérifiées par le service existant. La clôture de l’activité ne retire aucune habilitation permanente et ne restitue aucun badge.</p>
              <button type="button" class="site-btn site-btn-outline" data-testid="cloturer-activite" @click="transition(selected.event.id, 'cloturer')">Clôturer l’activité</button>
            </div>
            <div v-else-if="selected.event.status === 'clos'" class="space-y-2 rounded-xl border p-4">
              <p class="text-sm text-slate-700">Activité clôturée. L’historique est conservé.</p>
              <button type="button" class="site-btn site-btn-outline" data-testid="archiver-activite" @click="transition(selected.event.id, 'archiver')">Archiver l’activité</button>
            </div>
            <p v-else class="text-sm text-slate-700">Activité archivée : consultation seule.</p>
            <Link :href="`/administration/donnees?entity=evenement&id=${selected.event.id}`" class="site-btn site-btn-outline" data-testid="analyser-suppression">Analyser une suppression</Link>
            <div v-if="can?.journal">
              <h3 class="font-bold">Journal de l’activité</h3>
              <p v-if="!selected.journal.length" class="text-sm text-slate-600">Aucune opération journalisée.</p>
              <ol v-else class="mt-2 space-y-1 text-sm" data-testid="journal-activite"><li v-for="j in selected.journal" :key="j.id" class="flex justify-between gap-3"><span>{{ actionLabels[j.action] ?? j.action }}</span><time class="text-slate-600">{{ displayDate(j.at) }}</time></li></ol>
              <Link :href="'/administration/journal?event=' + selected.event.id" class="mt-2 inline-block text-sm font-semibold underline">Voir le journal complet</Link>
            </div>
          </section>
        </aside>
      </div>
    </div>
  </AdminLayout>
</template>
