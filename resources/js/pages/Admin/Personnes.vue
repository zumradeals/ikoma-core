<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminIcon from '../../components/admin/AdminIcon.vue';

/**
 * Personnes et habilitations (lot P1, consultation) : registre unifié des identités et fiche en panneau.
 * L'identité reste celle de `persons` ; le statut institutionnel, les participations et les habilitations sont présentés séparément.
 * Aucune habilitation ne se crée ici : les actions renvoient vers les écrans existants.
 */
type Row = {
  id: string; full_name: string; organisation: string | null; status: string; holder_id: string | null; identifier: string | null; holder_status: string | null;
  institutional_category: string | null; category_label: string | null; category_color: string | null; has_photo: boolean;
  participations_actives: number | string; participations_total: number | string; habilitations_actives: number | string;
};
type Right = { id: string; origin: 'permanente' | 'activite'; zone: string; event: string; kind: string | null; reason: string | null; valid_from: string; valid_until: string; state: string; actions?: string[] };
type Zone = { id: string; name: string };
type Grant = {
  permanent: { holder_id: string; events: { id: string; name: string; starts_at: string; ends_at: string; zones: Zone[] }[] } | null;
  activites: { participation_id: string; event_id: string; event: string; valid_from: string; valid_until: string; zones: Zone[] }[]; max_exceptional_hours: number;
};
type Participation = { id: string; event_name: string; event_kind: string; category: string; status: string | null; valid_from: string; valid_until: string; withdrawn_at: string | null; closed_at: string | null; holder_id: string | null; source?: string | null };
type Assignment = { id: string; badge_id: string; badge_code: string; event_name: string; assigned_at: string; returned_at: string | null; cancelled_at: string | null };
type Profile = {
  person: { id: string; first_name: string | null; last_name: string | null; full_name: string; organisation: string | null; status: string; created_at: string; updated_at: string };
  holder: { id: string; identifier: string; position: string | null; quality: string | null; status: string; institutional_category: string | null; category_label: string | null; engagement_starts_on: string | null; engagement_ends_on: string | null; has_photo: boolean } | null;
  participations: Participation[]; assignments: Assignment[]; entitlements: { permanentes: Right[]; activites: Right[] };
  access: { zones: string[]; posts: { id: string; code: string; name: string }[]; next_expiry: string | null; effective: number };
  grant?: Grant;
  timeline: { at: string; label: string }[];
};
const props = defineProps<{
  query: string; filters: { statut: string; institutionnel: string; habilitations: string }; persons: Row[];
  meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null };
  kpis: { personnes: number; habilitations_actives: number; a_echeance: number; retirees_ou_expirees: number; jours_echeance: number };
  institutionalOptions: { code: string; label: string }[]; selected: Profile | null; can: { habilitations: boolean };
}>();

const search = ref(props.query);
const statut = ref(props.filters.statut);
const institutionnel = ref(props.filters.institutionnel);
const habilitations = ref(props.filters.habilitations);
const params = (extra: Record<string, string | number | null> = {}) => {
  const all: Record<string, string | number | null> = { q: search.value, statut: statut.value, institutionnel: institutionnel.value, habilitations: habilitations.value, ...extra };
  return Object.fromEntries(Object.entries(all).filter(([, v]) => v !== '' && v !== null)) as Record<string, string | number>;
};
function find() { router.get('/administration/personnes', params(), { preserveState: true, preserveScroll: true }); }
function goto(page: number) { router.get('/administration/personnes', params({ page }), { preserveState: true, preserveScroll: true }); }
function open(row: Row) { creating.value = false; ptab.value = 'identite'; router.get('/administration/personnes', params({ personne: row.id, page: props.meta.current_page }), { only: ['selected', 'flash'], preserveState: true, preserveScroll: true, replace: true }); }
function closePanel() { router.get('/administration/personnes', params({ page: props.meta.current_page }), { only: ['selected', 'flash'], preserveState: true, preserveScroll: true, replace: true }); }

