<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import { displayDate } from '../../labels';

/**
 * Site et accès : vue synthétique des postes de contrôle permanents du site (état, opérations autorisées, zones gardées, tablettes affectées).
 * La configuration détaillée d'un poste ne s'ouvre que lorsqu'on le sélectionne. Aucun plan : tout vient des données réelles.
 * Une tablette s'affecte à un poste ; un poste peut en avoir plusieurs. Un poste se désactive, il ne se supprime pas.
 */
type Tablet = { id: string; name: string; station: string | null; checkpoint_id: string | null; state: 'non_enrole' | 'enrole' | 'revoque'; last_synced_at: string | null; event_name: string };
type PostZone = { mapping_id: string; zone_id: string; event_id: string; name: string; event_name: string };
type Checkpoint = { id: string; code: string; name: string; function_label: string | null; capabilities: string[]; direction_required: boolean; active: boolean; version: number; tablets: Tablet[]; zones: PostZone[] };
type SiteZone = { id: string; name: string; event_id: string; event_name: string };
const props = defineProps<{
  site: { id: string; name: string } | null; checkpoints: Checkpoint[]; catalogue: Record<string, string>; unassigned: Tablet[];
  zones: SiteZone[];
  history: { id: string; device: string; checkpoint: string | null; assigned_at: string; ended_at: string | null; reason: string | null; by: string | null }[];
}>();
const page = usePage<{ errors?: Record<string, string> }>();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const stateLabel = { non_enrole: 'Non enrôlée', enrole: 'Enrôlée', revoque: 'Révoquée' } as const;
// Libellé court d'une opération : le début du libellé du catalogue, avant « : » ou « ( ».
const short = (capability: string) => (props.catalogue[capability] ?? capability).split(/[:(]/)[0].trim();

const TABS = [
  { key: 'postes', label: 'Postes de contrôle', icon: 'monitor' }, { key: 'zones', label: 'Zones', icon: 'layers' }, { key: 'tablettes', label: 'Tablettes', icon: 'tablet' },
  { key: 'regles', label: 'Règles d’accès', icon: 'shield' }, { key: 'parametres', label: 'Paramètres', icon: 'settings' },
] as const;
type TabKey = typeof TABS[number]['key'];
const tab = ref<TabKey>('postes');
const tabs = ref<HTMLElement | null>(null);
function onTabKey(event: KeyboardEvent) {
  const index = TABS.findIndex(t => t.key === tab.value);
  const next = event.key === 'ArrowRight' ? index + 1 : event.key === 'ArrowLeft' ? index - 1 : null;
  if (next === null) return;
  event.preventDefault();
  tab.value = TABS[(next + TABS.length) % TABS.length].key;
  nextTick(() => (tabs.value?.querySelector('[aria-selected="true"]') as HTMLElement | null)?.focus());
}

// Indicateurs : uniquement des comptes réels.
const activePosts = computed(() => props.checkpoints.filter(p => p.active));
const assignedTablets = computed(() => props.checkpoints.reduce((n, p) => n + p.tablets.length, 0));
const guardedZones = computed(() => props.checkpoints.reduce((n, p) => n + p.zones.length, 0));
// Règles d'accès réelles = correspondances poste–zone ; une règle est active tant que son poste l'est.
const rules = computed(() => props.checkpoints.flatMap(p => p.zones.map(z => ({ ...z, post: p, active: p.active }))));
const PREVIEW = 4;
const allTablets = computed(() => [
  ...props.checkpoints.flatMap(p => p.tablets.map(t => ({ ...t, post: p.name }))),
  ...props.unassigned.map(t => ({ ...t, post: null as string | null })),
]);

// Poste sélectionné : sa configuration détaillée n'est ouverte que sur sélection.
const selectedId = ref<string | null>(null);
const creating = ref(false);
const selected = computed(() => props.checkpoints.find(p => p.id === selectedId.value) ?? null);
function choose(p: Checkpoint) { creating.value = false; selectedId.value = selectedId.value === p.id ? null : p.id; }
function configure(p: Checkpoint) { tab.value = 'postes'; creating.value = false; selectedId.value = p.id; }
const drawer = ref<HTMLElement | null>(null);
function closeDrawer() { selectedId.value = null; creating.value = false; }
watch(() => [selectedId.value, creating.value], async ([id, c]) => { if (id || c) { await nextTick(); (drawer.value?.querySelector('[data-testid="fermer-detail"]') as HTMLElement | null)?.focus(); } });
function startCreate() { tab.value = 'postes'; selectedId.value = null; creating.value = !creating.value; }

const siteName = ref(props.site?.name ?? '');
function saveSite() { router.put('/administration/site', { name: siteName.value }, { preserveScroll: true }); }

type Edit = { name: string; function_label: string; capabilities: string[]; direction_required: boolean };
const edit = reactive<Record<string, Edit>>({});
function sync() { for (const p of props.checkpoints) edit[p.id] = { name: p.name, function_label: p.function_label ?? '', capabilities: [...p.capabilities], direction_required: p.direction_required }; }
const target = reactive<Record<string, string>>({});
const zoneChoice = reactive<Record<string, string>>({});
// Les listes de choix partent vides (invite affichée) ; une valeur déjà choisie est conservée.
function syncChoices() {
  for (const p of props.checkpoints) { zoneChoice[p.id] ??= ''; for (const t of p.tablets) target[t.id] ??= ''; }
  for (const t of props.unassigned) target[t.id] ??= '';
}
sync(); syncChoices();
watch(() => [props.checkpoints, props.unassigned], () => { sync(); syncChoices(); });
function toggle(id: string, capability: string, on: boolean) {
  const e = edit[id]; e.capabilities = on ? [...e.capabilities, capability] : e.capabilities.filter(c => c !== capability);
  if (!e.capabilities.includes('scan')) e.direction_required = false;
}
function save(p: Checkpoint) { router.put(`/administration/site/postes/${p.id}`, { ...edit[p.id], version: p.version }, { preserveScroll: true }); }
function activation(p: Checkpoint) { router.post(`/administration/site/postes/${p.id}/activation`, { active: !p.active, version: p.version }, { preserveScroll: true }); }

function assign(tablet: { id: string }) { router.post(`/administration/site/tablettes/${tablet.id}/affectation`, { checkpoint_id: target[tablet.id] || null }, { preserveScroll: true }); }

function addZone(p: Checkpoint) {
  const zone = props.zones.find(z => z.id === zoneChoice[p.id]);
  if (zone) router.post('/administration/acces/correspondances', { event_id: zone.event_id, checkpoint_id: p.id, zone_id: zone.id }, { preserveScroll: true });
}
function removeZone(z: PostZone) { router.post(`/administration/acces/correspondances/${z.mapping_id}/retirer`, {}, { preserveScroll: true }); }
const postsOfZone = (zoneId: string) => props.checkpoints.filter(p => p.zones.some(z => z.zone_id === zoneId));

const create = reactive<Edit & { code: string }>({ code: '', name: '', function_label: '', capabilities: [], direction_required: false });
function createPost() {
  router.post('/administration/site/postes', { ...create }, { preserveScroll: true, onSuccess: () => { Object.assign(create, { code: '', name: '', function_label: '', capabilities: [], direction_required: false }); creating.value = false; } });
}
function toggleCreate(capability: string, on: boolean) {
  create.capabilities = on ? [...create.capabilities, capability] : create.capabilities.filter(c => c !== capability);
  if (!create.capabilities.includes('scan')) create.direction_required = false;
}
</script>

<template>
  <AdminLayout active="site-acces">
    <div class="flex max-w-[1500px] flex-col gap-5" data-testid="page-site-acces">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-start gap-3">
          <span class="mt-1 text-blue-700" aria-hidden="true"><AdminIcon name="pin" :size="34" /></span>
          <div>
            <h1 class="text-display font-bold">Site et accès</h1>
            <p class="max-w-3xl text-slate-700">Configuration du site, des postes de contrôle, des zones et des règles d’accès.<span v-if="!site" data-testid="aucun-site"> Aucun site n’est enregistré.</span></p>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="site-btn site-btn-outline" data-testid="historique-lien" @click="tab = 'parametres'"><AdminIcon name="history" :size="18" />Historique des affectations</button>
          <button type="button" class="site-btn site-btn-accent" :aria-expanded="creating" data-testid="ajouter-poste" @click="startCreate"><AdminIcon name="plus" :size="18" />Ajouter un poste</button>
        </div>
      </header>

      <div v-if="errors.length" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4" data-testid="erreurs-site"><p v-for="error in errors" :key="error">{{ error }}</p></div>

      <div ref="tabs" role="tablist" aria-label="Sections de Site et accès" class="site-tabs" @keydown="onTabKey">
        <button v-for="t in TABS" :id="'tab-' + t.key" :key="t.key" type="button" role="tab" class="site-tab"
          :aria-selected="tab === t.key" :aria-controls="'panneau-' + t.key" :tabindex="tab === t.key ? 0 : -1" :data-testid="'tab-' + t.key" @click="tab = t.key"><AdminIcon :name="t.icon" :size="20" /><span>{{ t.label }}</span></button>
      </div>

      <!-- Vue d'ensemble : indicateurs, registre des postes, informations du site, zones, règles -->
      <section v-if="tab === 'postes'" id="panneau-postes" role="tabpanel" aria-labelledby="tab-postes" class="space-y-5" data-testid="panneau-postes">
        <dl class="grid grid-cols-2 gap-3 xl:grid-cols-4" data-testid="indicateurs">
          <div class="site-kpi bg-sky-50"><span class="site-kpi-icon text-sky-700"><AdminIcon name="monitor" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-postes">{{ checkpoints.length }}</dd><dt class="font-semibold text-slate-800">Postes configurés</dt><dd class="text-sm text-slate-600">{{ activePosts.length }} actif<template v-if="activePosts.length > 1">s</template></dd></div></div>
          <div class="site-kpi bg-emerald-50"><span class="site-kpi-icon text-emerald-700"><AdminIcon name="layers" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-zones">{{ zones.length }}</dd><dt class="font-semibold text-slate-800">Zones définies</dt><dd class="text-sm text-slate-600">dans les activités</dd></div></div>
          <div class="site-kpi bg-amber-50"><span class="site-kpi-icon text-amber-700"><AdminIcon name="tablet" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-tablettes">{{ assignedTablets }}</dd><dt class="font-semibold text-slate-800">Tablettes affectées</dt><dd class="text-sm text-slate-600">{{ unassigned.length }} sans poste</dd></div></div>
          <div class="site-kpi bg-violet-50"><span class="site-kpi-icon text-violet-700"><AdminIcon name="shield" :size="40" /></span><div><dd class="text-3xl font-extrabold" data-testid="kpi-correspondances">{{ guardedZones }}</dd><dt class="font-semibold text-slate-800">Zones gardées</dt><dd class="text-sm text-slate-600">correspondances poste–zone</dd></div></div>
        </dl>

        <div class="site-grid">
         <div class="site-col">
          <!-- Registre des postes -->
          <section class="site-card" aria-labelledby="postes-titre" data-testid="registre-postes">
            <header class="site-card-head"><h2 id="postes-titre"><AdminIcon name="monitor" :size="22" />Postes de contrôle ({{ checkpoints.length }})</h2><button type="button" class="site-btn site-btn-blue" data-testid="ajouter-poste-registre" @click="startCreate"><AdminIcon name="plus" :size="16" />Ajouter un poste</button></header>
            <p v-if="!checkpoints.length" class="p-4" data-testid="aucun-poste">Aucun poste n’est configuré : utilisez « Ajouter un poste ».</p>
            <ul v-else class="site-table">
              <li class="site-row site-row-head" aria-hidden="true"><span>Code</span><span>Intitulé du poste</span><span>Opérations autorisées</span><span>Fonction</span><span>Tablette(s)</span><span>État</span><span>Actions</span></li>
              <li v-for="p in checkpoints" :key="p.id" class="site-row" :class="selectedId === p.id ? 'bg-blue-50' : ''" :data-testid="'poste-' + p.code">
                <span class="site-code site-c-code"><span class="rounded-md bg-blue-700 px-2 py-1 text-xs font-bold text-white">{{ p.code }}</span></span>
                <span class="site-c-name font-semibold">{{ p.name }}</span>
                <span class="site-c-ops flex flex-wrap gap-1" :data-testid="'operations-' + p.code">
                  <span v-for="c in p.capabilities" :key="c" class="rounded-full border border-blue-300 bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-900">{{ short(c) }}</span>
                  <span v-if="p.direction_required" class="rounded-full border border-slate-300 px-2 py-0.5 text-xs text-slate-700">Sens obligatoire</span>
                  <span v-if="!p.capabilities.length" class="text-sm text-slate-600">Aucune opération</span>
                </span>
                <span class="site-c-fn text-sm text-slate-700">{{ p.function_label ?? '—' }}</span>
                <span class="site-c-tab text-sm" :data-testid="'nombre-tablettes-' + p.code"><strong>{{ p.tablets.length }}</strong><span class="site-mobile-only"> tablette<template v-if="p.tablets.length > 1">s</template></span><template v-if="p.tablets.length"> · {{ p.tablets[0].name }}<template v-if="p.tablets.length > 1"> +{{ p.tablets.length - 1 }}</template></template></span>
                <span class="site-c-etat"><span class="inline-flex items-center gap-1.5 text-sm font-semibold" :class="p.active ? 'text-green-800' : 'text-slate-600'" :data-testid="'etat-' + p.code"><span class="h-2.5 w-2.5 rounded-full" :class="p.active ? 'bg-green-600' : 'bg-slate-400'" aria-hidden="true" />{{ p.active ? 'Actif' : 'Désactivé' }}</span></span>
                <span class="site-c-act"><button type="button" class="site-icon-btn" :aria-label="'Configurer ' + p.name" title="Configurer" :aria-expanded="selectedId === p.id" :data-testid="'selectionner-' + p.code" @click="choose(p)"><AdminIcon name="pencil" :size="18" /><span class="site-icon-label">Configurer</span></button></span>
              </li>
            </ul>
          </section>

          <!-- Zones du site -->
          <section class="site-card" aria-labelledby="zones-resume-titre" data-testid="resume-zones">
            <header class="site-card-head"><h2 id="zones-resume-titre"><AdminIcon name="layers" :size="22" />Zones du site ({{ zones.length }})</h2><Link href="/administration/zones" class="site-btn site-btn-blue" data-testid="ajouter-zone"><AdminIcon name="plus" :size="16" />Ajouter une zone</Link></header>
            <p v-if="!zones.length" class="p-4" data-testid="aucune-zone-resume">Aucune zone n’est définie.</p>
            <ul v-else class="site-table site-table-zones">
              <li class="site-row site-row-head" aria-hidden="true"><span>Zone</span><span>Activité</span><span>Gardée par</span></li>
              <li v-for="z in zones.slice(0, PREVIEW + 1)" :key="z.id" class="site-row" :data-testid="'zone-resume-' + z.id">
                <span class="font-semibold">{{ z.name }}</span><span class="text-sm text-slate-700">{{ z.event_name }}</span>
                <span class="text-sm">{{ postsOfZone(z.id).map(p => p.name).join(', ') || 'aucun poste' }}</span>
              </li>
            </ul>
            <p v-if="zones.length > PREVIEW + 1" class="site-more"><button type="button" class="font-semibold text-blue-800 underline" @click="tab = 'zones'">Voir toutes les zones ({{ zones.length }})</button></p>
          </section>
         </div>
         <div class="site-col">
          <!-- Informations du site -->
          <section class="site-card" aria-labelledby="infos-titre" data-testid="infos-site">
            <header class="site-card-head"><h2 id="infos-titre"><AdminIcon name="pin" :size="22" />Informations du site</h2><button type="button" class="site-icon-btn" aria-label="Modifier les informations du site" @click="tab = 'parametres'"><AdminIcon name="pencil" :size="18" /></button></header>
            <dl class="site-info">
              <div><dt>Nom du site</dt><dd data-testid="nom-site">{{ site?.name ?? '—' }}</dd></div>
              <div><dt>Nombre de postes</dt><dd>{{ checkpoints.length }}</dd></div>
              <div><dt>Postes actifs</dt><dd>{{ activePosts.length }}</dd></div>
              <div><dt>Nombre de zones</dt><dd>{{ zones.length }}</dd></div>
              <div><dt>Tablettes affectées</dt><dd>{{ assignedTablets }}</dd></div>
              <div><dt>Tablettes sans poste</dt><dd>{{ unassigned.length }}</dd></div>
            </dl>
          </section>

          <!-- Règles d'accès -->
          <section class="site-card" aria-labelledby="regles-resume-titre" data-testid="resume-regles">
            <header class="site-card-head"><h2 id="regles-resume-titre"><AdminIcon name="shield" :size="22" />Règles d’accès ({{ rules.length }})</h2><Link href="/administration/acces" class="site-btn site-btn-blue" data-testid="ajouter-regle"><AdminIcon name="plus" :size="16" />Ajouter une règle</Link></header>
            <p v-if="!rules.length" class="p-4" data-testid="aucune-regle-resume">Aucune règle poste–zone : sans correspondance, un poste ne refuse pas pour une zone.</p>
            <ul v-else class="divide-y">
              <li v-for="r in rules.slice(0, PREVIEW)" :key="r.mapping_id" class="flex items-center justify-between gap-3 px-4 py-2.5" :data-testid="'regle-resume-' + r.mapping_id">
                <span class="min-w-0"><strong>{{ r.name }}</strong><span class="block text-sm text-slate-600">{{ r.post.name }} · {{ r.event_name }}</span></span>
                <span class="shrink-0 rounded-md px-2 py-0.5 text-xs font-bold" :class="r.active ? 'bg-green-100 text-green-900' : 'bg-slate-200 text-slate-700'">{{ r.active ? 'Active' : 'Poste désactivé' }}</span>
              </li>
            </ul>
            <p v-if="rules.length" class="site-more"><button type="button" class="font-semibold text-blue-800 underline" data-testid="voir-regles" @click="tab = 'regles'">Voir toutes les règles ({{ rules.length }})</button></p>
          </section>
         </div>
        </div>
      </section>

      <!-- Zones -->
      <section v-else-if="tab === 'zones'" id="panneau-zones" role="tabpanel" aria-labelledby="tab-zones" class="admin-card space-y-3" data-testid="panneau-zones">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-heading font-bold">Zones ({{ zones.length }})</h2><Link href="/administration/zones" class="btn-soft" data-testid="gerer-zones">Gérer les zones</Link></div>
        <p class="text-slate-700">Les zones sont définies dans les activités. Un poste ne juge une zone que s’il lui est associé.</p>
        <p v-if="!zones.length" data-testid="aucune-zone">Aucune zone n’est définie.</p>
        <ul v-else class="divide-y">
          <li v-for="z in zones" :key="z.id" class="space-y-1 py-2" :data-testid="'zone-liste-' + z.id">
            <p><strong>{{ z.name }}</strong> · {{ z.event_name }}</p>
            <p class="text-sm text-slate-700">Gardée par : <template v-if="postsOfZone(z.id).length"><template v-for="(p, i) in postsOfZone(z.id)" :key="p.id"><template v-if="i > 0">, </template><button type="button" class="font-semibold text-blue-900 underline" @click="configure(p)">{{ p.name }}</button></template></template><template v-else>aucun poste</template></p>
          </li>
        </ul>
      </section>

      <!-- Tablettes -->
      <section v-else-if="tab === 'tablettes'" id="panneau-tablettes" role="tabpanel" aria-labelledby="tab-tablettes" class="admin-card space-y-3" data-testid="panneau-tablettes">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-heading font-bold">Tablettes ({{ allTablets.length }})</h2><Link href="/administration/postes" class="btn-soft" data-testid="gerer-tablettes">Enrôler ou révoquer des tablettes</Link></div>
        <p class="text-slate-700">Un poste peut avoir plusieurs tablettes. Une tablette sans poste n’a aucune capacité de poste : elle ne sert qu’aux opérations de groupe autorisées par le rôle de l’agent.</p>
        <p v-if="!allTablets.length" data-testid="aucune-tablette">Aucune tablette n’est enregistrée.</p>
        <ul v-else class="space-y-2">
          <li v-for="t in allTablets" :key="t.id" class="space-y-2 rounded-lg border p-3 md:flex md:flex-wrap md:items-center md:justify-between md:gap-3 md:space-y-0" :data-testid="'tablette-' + t.id">
            <p><strong>{{ t.name }}</strong> · {{ t.event_name }} · {{ stateLabel[t.state] }} · <span :data-testid="'poste-de-' + t.id">{{ t.post ?? 'sans poste' }}</span></p>
            <div class="flex flex-wrap items-center gap-2">
              <select v-model="target[t.id]" class="field !mt-0 grow" :aria-label="'Poste de ' + t.name" :data-testid="'cible-' + t.id"><option value="">{{ t.post ? 'Changer de poste…' : 'Choisir un poste…' }}</option><option v-for="q in activePosts.filter(x => x.id !== t.checkpoint_id)" :key="q.id" :value="q.id">{{ q.name }}</option></select>
              <button type="button" class="btn-soft !min-h-11 !px-4 !py-2 !text-sm" :disabled="!target[t.id]" :data-testid="'affecter-' + t.id" @click="assign(t)">{{ t.post ? 'Déplacer' : 'Affecter' }}</button>
            </div>
          </li>
        </ul>
        <p class="text-sm text-slate-700" data-testid="tablettes-sans-poste">{{ unassigned.length }} tablette<template v-if="unassigned.length > 1">s</template> sans poste.</p>
      </section>

      <!-- Règles d'accès : uniquement les règles réellement configurées -->
      <section v-else-if="tab === 'regles'" id="panneau-regles" role="tabpanel" aria-labelledby="tab-regles" class="admin-card space-y-3" data-testid="panneau-regles">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-heading font-bold">Règles d’accès par poste</h2><Link href="/administration/acces" class="btn-soft" data-testid="gerer-acces">Accès des participants</Link></div>
        <p class="text-slate-700">Ce que chaque poste est autorisé à faire et les zones qu’il juge. Rien n’est autorisé par défaut : un poste sans opération refuse.</p>
        <p v-if="!checkpoints.length" data-testid="aucune-regle">Aucun poste n’est configuré.</p>
        <ul v-else class="divide-y">
          <li v-for="p in checkpoints" :key="p.id" class="space-y-1 py-3" :data-testid="'regle-' + p.code">
            <div class="flex flex-wrap items-center justify-between gap-2"><p class="font-bold">{{ p.name }} <small class="font-normal text-slate-600">({{ p.code }}){{ p.active ? '' : ' · désactivé' }}</small></p><button type="button" class="btn-soft !min-h-11 !px-3 !py-1 text-sm" :aria-label="'Modifier les règles de ' + p.name" @click="configure(p)">Modifier</button></div>
            <p class="text-sm"><span class="font-semibold">Opérations :</span> {{ p.capabilities.length ? p.capabilities.map(short).join(', ') : 'aucune' }}<template v-if="p.direction_required"> · sens Entrée / Sortie obligatoire</template></p>
            <p class="text-sm"><span class="font-semibold">Zones jugées :</span> {{ p.zones.length ? p.zones.map(z => z.name).join(', ') : 'aucune' }}</p>
          </li>
        </ul>
      </section>

      <!-- Paramètres : nom du site et historique des affectations -->
      <section v-else id="panneau-parametres" role="tabpanel" aria-labelledby="tab-parametres" class="space-y-4" data-testid="panneau-parametres">
        <div class="admin-card space-y-3">
          <h2 class="text-heading font-bold">Site</h2>
          <p v-if="!site">Aucun site n’est enregistré.</p>
          <form v-else class="flex flex-wrap items-end gap-3" @submit.prevent="saveSite">
            <label class="block grow">Nom du site<input v-model="siteName" class="field" maxlength="160" required data-testid="site-nom"></label>
            <button type="submit" class="btn-soft" data-testid="enregistrer-site">Enregistrer</button>
          </form>
        </div>
        <div class="admin-card space-y-3" data-testid="historique-affectations">
          <h2 class="text-heading font-bold">Historique des affectations</h2>
          <p v-if="!history.length">Aucune affectation enregistrée.</p>
          <ul v-else class="space-y-2">
            <li v-for="h in history" :key="h.id"><strong>{{ h.device }}</strong> → {{ h.checkpoint ?? 'sans poste' }} · depuis {{ displayDate(h.assigned_at) }}<template v-if="h.ended_at"> jusqu’au {{ displayDate(h.ended_at) }}</template><template v-else> (en cours)</template><template v-if="h.by"> · par {{ h.by }}</template></li>
          </ul>
        </div>
      </section>

      <!-- Panneau contextuel : création ou configuration détaillée d'un poste -->
      <template v-if="creating || selected">
        <button type="button" class="site-backdrop" aria-label="Fermer le panneau" tabindex="-1" data-testid="fond-panneau" @click="closeDrawer" />
        <aside ref="drawer" class="site-drawer" role="dialog" aria-modal="true" :aria-label="creating ? 'Ajouter un poste' : 'Configuration de ' + selected!.name" :data-testid="creating ? 'panneau-creation' : 'detail-poste'" @keydown.esc="closeDrawer">
          <template v-if="creating">
            <div class="flex items-start justify-between gap-3"><h2 id="creer-poste-titre" class="text-heading font-bold">Ajouter un poste</h2><button type="button" class="btn-soft !min-h-11 !px-3 !py-1 text-sm" data-testid="fermer-detail" @click="closeDrawer">Fermer</button></div>
            <form class="space-y-3" @submit.prevent="createPost">
              <label class="block">Code (minuscules, chiffres, tiret bas)<input v-model="create.code" class="field" maxlength="20" required data-testid="nouveau-code"></label>
              <label class="block">Nom<input v-model="create.name" class="field" maxlength="120" required data-testid="nouveau-nom"></label>
              <label class="block">Fonction<input v-model="create.function_label" class="field" maxlength="160" data-testid="nouvelle-fonction"></label>
              <fieldset class="space-y-1">
                <legend class="font-semibold">Opérations autorisées</legend>
                <label v-for="(label, capability) in catalogue" :key="capability" class="flex min-h-12 items-start gap-3 py-1"><input type="checkbox" class="mt-1 h-5 w-5 shrink-0" :checked="create.capabilities.includes(capability)" :data-testid="'nouvelle-capacite-' + capability" @change="toggleCreate(capability, ($event.target as HTMLInputElement).checked)"><span>{{ label }}</span></label>
                <label class="flex min-h-12 items-start gap-3 py-1"><input v-model="create.direction_required" type="checkbox" class="mt-1 h-5 w-5 shrink-0" :disabled="!create.capabilities.includes('scan')"><span>Choix Entrée / Sortie obligatoire</span></label>
              </fieldset>
              <div class="flex flex-wrap gap-3"><button type="submit" class="btn-main" data-testid="creer-poste">Créer le poste</button><button type="button" class="btn-soft" @click="closeDrawer">Annuler</button></div>
            </form>
          </template>
          <template v-else-if="selected">
            <div class="flex items-start justify-between gap-3">
              <h2 class="text-heading font-bold">{{ selected.name }} <small class="font-normal text-slate-600">({{ selected.code }})</small></h2>
              <button type="button" class="btn-soft !min-h-11 !px-3 !py-1 text-sm" data-testid="fermer-detail" @click="closeDrawer">Fermer</button>
            </div>

            <form class="space-y-3" @submit.prevent="save(selected)">
              <label class="block">Nom du poste<input v-model="edit[selected.id].name" class="field" maxlength="120" required :data-testid="'nom-' + selected.code"></label>
              <label class="block">Fonction<input v-model="edit[selected.id].function_label" class="field" maxlength="160" :data-testid="'fonction-' + selected.code"></label>
              <fieldset class="space-y-1">
                <legend class="font-semibold">Opérations autorisées à ce poste</legend>
                <p class="text-sm text-slate-700">Aucune lecture de badge n’est imposée : chaque opération s’active selon la configuration. L’agent doit en plus détenir le droit correspondant.</p>
                <label v-for="(label, capability) in catalogue" :key="capability" class="flex min-h-12 items-start gap-3 py-1"><input type="checkbox" class="mt-1 h-5 w-5 shrink-0" :checked="edit[selected.id].capabilities.includes(capability)" :data-testid="'capacite-' + selected.code + '-' + capability" @change="toggle(selected.id, capability, ($event.target as HTMLInputElement).checked)"><span>{{ label }}</span></label>
                <label class="flex min-h-12 items-start gap-3 py-1"><input v-model="edit[selected.id].direction_required" type="checkbox" class="mt-1 h-5 w-5 shrink-0" :disabled="!edit[selected.id].capabilities.includes('scan')" :data-testid="'sens-' + selected.code"><span>Choix Entrée / Sortie obligatoire avant chaque contrôle</span></label>
              </fieldset>
              <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-main" :data-testid="'enregistrer-' + selected.code">Enregistrer la configuration</button>
                <button type="button" class="btn-soft !min-h-11 !px-4 !py-2 !text-sm" :data-testid="'activation-' + selected.code" @click="activation(selected)">{{ selected.active ? 'Désactiver ce poste' : 'Réactiver ce poste' }}</button>
              </div>
            </form>

            <div class="space-y-2" :data-testid="'tablettes-' + selected.code">
              <h3 class="font-bold">Tablettes affectées ({{ selected.tablets.length }})</h3>
              <p v-if="!selected.tablets.length" class="text-slate-700">Aucune tablette affectée à ce poste. Affectez-en une depuis la section « Tablettes ».</p>
              <ul v-else class="space-y-2">
                <li v-for="t in selected.tablets" :key="t.id" class="space-y-2 rounded-lg border p-3" :data-testid="'tablette-' + t.id">
                  <p><strong>{{ t.name }}</strong><template v-if="t.station"> · {{ t.station }}</template> · {{ t.event_name }} · {{ stateLabel[t.state] }}<template v-if="t.last_synced_at"> · synchronisée {{ displayDate(t.last_synced_at) }}</template></p>
                  <div class="flex flex-wrap items-center gap-2">
                    <select v-model="target[t.id]" class="field !mt-0 grow" :aria-label="'Nouveau poste de ' + t.name" :data-testid="'cible-' + t.id"><option value="">Changer de poste…</option><option v-for="q in activePosts.filter(x => x.id !== selected!.id)" :key="q.id" :value="q.id">{{ q.name }}</option></select>
                    <button type="button" class="btn-soft !min-h-11 !px-4 !py-2 !text-sm" :disabled="!target[t.id]" :data-testid="'affecter-' + t.id" @click="assign(t)">Déplacer</button>
                  </div>
                </li>
              </ul>
            </div>

            <div class="space-y-2" :data-testid="'zones-' + selected.code">
              <h3 class="font-bold">Zones gardées ({{ selected.zones.length }})</h3>
              <p v-if="!selected.zones.length" class="text-slate-700">Ce poste ne juge aucune zone : sans correspondance, il ne refuse pas pour une zone.</p>
              <ul v-else class="space-y-1">
                <li v-for="z in selected.zones" :key="z.mapping_id" class="flex items-center justify-between gap-3" :data-testid="'zone-' + z.mapping_id"><span><strong>{{ z.name }}</strong> · {{ z.event_name }}</span><button type="button" class="btn-soft !min-h-11 !px-3 !py-1 text-sm" :aria-label="'Retirer la zone ' + z.name" @click="removeZone(z)">Retirer</button></li>
              </ul>
              <div v-if="zones.length" class="flex flex-wrap items-center gap-2">
                <select v-model="zoneChoice[selected.id]" class="field !mt-0 grow" :aria-label="'Zone à associer à ' + selected.name" :data-testid="'zone-choix-' + selected.code"><option value="">Choisir une zone…</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }} · {{ z.event_name }}</option></select>
                <button type="button" class="btn-soft !min-h-11 !px-4 !py-2 !text-sm" :disabled="!zoneChoice[selected.id]" :data-testid="'zone-ajouter-' + selected.code" @click="addZone(selected)">Associer</button>
              </div>
            </div>
          </template>
        </aside>
      </template>
    </div>
  </AdminLayout>
</template>
