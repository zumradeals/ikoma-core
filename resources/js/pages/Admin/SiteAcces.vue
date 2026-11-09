<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
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
  { key: 'postes', label: 'Postes de contrôle' }, { key: 'zones', label: 'Zones' }, { key: 'tablettes', label: 'Tablettes' },
  { key: 'regles', label: 'Règles d’accès' }, { key: 'parametres', label: 'Paramètres' },
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
    <div class="flex max-w-6xl flex-col gap-5" data-testid="page-site-acces">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h1 class="text-display font-bold">Site et accès</h1>
          <p class="max-w-3xl text-slate-700"><strong v-if="site" data-testid="nom-site">{{ site.name }}</strong><span v-else data-testid="aucun-site">Aucun site n’est enregistré.</span> · Postes de contrôle, opérations autorisées, tablettes et zones gardées.</p>
        </div>
        <button type="button" class="btn-main !w-auto" :aria-expanded="creating" data-testid="ajouter-poste" @click="startCreate">Ajouter un poste</button>
      </header>

      <div v-if="errors.length" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4" data-testid="erreurs-site"><p v-for="error in errors" :key="error">{{ error }}</p></div>

      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="indicateurs">
        <div class="admin-card !p-4"><dt class="text-sm font-semibold text-slate-700">Postes de contrôle</dt><dd class="text-2xl font-bold" data-testid="kpi-postes">{{ checkpoints.length }}</dd><dd class="text-sm text-slate-600">{{ activePosts.length }} actif<template v-if="activePosts.length > 1">s</template></dd></div>
        <div class="admin-card !p-4"><dt class="text-sm font-semibold text-slate-700">Zones définies</dt><dd class="text-2xl font-bold" data-testid="kpi-zones">{{ zones.length }}</dd><dd class="text-sm text-slate-600">dans les activités</dd></div>
        <div class="admin-card !p-4"><dt class="text-sm font-semibold text-slate-700">Tablettes affectées</dt><dd class="text-2xl font-bold" data-testid="kpi-tablettes">{{ assignedTablets }}</dd><dd class="text-sm text-slate-600">{{ unassigned.length }} sans poste</dd></div>
        <div class="admin-card !p-4"><dt class="text-sm font-semibold text-slate-700">Zones gardées</dt><dd class="text-2xl font-bold" data-testid="kpi-correspondances">{{ guardedZones }}</dd><dd class="text-sm text-slate-600">correspondances poste–zone</dd></div>
      </dl>

      <div ref="tabs" role="tablist" aria-label="Sections de Site et accès" class="flex flex-wrap gap-2" @keydown="onTabKey">
        <button v-for="t in TABS" :id="'tab-' + t.key" :key="t.key" type="button" role="tab" class="min-h-11 rounded-full border px-4 py-2 text-sm font-bold"
          :class="tab === t.key ? 'border-transparent bg-[var(--inst-accent-ui)] text-slate-900' : 'border-slate-300 bg-white text-blue-900'"
          :aria-selected="tab === t.key" :aria-controls="'panneau-' + t.key" :tabindex="tab === t.key ? 0 : -1" :data-testid="'tab-' + t.key" @click="tab = t.key">{{ t.label }}</button>
      </div>

      <!-- Postes de contrôle : registre synthétique ; configuration détaillée à la sélection -->
      <section v-if="tab === 'postes'" id="panneau-postes" role="tabpanel" aria-labelledby="tab-postes" class="space-y-4" data-testid="panneau-postes">
        <div class="grid items-start gap-4" :class="selected || creating ? 'lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]' : ''">
          <div class="admin-card !p-0 overflow-hidden" data-testid="registre-postes">
            <p v-if="!checkpoints.length" class="p-4" data-testid="aucun-poste">Aucun poste n’est configuré : utilisez « Ajouter un poste ».</p>
            <ul v-else class="divide-y">
              <li class="hidden grid-cols-[minmax(0,1.3fr)_minmax(0,1.4fr)_5rem_7.5rem] gap-3 bg-slate-50 px-4 py-2 text-xs font-bold uppercase tracking-wide text-slate-600 md:grid" aria-hidden="true"><span>Poste</span><span>Opérations autorisées</span><span>État</span><span /></li>
              <li v-for="p in checkpoints" :key="p.id" class="grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1.4fr)_5rem_7.5rem] md:items-center md:gap-3" :class="selectedId === p.id ? 'bg-blue-50' : ''" :data-testid="'poste-' + p.code">
                <div class="min-w-0"><p class="font-bold">{{ p.name }} <small class="font-normal text-slate-600">({{ p.code }})</small></p><p v-if="p.function_label" class="text-sm text-slate-700">{{ p.function_label }}</p><p class="text-sm text-slate-600" :data-testid="'nombre-tablettes-' + p.code">{{ p.tablets.length }} tablette<template v-if="p.tablets.length > 1">s</template> · {{ p.zones.length }} zone<template v-if="p.zones.length > 1">s</template></p></div>
                <p class="flex flex-wrap gap-1" :data-testid="'operations-' + p.code">
                  <span v-for="c in p.capabilities" :key="c" class="rounded-full border border-blue-300 bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-900">{{ short(c) }}</span>
                  <span v-if="p.direction_required" class="rounded-full border border-slate-300 px-2 py-0.5 text-xs text-slate-700">Sens obligatoire</span>
                  <span v-if="!p.capabilities.length" class="text-sm text-slate-600">Aucune opération</span>
                </p>
                <p><span class="rounded-full border px-2 py-0.5 text-xs font-bold" :class="p.active ? 'border-green-700 text-green-900' : 'border-slate-500 text-slate-700'" :data-testid="'etat-' + p.code">{{ p.active ? 'Actif' : 'Désactivé' }}</span></p>
                <p><button type="button" class="btn-soft w-full !min-h-11 !px-3 !py-1 text-sm" :aria-expanded="selectedId === p.id" :aria-label="(selectedId === p.id ? 'Fermer la configuration de ' : 'Configurer ') + p.name" :data-testid="'selectionner-' + p.code" @click="choose(p)">{{ selectedId === p.id ? 'Fermer' : 'Configurer' }}</button></p>
              </li>
            </ul>
          </div>

          <!-- Création d'un poste -->
          <aside v-if="creating" class="admin-card space-y-3" aria-labelledby="creer-poste-titre" data-testid="panneau-creation">
            <h2 id="creer-poste-titre" class="text-heading font-bold">Ajouter un poste</h2>
            <form class="space-y-3" @submit.prevent="createPost">
              <label class="block">Code (minuscules, chiffres, tiret bas)<input v-model="create.code" class="field" maxlength="20" required data-testid="nouveau-code"></label>
              <label class="block">Nom<input v-model="create.name" class="field" maxlength="120" required data-testid="nouveau-nom"></label>
              <label class="block">Fonction<input v-model="create.function_label" class="field" maxlength="160" data-testid="nouvelle-fonction"></label>
              <fieldset class="space-y-1">
                <legend class="font-semibold">Opérations autorisées</legend>
                <label v-for="(label, capability) in catalogue" :key="capability" class="flex min-h-12 items-start gap-3 py-1"><input type="checkbox" class="mt-1 h-5 w-5 shrink-0" :checked="create.capabilities.includes(capability)" :data-testid="'nouvelle-capacite-' + capability" @change="toggleCreate(capability, ($event.target as HTMLInputElement).checked)"><span>{{ label }}</span></label>
                <label class="flex min-h-12 items-start gap-3 py-1"><input v-model="create.direction_required" type="checkbox" class="mt-1 h-5 w-5 shrink-0" :disabled="!create.capabilities.includes('scan')"><span>Choix Entrée / Sortie obligatoire</span></label>
              </fieldset>
              <div class="flex flex-wrap gap-3"><button type="submit" class="btn-main" data-testid="creer-poste">Créer le poste</button><button type="button" class="btn-soft" @click="creating = false">Annuler</button></div>
            </form>
          </aside>

          <!-- Configuration détaillée du poste sélectionné -->
          <aside v-else-if="selected" class="admin-card space-y-5" :aria-label="'Configuration de ' + selected.name" data-testid="detail-poste">
            <div class="flex items-start justify-between gap-3">
              <h2 class="text-heading font-bold">{{ selected.name }} <small class="font-normal text-slate-600">({{ selected.code }})</small></h2>
              <button type="button" class="btn-soft !min-h-11 !px-3 !py-1 text-sm" data-testid="fermer-detail" @click="selectedId = null">Fermer</button>
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
          </aside>
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
    </div>
  </AdminLayout>
</template>