// Fiche : tiroir sur écran étroit, panneau fixe sur grand écran.
const wide = ref(false);
let media: MediaQueryList | null = null;
const onMedia = () => { wide.value = media?.matches ?? false; };
onMounted(() => { if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') { media = window.matchMedia('(min-width: 1360px)'); onMedia(); media.addEventListener?.('change', onMedia); } });
onBeforeUnmount(() => media?.removeEventListener?.('change', onMedia));
const panel = ref<HTMLElement | null>(null);

const TABS = [
  { key: 'identite', label: 'Identité', icon: 'users' }, { key: 'habilitations', label: 'Habilitations', icon: 'shield' }, { key: 'badges', label: 'Badges', icon: 'qr' },
  { key: 'activites', label: 'Activités', icon: 'calendar' }, { key: 'historique', label: 'Historique', icon: 'clock' },
] as const;
type TabKey = typeof TABS[number]['key'];
const ptab = ref<TabKey>('identite');
function onTabKey(event: KeyboardEvent) {
  const index = TABS.findIndex(t => t.key === ptab.value);
  const next = event.key === 'ArrowRight' ? index + 1 : event.key === 'ArrowLeft' ? index - 1 : null;
  if (next === null) return;
  event.preventDefault();
  ptab.value = TABS[(next + TABS.length) % TABS.length].key;
  nextTick(() => (panel.value?.querySelector('[role="tab"][aria-selected="true"]') as HTMLElement | null)?.focus());
}

// --- Habilitations (P3) : les opérations passent par les routes et services EXISTANTS de chaque moteur ; l'interface ne remplace aucun contrôle serveur.
type Action = 'retirer' | 'suspendre' | 'reprendre';
const pending = ref<{ id: string; action: Action } | null>(null);
const reason = ref('');
const opErrors = ref<string[]>([]);
const actionLabel: Record<string, string> = { retirer: 'Retirer', suspendre: 'Suspendre', reprendre: 'Reprendre' };
const actionUrl = (r: Right, action: Action) => (r.origin === 'permanente' ? `/administration/habilitations/${r.id}/retirer` : `/administration/acces/habilitations/${r.id}/${action === 'retirer' ? 'retirer' : action === 'suspendre' ? 'suspendre' : 'reprendre'}`);
const onOpError = (errors: Record<string, string>) => { opErrors.value = Object.values(errors); };
function run(r: Right, action: Action) {
  opErrors.value = [];
  router.post(actionUrl(r, action), action === 'reprendre' ? {} : { reason: reason.value.trim() }, { preserveScroll: true, onSuccess: () => { pending.value = null; reason.value = ''; }, onError: onOpError });
}
function startAction(r: Right, action: Action) {
  opErrors.value = [];
  if (action === 'reprendre') { run(r, action); return; }
  pending.value = pending.value?.id === r.id && pending.value.action === action ? null : { id: r.id, action };
  reason.value = '';
}
const pad = (n: number) => String(n).padStart(2, '0');
const toLocalInput = (iso: string) => { const d = new Date(iso); return Number.isNaN(d.getTime()) ? '' : `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const toIso = (local: string) => new Date(local).toISOString();
const grantP = reactive({ open: false, event_id: '', zone_id: '', from: '', until: '' });
const permEvent = computed(() => props.selected?.grant?.permanent?.events.find(e => e.id === grantP.event_id) ?? null);
function toggleGrantP() {
  grantP.open = !grantP.open; opErrors.value = [];
  const first = props.selected?.grant?.permanent?.events[0];
  if (grantP.open && first && !grantP.event_id) pickEventP(first.id);
}
function pickEventP(id: string) {
  const e = props.selected?.grant?.permanent?.events.find(x => x.id === id);
  grantP.event_id = id; grantP.zone_id = e?.zones[0]?.id ?? ''; grantP.from = e ? toLocalInput(e.starts_at) : ''; grantP.until = e ? toLocalInput(e.ends_at) : '';
}
function submitGrantP() {
  const holder = props.selected?.grant?.permanent;
  if (!holder) return;
  opErrors.value = [];
  router.post('/administration/habilitations', { holder_id: holder.holder_id, event_id: grantP.event_id, zone_id: grantP.zone_id, valid_from: toIso(grantP.from), valid_until: toIso(grantP.until) },
    { preserveScroll: true, onSuccess: () => { grantP.open = false; }, onError: onOpError });
}
const grantA = reactive({ open: false, participation_id: '', zone_id: '', kind: 'standard', reason: '', from: '', until: '' });
const partA = computed(() => props.selected?.grant?.activites.find(a => a.participation_id === grantA.participation_id) ?? null);
function pickParticipation(id: string) {
  const a = props.selected?.grant?.activites.find(x => x.participation_id === id);
  grantA.participation_id = id; grantA.zone_id = a?.zones[0]?.id ?? ''; grantA.from = a ? toLocalInput(a.valid_from) : ''; grantA.until = a ? toLocalInput(a.valid_until) : '';
}
function toggleGrantA() {
  grantA.open = !grantA.open; opErrors.value = [];
  const first = props.selected?.grant?.activites[0];
  if (grantA.open && first && !grantA.participation_id) pickParticipation(first.participation_id);
}
function submitGrantA() {
  opErrors.value = [];
  router.post('/administration/acces/habilitations', { visitor_id: grantA.participation_id, zone_id: grantA.zone_id, kind: grantA.kind, reason: grantA.reason.trim() || null, valid_from: toIso(grantA.from), valid_until: toIso(grantA.until) },
    { preserveScroll: true, onSuccess: () => { grantA.open = false; grantA.reason = ''; }, onError: onOpError });
}
watch(() => props.selected?.person.id, () => { pending.value = null; opErrors.value = []; grantP.open = false; grantA.open = false; grantP.event_id = ''; grantA.participation_id = ''; });

const day = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('fr-FR') : '—');
const dateTime = (iso: string) => new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w.charAt(0).toUpperCase()).join('');
const n = (value: number | string) => Number(value);
const kinds: Record<string, string> = { evenement: 'Événement', stage: 'Stage', visite: 'Visite' };
const stateLabel: Record<string, string> = {
  active: 'Active', suspendue: 'Suspendue', retiree: 'Retirée', expiree: 'Expirée', a_venir: 'À venir', participation_close: 'Participation clôturée', activite_close: 'Activité terminée', profil_clos: 'Profil clos',
};
const stateClass = (s: string) => (s === 'active' ? 'bg-green-100 text-green-900' : s === 'suspendue' || s === 'a_venir' ? 'bg-amber-100 text-amber-900' : 'bg-slate-200 text-slate-700');
const participationState = (p: Participation) => (p.closed_at ? 'Clôturée' : p.withdrawn_at ? 'Retirée' : ({ attendu: 'Attendue', present: 'Présente', sorti: 'Sortie', refuse: 'Accès refusé' } as Record<string, string>)[p.status ?? 'attendu'] ?? 'En cours');
const assignmentState = (a: Assignment) => (a.cancelled_at ? 'Annulé' : a.returned_at ? 'Restitué le ' + day(a.returned_at) : 'En cours');

const creating = ref(false);
const form = useForm({ first_name: '', last_name: '', full_name: '', organisation: '', homonym_confirmed: false, position: '', quality: '', institutional_category: '', engagement_starts_on: '', engagement_ends_on: '' });
const needsConfirmation = () => Object.values(form.errors).some(message => String(message).includes('de même nom'));
// Nouvelle personne (cas B) : la recherche précède la création ; avec « personnel permanent », l'identité et le profil s'enregistrent ensemble.
const permanentToo = ref(false);
function create() {
  if (permanentToo.value) form.transform(d => ({ ...d, confirm_homonym: d.homonym_confirmed })).post('/administration/titulaires', { onSuccess: () => { form.reset(); creating.value = false; } });
  else form.post('/administration/personnes', { onSuccess: () => form.reset() });
}
type Match = { id: string; full_name: string; organisation: string | null; participations: number | string; permanent: boolean | number | string };
const matches = ref<Match[]>([]);
let lookupTimer: ReturnType<typeof setTimeout> | null = null;
watch(() => [form.first_name, form.last_name, form.full_name], () => {
  if (lookupTimer) clearTimeout(lookupTimer);
  const q = `${form.first_name} ${form.last_name}`.trim() || form.full_name.trim();
  if (q.length < 2) { matches.value = []; return; }
  lookupTimer = setTimeout(async () => {
    try {
      const response = await fetch('/administration/personnes/recherche?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      matches.value = response.ok ? ((await response.json()).results ?? []) : [];
    } catch { matches.value = []; }
  }, 300);
});
// Sélection explicite d'une identité existante : on ouvre sa fiche, où l'on ajoute le profil permanent (aucune nouvelle identité).
function useExisting(match: Match) { creating.value = false; permanentToo.value = false; matches.value = []; open({ id: match.id } as Row); }

// Profil permanent d'une personne existante (cas A) et correction d'identité : depuis la fiche.
const addProfile = ref(false);
const profile = useForm({ person_id: '', position: '', quality: '', institutional_category: '', engagement_starts_on: '', engagement_ends_on: '' });
function submitProfile() {
  profile.person_id = props.selected?.person.id ?? '';
  profile.post('/administration/titulaires', { preserveScroll: true, onSuccess: () => { addProfile.value = false; profile.reset(); } });
}
const correcting = ref(false);
const correction = useForm({ first_name: '', last_name: '', full_name: '', organisation: '', homonym_confirmed: false });
function syncCorrection() {
  const person = props.selected?.person;
  correction.first_name = person?.first_name ?? ''; correction.last_name = person?.last_name ?? ''; correction.full_name = person?.full_name ?? ''; correction.organisation = person?.organisation ?? '';
  correction.homonym_confirmed = false;
}
watch(() => props.selected?.person.id, () => { correcting.value = false; addProfile.value = false; syncCorrection(); }, { immediate: true });
const correctionNeedsConfirmation = () => Object.values(correction.errors).some(message => String(message).includes('de même nom'));
function submitCorrection() { correction.put('/administration/personnes/' + props.selected?.person.id, { preserveScroll: true, onSuccess: () => { correcting.value = false; } }); }
const drawerOpen = computed(() => creating.value || (props.selected !== null && !wide.value));
function closeAll() { creating.value = false; if (props.selected) closePanel(); }
</script>

<template>
  <AdminLayout active="personnes">
    <div class="flex max-w-[1500px] flex-col gap-5" data-testid="page-personnes">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 flex-1 basis-96 items-start gap-3">
          <span class="mt-1 text-blue-700" aria-hidden="true"><AdminIcon name="users" :size="34" /></span>
          <div>
            <h1 class="text-display font-bold" data-testid="titre-personnes">Personnes et habilitations</h1>
            <p class="max-w-2xl text-slate-700">Registre des personnes, de leur statut institutionnel, de leurs participations et de leurs habilitations d’accès. Une personne a une identité unique ; deux personnes ne sont jamais fusionnées sur le seul nom.</p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <Link href="/administration/imports" class="site-btn site-btn-outline" data-testid="importer-liste"><AdminIcon name="arrow" :size="18" />Importer (CSV)</Link>
          <button type="button" class="site-btn site-btn-accent" :aria-expanded="creating" data-testid="ajouter-personne" @click="creating = !creating"><AdminIcon name="plus" :size="18" />Ajouter une personne</button>
        </div>
      </header>

      <dl class="grid grid-cols-2 gap-3 xl:grid-cols-4" data-testid="indicateurs-personnes">
        <div class="site-kpi bg-sky-50"><span class="site-kpi-icon text-sky-700"><AdminIcon name="users" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-personnes">{{ kpis.personnes }}</dd><dt class="font-semibold text-slate-800">Personnes enregistrées</dt></div></div>
        <div class="site-kpi bg-emerald-50"><span class="site-kpi-icon text-emerald-700"><AdminIcon name="shield" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-actives">{{ kpis.habilitations_actives }}</dd><dt class="font-semibold text-slate-800">Habilitations actives</dt></div></div>
        <div class="site-kpi bg-amber-50"><span class="site-kpi-icon text-amber-700"><AdminIcon name="calendar" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-echeance">{{ kpis.a_echeance }}</dd><dt class="font-semibold text-slate-800">À échéance (≤ {{ kpis.jours_echeance }} j)</dt></div></div>
        <div class="site-kpi bg-rose-50"><span class="site-kpi-icon text-rose-700"><AdminIcon name="alert" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-terminees">{{ kpis.retirees_ou_expirees }}</dd><dt class="font-semibold text-slate-800">Retirées ou expirées</dt></div></div>
      </dl>

      <div class="pers-grid">
        <!-- Registre -->
        <section class="site-card" aria-labelledby="registre-titre" data-testid="registre-personnes">
          <header class="site-card-head"><h2 id="registre-titre"><AdminIcon name="list" :size="22" />Registre des personnes ({{ meta.total }})</h2></header>
          <form class="space-y-3 px-4 pb-4" role="search" aria-label="Rechercher une personne" @submit.prevent="find">
            <label class="block"><span class="sr-only">Rechercher</span><input v-model="search" class="field !mt-0" maxlength="160" placeholder="Rechercher (nom, organisation, identifiant)" data-testid="recherche-personne"></label>
            <div class="pers-filters">
              <label class="block"><span class="sr-only">Statut institutionnel</span>
                <select v-model="institutionnel" class="field !mt-0" data-testid="filtre-institutionnel" @change="find"><option value="">Tous statuts institutionnels</option><option value="aucun">Sans statut institutionnel</option><option v-for="c in institutionalOptions" :key="c.code" :value="c.code">{{ c.label }}</option></select></label>
              <label class="block"><span class="sr-only">Habilitations</span>
                <select v-model="habilitations" class="field !mt-0" data-testid="filtre-habilitations" @change="find"><option value="">Avec ou sans habilitation</option><option value="avec">Avec habilitation active</option><option value="sans">Sans habilitation active</option></select></label>
              <label class="block"><span class="sr-only">Statut de la personne</span>
                <select v-model="statut" class="field !mt-0" data-testid="filtre-statut" @change="find"><option value="">Tous statuts</option><option value="actif">Actives</option><option value="archive">Archivées</option></select></label>
            </div>
            <button type="submit" class="site-btn site-btn-blue" data-testid="lancer-recherche">Rechercher</button>
          </form>
          <p v-if="!persons.length" class="px-4 pb-4" data-testid="aucune-personne">Aucune personne ne correspond.</p>
          <ul v-else class="site-table" data-testid="liste-personnes">
            <li class="site-row site-row-head pers-row" aria-hidden="true"><span>Personne</span><span>Statut institutionnel</span><span>Rattachements</span></li>
            <li v-for="row in persons" :key="row.id" class="site-row pers-row" :class="selected?.person.id === row.id ? 'bg-blue-50' : ''" :data-testid="'personne-' + row.id">
              <button type="button" class="pers-name" :aria-expanded="selected?.person.id === row.id" :aria-label="'Consulter la fiche de ' + row.full_name" :data-testid="'selectionner-personne-' + row.id" @click="open(row)">
                <span class="pers-avatar" aria-hidden="true"><img v-if="row.has_photo && row.holder_id" :src="'/administration/titulaires/' + row.holder_id + '/portrait'" alt="" loading="lazy"><template v-else>{{ initials(row.full_name) }}</template></span>
                <span class="min-w-0 text-left"><strong class="block">{{ row.full_name }}</strong><small class="block text-slate-600">{{ row.identifier ?? row.organisation ?? '—' }}</small></span>
              </button>
              <span class="text-sm" :data-testid="'institutionnel-' + row.id"><span v-if="row.category_label" class="rounded-full border px-2 py-0.5 text-xs font-semibold" :style="row.category_color ? { borderColor: row.category_color } : {}">{{ row.category_label }}</span><span v-else class="text-slate-500">—</span></span>
              <span class="text-sm"><span :data-testid="'synthese-' + row.id" class="block">{{ n(row.participations_actives) }} participation<template v-if="n(row.participations_actives) > 1">s</template> · {{ n(row.habilitations_actives) }} habilitation<template v-if="n(row.habilitations_actives) > 1">s</template></span>
                <span class="inline-flex items-center gap-1.5 font-semibold" :class="row.status === 'actif' ? 'text-green-800' : 'text-slate-600'"><span class="h-2.5 w-2.5 rounded-full" :class="row.status === 'actif' ? 'bg-green-600' : 'bg-slate-400'" aria-hidden="true" />{{ row.status === 'actif' ? 'Actif' : 'Archivée' }}</span></span>
            </li>
          </ul>
          <nav v-if="meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm" aria-label="Pagination du registre" data-testid="pagination">
            <span>{{ meta.from }} – {{ meta.to }} sur {{ meta.total }}</span>
            <span class="flex gap-2">
              <button type="button" class="site-btn site-btn-outline !min-h-11" :disabled="meta.current_page <= 1" data-testid="page-precedente" @click="goto(meta.current_page - 1)">Précédent</button>
              <button type="button" class="site-btn site-btn-outline !min-h-11" :disabled="meta.current_page >= meta.last_page" data-testid="page-suivante" @click="goto(meta.current_page + 1)">Suivant</button>
            </span>
          </nav>
        </section>

        <p v-if="!selected && !creating" class="pers-placeholder site-card p-6 text-slate-600" data-testid="fiche-vide">Sélectionnez une personne pour consulter sa fiche : identité, habilitations, badges, activités et historique.</p>

        <button v-if="drawerOpen" type="button" class="pers-backdrop" aria-label="Fermer le panneau" tabindex="-1" data-testid="fond-panneau" @click="closeAll" />

        <!-- Création : la recherche précède toute création -->
        <aside v-if="creating" class="pers-fiche" role="dialog" aria-modal="true" aria-label="Ajouter une personne" data-testid="panneau-creation-personne" @keydown.esc="closeAll">
          <div class="flex items-start justify-between gap-3"><h2 class="text-heading font-bold">Ajouter une personne</h2><button type="button" class="btn-soft !min-h-11 !px-3 !py-1 text-sm" data-testid="fermer-creation" @click="creating = false">Fermer</button></div>
          <p class="text-sm text-slate-700">Vérifiez d’abord la recherche : si la personne existe, ouvrez sa fiche plutôt que d’en créer une seconde. Aucun rapprochement automatique n’est fait sur le nom.</p>
          <form class="space-y-4" aria-label="Enregistrer une personne" data-testid="form-personne" @submit.prevent="create">
            <label class="block">Prénom<input v-model="form.first_name" class="field" maxlength="100"></label>
            <label class="block">Nom<input v-model="form.last_name" class="field" maxlength="100"></label>
            <label class="block">Ou nom complet seul<input v-model="form.full_name" class="field" maxlength="160"></label>
            <label class="block">Organisation (facultatif)<input v-model="form.organisation" class="field" maxlength="160"></label>
            <section v-if="matches.length" class="rounded-xl border-2 border-amber-500 bg-amber-50 p-3" aria-labelledby="proches-titre" data-testid="personnes-proches">
              <h3 id="proches-titre" class="font-bold">Personnes déjà enregistrées portant un nom proche</h3>
              <p class="text-sm">Si c’est la même personne, utilisez-la : aucune nouvelle identité n’est créée.</p>
              <ul class="mt-2 space-y-2"><li v-for="m in matches" :key="m.id" class="flex flex-wrap items-center justify-between gap-2"><span><strong>{{ m.full_name }}</strong><small class="block text-slate-700">{{ m.organisation ?? '—' }}<template v-if="m.permanent"> · profil permanent existant</template></small></span><button type="button" class="site-btn site-btn-outline !min-h-11" :data-testid="'utiliser-personne-' + m.id" @click="useExisting(m)">Utiliser cette personne</button></li></ul>
            </section>
            <label class="flex min-h-12 items-center gap-3" data-testid="creer-permanent"><input v-model="permanentToo" type="checkbox" class="h-5 w-5">Cette personne est du personnel permanent (profil permanent créé avec l’identité)</label>
            <fieldset v-if="permanentToo" class="space-y-3" data-testid="profil-nouvelle-personne">
              <legend class="font-semibold">Profil permanent</legend>
              <label class="block">Fonction<input v-model="form.position" class="field" maxlength="160"></label>
              <label class="block">Qualité<input v-model="form.quality" class="field" maxlength="160"></label>
              <label class="block">Statut institutionnel<select v-model="form.institutional_category" class="field"><option value="">Non renseigné</option><option v-for="c in institutionalOptions" :key="c.code" :value="c.code">{{ c.label }}</option></select></label>
              <label class="block">Début d’engagement<input v-model="form.engagement_starts_on" type="date" class="field"></label>
              <label class="block">Fin d’engagement<input v-model="form.engagement_ends_on" type="date" class="field"></label>
            </fieldset>
            <label v-if="needsConfirmation()" class="flex min-h-14 items-center gap-3" data-testid="confirmer-homonyme"><input v-model="form.homonym_confirmed" type="checkbox" class="h-6 w-6">Je confirme qu’il s’agit d’une autre personne que celle déjà enregistrée.</label>
            <p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
            <button type="submit" class="btn-main" :disabled="form.processing">Enregistrer la personne</button>
          </form>
        </aside>

        <!-- Fiche individuelle -->
        <aside v-else-if="selected" ref="panel" class="pers-fiche" :role="wide ? 'region' : 'dialog'" :aria-modal="wide ? undefined : 'true'" :aria-label="'Fiche de ' + selected.person.full_name" data-testid="fiche-personne" @keydown.esc="closeAll">
          <div class="flex items-start gap-4">
            <span class="pers-avatar pers-avatar-lg" aria-hidden="true"><img v-if="selected.holder?.has_photo" :src="'/administration/titulaires/' + selected.holder.id + '/portrait'" alt="" loading="lazy"><template v-else>{{ initials(selected.person.full_name) }}</template></span>
            <div class="min-w-0 grow space-y-1">
              <h2 class="text-heading font-bold" data-testid="nom-personne">{{ selected.person.full_name }}
                <span class="ml-1 rounded-full px-2 py-0.5 align-middle text-xs font-bold" :class="selected.person.status === 'actif' ? 'bg-green-100 text-green-900' : 'bg-slate-200 text-slate-700'">{{ selected.person.status === 'actif' ? 'Actif' : 'Archivée' }}</span></h2>
              <p v-if="selected.holder" class="text-sm"><span class="text-slate-600">Identifiant :</span> <strong data-testid="fiche-identifiant">{{ selected.holder.identifier }}</strong></p>
              <p v-if="selected.holder?.category_label" class="text-sm"><span class="text-slate-600">Statut institutionnel :</span> <strong data-testid="fiche-institutionnel">{{ selected.holder.category_label }}</strong></p>
              <p v-if="selected.holder?.position" class="text-sm"><span class="text-slate-600">Fonction :</span> {{ selected.holder.position }}</p>
              <p v-if="selected.person.organisation" class="text-sm"><span class="text-slate-600">Organisation :</span> {{ selected.person.organisation }}</p>
            </div>
            <div class="flex shrink-0 flex-col items-end gap-2">
              <button type="button" class="btn-soft !min-h-11 !px-3 !py-1 text-sm" data-testid="fermer-fiche" @click="closeAll">Fermer</button>
              <Link :href="'/administration/personnes/' + selected.person.id" class="text-sm font-semibold text-blue-800 underline" :data-testid="'ouvrir-personne-' + selected.person.id">Fiche complète</Link>
            </div>
          </div>
          <p class="text-sm text-slate-600">Enregistrée le {{ day(selected.person.created_at) }} · mise à jour le {{ day(selected.person.updated_at) }}<template v-if="selected.holder?.engagement_ends_on"> · fin d’engagement le {{ day(selected.holder.engagement_ends_on) }}</template></p>

          <div role="tablist" aria-label="Sections de la fiche" class="site-tabs" @keydown="onTabKey">
            <button v-for="t in TABS" :id="'ptab-' + t.key" :key="t.key" type="button" role="tab" class="site-tab pers-tab" :aria-selected="ptab === t.key" :aria-controls="'ppanneau-' + t.key" :tabindex="ptab === t.key ? 0 : -1" :data-testid="'ptab-' + t.key" @click="ptab = t.key"><AdminIcon :name="t.icon" :size="18" class="pers-tab-icon" /><span>{{ t.label }}</span></button>
          </div>

          <div v-if="ptab === 'identite'" id="ppanneau-identite" role="tabpanel" aria-labelledby="ptab-identite" class="space-y-4" data-testid="onglet-identite">
            <p><button type="button" class="site-btn site-btn-outline !min-h-11" :aria-expanded="correcting" data-testid="corriger-identite" @click="correcting = !correcting">Corriger l’identité</button></p>
            <form v-if="correcting" class="space-y-3 rounded-xl border bg-slate-50 p-4" aria-label="Corriger l’identité" data-testid="form-correction" @submit.prevent="submitCorrection">
              <p class="text-sm text-slate-700">La correction s’applique à l’identité de la personne et à son profil permanent actif. Les participations, journaux, cartes et badges déjà émis ne sont pas réécrits.</p>
              <label class="block">Prénom<input v-model="correction.first_name" class="field" maxlength="100"></label>
              <label class="block">Nom<input v-model="correction.last_name" class="field" maxlength="100"></label>
              <label class="block">Ou nom complet seul<input v-model="correction.full_name" class="field" maxlength="160"></label>
              <label class="block">Organisation<input v-model="correction.organisation" class="field" maxlength="160"></label>
              <label v-if="correctionNeedsConfirmation()" class="flex min-h-12 items-center gap-3" data-testid="confirmer-homonyme-correction"><input v-model="correction.homonym_confirmed" type="checkbox" class="h-5 w-5">Je confirme qu’il s’agit d’une autre personne que celle déjà enregistrée.</label>
              <p v-for="error in correction.errors" :key="error" role="alert">{{ error }}</p>
              <button type="submit" class="btn-main" :disabled="correction.processing" data-testid="enregistrer-correction">Enregistrer la correction</button>
            </form>
            <dl class="site-info">
              <div><dt>Prénom</dt><dd>{{ selected.person.first_name ?? '—' }}</dd></div>
              <div><dt>Nom</dt><dd>{{ selected.person.last_name ?? '—' }}</dd></div>
              <div><dt>Nom complet</dt><dd>{{ selected.person.full_name }}</dd></div>
              <div><dt>Organisation</dt><dd>{{ selected.person.organisation ?? '—' }}</dd></div>
            </dl>
            <h3 class="font-bold">Profil permanent</h3>
            <div v-if="!selected.holder" class="space-y-3" data-testid="sans-profil-permanent">
              <p>Cette personne n’a pas de profil permanent. Son statut institutionnel n’est donc pas renseigné.</p>
              <p><button type="button" class="site-btn site-btn-accent !min-h-11" :aria-expanded="addProfile" data-testid="ajouter-profil-permanent" @click="addProfile = !addProfile">Ajouter un profil permanent</button></p>
              <form v-if="addProfile" class="space-y-3 rounded-xl border bg-slate-50 p-4" aria-label="Ajouter un profil permanent" data-testid="form-profil-permanent" @submit.prevent="submitProfile">
                <p class="text-sm text-slate-700">Le profil se rattache à cette identité : aucune nouvelle personne n’est créée, et ses participations et habilitations ne changent pas.</p>
                <label class="block">Fonction<input v-model="profile.position" class="field" maxlength="160"></label>
                <label class="block">Qualité<input v-model="profile.quality" class="field" maxlength="160"></label>
                <label class="block">Statut institutionnel<select v-model="profile.institutional_category" class="field" data-testid="profil-categorie"><option value="">Non renseigné</option><option v-for="c in institutionalOptions" :key="c.code" :value="c.code">{{ c.label }}</option></select></label>
                <label class="block">Début d’engagement<input v-model="profile.engagement_starts_on" type="date" class="field"></label>
                <label class="block">Fin d’engagement<input v-model="profile.engagement_ends_on" type="date" class="field"></label>
                <p v-for="error in profile.errors" :key="error" role="alert">{{ error }}</p>
                <button type="submit" class="btn-main" :disabled="profile.processing" data-testid="enregistrer-profil-permanent">Enregistrer le profil permanent</button>
              </form>
            </div>
            <dl v-else class="site-info" data-testid="profil-permanent">
              <div><dt>Identifiant</dt><dd>{{ selected.holder.identifier }}</dd></div>
              <div><dt>Statut institutionnel</dt><dd>{{ selected.holder.category_label ?? 'Non renseigné' }}</dd></div>
              <div><dt>Fonction</dt><dd>{{ selected.holder.position ?? '—' }}</dd></div>
              <div><dt>Qualité</dt><dd>{{ selected.holder.quality ?? '—' }}</dd></div>
              <div><dt>Engagement</dt><dd>{{ day(selected.holder.engagement_starts_on) }} → {{ day(selected.holder.engagement_ends_on) }}</dd></div>
              <div><dt>Profil</dt><dd>{{ selected.holder.status === 'actif' ? 'Actif' : 'Clos' }}</dd></div>
            </dl>
          </div>

          <div v-else-if="ptab === 'habilitations'" id="ppanneau-habilitations" role="tabpanel" aria-labelledby="ptab-habilitations" class="space-y-4" data-testid="onglet-habilitations">
            <section aria-labelledby="hab-perm" data-testid="habilitations-permanentes">
              <h3 id="hab-perm" class="font-bold">Habilitations permanentes ({{ selected.entitlements.permanentes.length }})</h3>
              <p v-if="!selected.entitlements.permanentes.length" class="text-sm text-slate-600">Aucune habilitation permanente.</p>
              <ul v-else class="divide-y">
                <li v-for="r in selected.entitlements.permanentes" :key="r.id" class="space-y-2 py-2" :data-testid="'habilitation-' + r.id">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="min-w-0"><strong>{{ r.zone }}</strong><span class="block text-sm text-slate-600">Origine : permanente · {{ r.event }} · {{ day(r.valid_from) }} → {{ day(r.valid_until) }}</span></span>
                    <span class="rounded-md px-2 py-0.5 text-xs font-bold" :class="stateClass(r.state)">{{ stateLabel[r.state] ?? r.state }}</span>
                  </div>
                  <p v-if="can.habilitations && r.actions?.length" class="flex flex-wrap gap-2">
                    <button v-for="a in r.actions" :key="a" type="button" class="site-btn site-btn-outline !min-h-11" :data-testid="'action-' + r.id + '-' + a" @click="startAction(r, a as Action)">{{ actionLabel[a] }}</button>
                  </p>
                  <form v-if="pending && pending.id === r.id" class="space-y-2 rounded-lg border bg-slate-50 p-3" :aria-label="actionLabel[pending.action] + ' l’habilitation'" @submit.prevent="run(r, pending.action)">
                    <label class="block text-sm">Motif (obligatoire, 200 caractères au plus)<input v-model="reason" class="field" maxlength="200" required :data-testid="'motif-' + r.id"></label>
                    <button type="submit" class="btn-main !min-h-11 !text-sm" :data-testid="'confirmer-' + r.id">Confirmer : {{ actionLabel[pending.action].toLowerCase() }}</button>
                  </form>
                </li>
              </ul>
              <p v-if="can.habilitations && selected.grant?.permanent" class="pt-1"><button type="button" class="site-btn site-btn-accent !min-h-11" :aria-expanded="grantP.open" data-testid="accorder-permanente" @click="toggleGrantP">Accorder une habilitation permanente</button></p>
              <p v-else-if="can.habilitations && !selected.holder" class="text-sm text-slate-600" data-testid="sans-profil-habilitation">Aucun profil permanent : ajoutez-en un depuis l’onglet Identité pour accorder des habilitations permanentes.</p>
              <form v-if="grantP.open && selected.grant?.permanent" class="space-y-3 rounded-xl border bg-slate-50 p-4" aria-label="Accorder une habilitation permanente" data-testid="form-accorder-permanente" @submit.prevent="submitGrantP">
                <label class="block">Activité<select :value="grantP.event_id" class="field" data-testid="perm-evenement" required @change="pickEventP(($event.target as HTMLSelectElement).value)"><option v-for="e in selected.grant.permanent.events" :key="e.id" :value="e.id">{{ e.name }}</option></select></label>
                <label class="block">Zone<select v-model="grantP.zone_id" class="field" data-testid="perm-zone" required><option v-for="z in permEvent?.zones ?? []" :key="z.id" :value="z.id">{{ z.name }}</option></select></label>
                <label class="block">Début<input v-model="grantP.from" type="datetime-local" class="field" required data-testid="perm-debut"></label>
                <label class="block">Fin<input v-model="grantP.until" type="datetime-local" class="field" required data-testid="perm-fin"></label>
                <button type="submit" class="btn-main" data-testid="perm-enregistrer">Accorder l’habilitation</button>
              </form>
            </section>
            <section aria-labelledby="hab-act" data-testid="habilitations-activites">
              <h3 id="hab-act" class="font-bold">Habilitations liées aux activités ({{ selected.entitlements.activites.length }})</h3>
              <p v-if="!selected.entitlements.activites.length" class="text-sm text-slate-600">Aucune habilitation liée à une activité.</p>
              <ul v-else class="divide-y">
                <li v-for="r in selected.entitlements.activites" :key="r.id" class="space-y-2 py-2" :data-testid="'habilitation-' + r.id">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="min-w-0"><strong>{{ r.zone }}</strong><span class="block text-sm text-slate-600">Origine : activité « {{ r.event }} » · {{ r.kind === 'exceptionnelle' ? 'exceptionnelle' : 'standard' }} · {{ day(r.valid_from) }} → {{ day(r.valid_until) }}</span></span>
                    <span class="rounded-md px-2 py-0.5 text-xs font-bold" :class="stateClass(r.state)">{{ stateLabel[r.state] ?? r.state }}</span>
                  </div>
                  <p v-if="can.habilitations && r.actions?.length" class="flex flex-wrap gap-2">
                    <button v-for="a in r.actions" :key="a" type="button" class="site-btn site-btn-outline !min-h-11" :data-testid="'action-' + r.id + '-' + a" @click="startAction(r, a as Action)">{{ actionLabel[a] }}</button>
                  </p>
                  <form v-if="pending && pending.id === r.id" class="space-y-2 rounded-lg border bg-slate-50 p-3" :aria-label="actionLabel[pending.action] + ' l’habilitation'" @submit.prevent="run(r, pending.action)">
                    <label class="block text-sm">Motif (obligatoire, 200 caractères au plus)<input v-model="reason" class="field" maxlength="200" required :data-testid="'motif-' + r.id"></label>
                    <button type="submit" class="btn-main !min-h-11 !text-sm" :data-testid="'confirmer-' + r.id">Confirmer : {{ actionLabel[pending.action].toLowerCase() }}</button>
                  </form>
                </li>
              </ul>
              <p v-if="can.habilitations && selected.grant?.activites.length" class="pt-1"><button type="button" class="site-btn site-btn-accent !min-h-11" :aria-expanded="grantA.open" data-testid="accorder-activite" @click="toggleGrantA">Accorder une habilitation d’activité</button></p>
              <p v-else-if="can.habilitations" class="text-sm text-slate-600" data-testid="sans-participation-ouverte">Aucune participation ouverte : une habilitation d’activité se rattache à une participation en cours (Activités).</p>
              <form v-if="grantA.open && selected.grant?.activites.length" class="space-y-3 rounded-xl border bg-slate-50 p-4" aria-label="Accorder une habilitation d’activité" data-testid="form-accorder-activite" @submit.prevent="submitGrantA">
                <label class="block">Participation<select :value="grantA.participation_id" class="field" data-testid="act-participation" required @change="pickParticipation(($event.target as HTMLSelectElement).value)"><option v-for="a in selected.grant.activites" :key="a.participation_id" :value="a.participation_id">{{ a.event }}</option></select></label>
                <label class="block">Zone<select v-model="grantA.zone_id" class="field" data-testid="act-zone" required><option v-for="z in partA?.zones ?? []" :key="z.id" :value="z.id">{{ z.name }}</option></select></label>
                <label class="block">Type<select v-model="grantA.kind" class="field" data-testid="act-type"><option value="standard">Standard</option><option value="exceptionnelle">Exceptionnelle (limitée à {{ selected.grant.max_exceptional_hours }} h, motif obligatoire)</option></select></label>
                <label v-if="grantA.kind === 'exceptionnelle'" class="block">Motif<input v-model="grantA.reason" class="field" maxlength="200" required data-testid="act-motif"></label>
                <label class="block">Début<input v-model="grantA.from" type="datetime-local" class="field" required data-testid="act-debut"></label>
                <label class="block">Fin<input v-model="grantA.until" type="datetime-local" class="field" required data-testid="act-fin"></label>
                <button type="submit" class="btn-main" data-testid="act-enregistrer">Accorder l’habilitation</button>
              </form>
            </section>
            <section class="rounded-xl border bg-slate-50 p-4" aria-labelledby="resume-acces" data-testid="resume-acces">
              <h3 id="resume-acces" class="font-bold">Résumé des accès</h3>
              <p v-if="!selected.access.effective" class="text-sm text-slate-600">Aucun accès effectif aujourd’hui.</p>
              <dl v-else class="mt-2 space-y-1 text-sm">
                <div><dt class="inline font-semibold">Zones autorisées : </dt><dd class="inline" data-testid="resume-zones-personne">{{ selected.access.zones.join(' ; ') }}</dd></div>
                <div><dt class="inline font-semibold">Postes concernés : </dt><dd class="inline" data-testid="resume-postes-personne">{{ selected.access.posts.length ? selected.access.posts.map(p => p.name).join(', ') : 'aucun poste associé à ces zones' }}</dd></div>
                <div><dt class="inline font-semibold">Prochaine échéance : </dt><dd class="inline">{{ day(selected.access.next_expiry) }}</dd></div>
              </dl>
            </section>
            <div v-if="opErrors.length" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-3" data-testid="erreurs-habilitation"><p v-for="e in opErrors" :key="e">{{ e }}</p></div>
            <p v-if="can.habilitations" class="flex flex-wrap gap-3 text-sm">
              <Link href="/administration/habilitations" class="site-btn site-btn-outline !min-h-11" data-testid="gerer-permanentes">Habilitations par zone</Link>
              <Link href="/administration/acces" class="site-btn site-btn-outline !min-h-11" data-testid="gerer-participants">Habilitations des participants</Link>
            </p>
          </div>

          <div v-else-if="ptab === 'badges'" id="ppanneau-badges" role="tabpanel" aria-labelledby="ptab-badges" class="space-y-3" data-testid="onglet-badges">
            <p class="text-sm text-slate-600">Le badge est un support physique d’identification, pas une habilitation. Son cycle de vie se gère dans « Badges et cartes ».</p>
            <ul v-if="selected.assignments.length" class="space-y-2" data-testid="liste-attributions">
              <li v-for="a in selected.assignments" :key="a.id" class="rounded-xl border p-3"><Link :href="'/administration/badges/' + a.badge_id" class="font-bold">{{ a.badge_code }}</Link> · {{ a.event_name }} · {{ assignmentState(a) }}</li>
            </ul>
            <p v-else data-testid="aucun-badge">Aucun badge n’a été attribué à cette personne.</p>
          </div>

          <div v-else-if="ptab === 'activites'" id="ppanneau-activites" role="tabpanel" aria-labelledby="ptab-activites" class="space-y-3" data-testid="onglet-activites">
            <p class="text-sm text-slate-600">Participations en consultation. La catégorie d’une participation est propre à son activité ; elle ne désigne pas un statut institutionnel.</p>
            <ul v-if="selected.participations.length" class="space-y-2" data-testid="liste-participations">
              <li v-for="p in selected.participations" :key="p.id" class="rounded-xl border p-3" :data-testid="'participation-' + p.id">
                <p class="font-bold">{{ p.event_name }} <span class="text-sm font-normal text-slate-600">({{ kinds[p.event_kind] ?? p.event_kind }})</span></p>
                <p class="text-sm text-slate-600">Catégorie de l’activité : {{ p.category }} · {{ day(p.valid_from) }} → {{ day(p.valid_until) }} · {{ participationState(p) }}</p>
              </li>
            </ul>
            <p v-else data-testid="aucune-participation">Cette personne ne participe à aucune activité.</p>
          </div>

          <div v-else id="ppanneau-historique" role="tabpanel" aria-labelledby="ptab-historique" class="space-y-2" data-testid="onglet-historique">
            <ul class="divide-y">
              <li v-for="(e, i) in selected.timeline" :key="i" class="flex flex-wrap items-baseline justify-between gap-2 py-2 text-sm"><span>{{ e.label }}</span><time class="text-slate-600" :datetime="e.at">{{ dateTime(e.at) }}</time></li>
            </ul>
          </div>
        </aside>
      </div>
    </div>
  </AdminLayout>
</template>
