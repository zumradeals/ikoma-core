<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { displayDate } from '../../labels';

/**
 * Site et accès : le site, ses postes de contrôle permanents (capacités, tablettes affectées, zones gardées) et l'historique des affectations.
 * Aucun plan : tout vient des données réelles. Une tablette s'affecte à un poste ; un poste peut en avoir plusieurs.
 */
type Tablet = { id: string; name: string; station: string | null; checkpoint_id: string | null; state: 'non_enrole' | 'enrole' | 'revoque'; last_synced_at: string | null; event_name: string };
type PostZone = { mapping_id: string; zone_id: string; event_id: string; name: string; event_name: string };
type Checkpoint = { id: string; code: string; name: string; function_label: string | null; capabilities: string[]; direction_required: boolean; active: boolean; version: number; tablets: Tablet[]; zones: PostZone[] };
const props = defineProps<{
  site: { id: string; name: string } | null; checkpoints: Checkpoint[]; catalogue: Record<string, string>; unassigned: Tablet[];
  zones: { id: string; name: string; event_id: string; event_name: string }[];
  history: { id: string; device: string; checkpoint: string | null; assigned_at: string; ended_at: string | null; reason: string | null; by: string | null }[];
}>();
const page = usePage<{ errors?: Record<string, string> }>();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const stateLabel = { non_enrole: 'Non enrôlée', enrole: 'Enrôlée', revoque: 'Révoquée' } as const;

const siteName = ref(props.site?.name ?? '');
function saveSite() { router.put('/administration/site', { name: siteName.value }, { preserveScroll: true }); }

type Edit = { name: string; function_label: string; capabilities: string[]; direction_required: boolean };
const edit = reactive<Record<string, Edit>>({});
function sync() { for (const p of props.checkpoints) edit[p.id] = { name: p.name, function_label: p.function_label ?? '', capabilities: [...p.capabilities], direction_required: p.direction_required }; }
sync();
watch(() => props.checkpoints, sync);
function toggle(id: string, capability: string, on: boolean) {
  const e = edit[id]; e.capabilities = on ? [...e.capabilities, capability] : e.capabilities.filter(c => c !== capability);
  if (!e.capabilities.includes('scan')) e.direction_required = false;
}
function save(p: Checkpoint) { router.put(`/administration/site/postes/${p.id}`, { ...edit[p.id], version: p.version }, { preserveScroll: true }); }
function activation(p: Checkpoint) { router.post(`/administration/site/postes/${p.id}/activation`, { active: !p.active, version: p.version }, { preserveScroll: true }); }

const target = reactive<Record<string, string>>({});
function assign(tablet: Tablet) { router.post(`/administration/site/tablettes/${tablet.id}/affectation`, { checkpoint_id: target[tablet.id] || null }, { preserveScroll: true }); }
const activePosts = computed(() => props.checkpoints.filter(p => p.active));

const zoneChoice = reactive<Record<string, string>>({});
function addZone(p: Checkpoint) {
  const zone = props.zones.find(z => z.id === zoneChoice[p.id]);
  if (zone) router.post('/administration/acces/correspondances', { event_id: zone.event_id, checkpoint_id: p.id, zone_id: zone.id }, { preserveScroll: true });
}
function removeZone(z: PostZone) { router.post(`/administration/acces/correspondances/${z.mapping_id}/retirer`, {}, { preserveScroll: true }); }

const create = reactive<Edit & { code: string }>({ code: '', name: '', function_label: '', capabilities: [], direction_required: false });
function createPost() {
  router.post('/administration/site/postes', { ...create }, { preserveScroll: true, onSuccess: () => { Object.assign(create, { code: '', name: '', function_label: '', capabilities: [], direction_required: false }); } });
}
function toggleCreate(capability: string, on: boolean) {
  create.capabilities = on ? [...create.capabilities, capability] : create.capabilities.filter(c => c !== capability);
  if (!create.capabilities.includes('scan')) create.direction_required = false;
}
</script>

<template>
  <AdminLayout active="site-acces">
    <div class="flex max-w-5xl flex-col gap-6" data-testid="page-site-acces">
      <header class="space-y-2">
        <h1 class="text-display font-bold">Site et accès</h1>
        <p class="max-w-3xl">Les postes de contrôle du site, ce que chacun est autorisé à faire, les tablettes qui lui sont affectées et les zones qu’il garde. Un poste peut avoir plusieurs tablettes. Un poste se désactive, il ne se supprime pas.</p>
      </header>

      <div v-if="errors.length" role="alert" class="rounded-xl border-2 border-red-700 bg-red-50 p-4" data-testid="erreurs-site"><p v-for="error in errors" :key="error">{{ error }}</p></div>

      <section class="admin-card space-y-3" aria-labelledby="site-titre">
        <h2 id="site-titre" class="text-heading font-bold">Site</h2>
        <p v-if="!site" data-testid="aucun-site">Aucun site n’est enregistré.</p>
        <form v-else class="flex flex-wrap items-end gap-3" @submit.prevent="saveSite">
          <label class="block grow">Nom du site<input v-model="siteName" class="field" maxlength="160" required data-testid="site-nom"></label>
          <button type="submit" class="btn-soft" data-testid="enregistrer-site">Enregistrer</button>
        </form>
      </section>

      <section class="space-y-4" aria-labelledby="postes-titre">
        <h2 id="postes-titre" class="text-heading font-bold">Postes de contrôle ({{ checkpoints.length }})</h2>
        <p v-if="!checkpoints.length" class="admin-card" data-testid="aucun-poste">Aucun poste n’est configuré : créez-en un ci-dessous.</p>
        <article v-for="p in checkpoints" :key="p.id" class="admin-card space-y-4" :data-testid="'poste-' + p.code">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-heading font-bold">{{ p.name }}<template v-if="p.function_label"> — {{ p.function_label }}</template> <small class="font-normal text-slate-600">({{ p.code }})</small></h3>
            <span class="rounded-full border px-3 py-1 text-sm font-bold" :class="p.active ? 'border-green-700 text-green-900' : 'border-slate-500 text-slate-700'" :data-testid="'etat-' + p.code">{{ p.active ? 'Actif' : 'Désactivé' }}</span>
          </div>

          <form class="space-y-3" @submit.prevent="save(p)">
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block">Nom du poste<input v-model="edit[p.id].name" class="field" maxlength="120" required :data-testid="'nom-' + p.code"></label>
              <label class="block">Fonction<input v-model="edit[p.id].function_label" class="field" maxlength="160" :data-testid="'fonction-' + p.code"></label>
            </div>
            <fieldset class="space-y-2">
              <legend class="font-semibold">Opérations autorisées à ce poste</legend>
              <p class="text-sm text-slate-700">Aucune lecture de badge n’est imposée : chaque capacité s’active selon la configuration. L’agent doit en plus détenir le droit correspondant.</p>
              <label v-for="(label, capability) in catalogue" :key="capability" class="flex min-h-12 items-center gap-3"><input type="checkbox" :checked="edit[p.id].capabilities.includes(capability)" :data-testid="'capacite-' + p.code + '-' + capability" @change="toggle(p.id, capability, ($event.target as HTMLInputElement).checked)">{{ label }}</label>
              <label class="flex min-h-12 items-center gap-3"><input v-model="edit[p.id].direction_required" type="checkbox" :disabled="!edit[p.id].capabilities.includes('scan')" :data-testid="'sens-' + p.code">Choix Entrée / Sortie obligatoire avant chaque contrôle</label>
            </fieldset>
            <div class="flex flex-wrap gap-3">
              <button type="submit" class="btn-main" :data-testid="'enregistrer-' + p.code">Enregistrer la configuration</button>
              <button type="button" class="btn-soft" :data-testid="'activation-' + p.code" @click="activation(p)">{{ p.active ? 'Désactiver ce poste' : 'Réactiver ce poste' }}</button>
            </div>
          </form>

          <div class="space-y-2" :data-testid="'tablettes-' + p.code">
            <h4 class="font-bold">Tablettes affectées ({{ p.tablets.length }})</h4>
            <p v-if="!p.tablets.length" class="text-slate-700">Aucune tablette affectée à ce poste.</p>
            <ul v-else class="space-y-2">
              <li v-for="t in p.tablets" :key="t.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3" :data-testid="'tablette-' + t.id">
                <span><strong>{{ t.name }}</strong><template v-if="t.station"> · {{ t.station }}</template> · {{ t.event_name }} · {{ stateLabel[t.state] }}<template v-if="t.last_synced_at"> · dernière synchronisation {{ displayDate(t.last_synced_at) }}</template></span>
                <span class="flex flex-wrap items-center gap-2">
                  <select v-model="target[t.id]" class="field" :aria-label="'Nouveau poste de ' + t.name" :data-testid="'cible-' + t.id"><option value="">Choisir un poste…</option><option v-for="q in activePosts.filter(x => x.id !== p.id)" :key="q.id" :value="q.id">{{ q.name }}</option></select>
                  <button type="button" class="btn-soft" :disabled="!target[t.id]" :data-testid="'affecter-' + t.id" @click="assign(t)">Affecter à ce poste</button>
                </span>
              </li>
            </ul>
          </div>

          <div class="space-y-2" :data-testid="'zones-' + p.code">
            <h4 class="font-bold">Zones gardées ({{ p.zones.length }})</h4>
            <p v-if="!p.zones.length" class="text-slate-700">Ce poste ne juge aucune zone : sans correspondance, il ne refuse pas pour une zone.</p>
            <ul v-else class="space-y-1">
              <li v-for="z in p.zones" :key="z.mapping_id" class="flex items-center justify-between gap-3" :data-testid="'zone-' + z.mapping_id"><span><strong>{{ z.name }}</strong> · {{ z.event_name }}</span><button type="button" class="btn-soft" @click="removeZone(z)">Retirer</button></li>
            </ul>
            <div v-if="zones.length" class="flex flex-wrap items-center gap-2">
              <select v-model="zoneChoice[p.id]" class="field" :aria-label="'Zone à associer à ' + p.name" :data-testid="'zone-choix-' + p.code"><option value="">Choisir une zone…</option><option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }} · {{ z.event_name }}</option></select>
              <button type="button" class="btn-soft" :disabled="!zoneChoice[p.id]" :data-testid="'zone-ajouter-' + p.code" @click="addZone(p)">Associer cette zone</button>
            </div>
          </div>
        </article>
      </section>

      <section class="admin-card space-y-3" aria-labelledby="sans-poste-titre" data-testid="tablettes-sans-poste">
        <h2 id="sans-poste-titre" class="text-heading font-bold">Tablettes sans poste ({{ unassigned.length }})</h2>
        <p class="text-slate-700">Une tablette sans poste n’a aucune capacité de poste : elle ne sert qu’aux opérations de groupe autorisées par le rôle de l’agent.</p>
        <p v-if="!unassigned.length">Toutes les tablettes sont affectées à un poste.</p>
        <ul v-else class="space-y-2">
          <li v-for="t in unassigned" :key="t.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3" :data-testid="'tablette-' + t.id">
            <span><strong>{{ t.name }}</strong> · {{ t.event_name }} · {{ stateLabel[t.state] }}</span>
            <span class="flex flex-wrap items-center gap-2">
              <select v-model="target[t.id]" class="field" :aria-label="'Poste de ' + t.name" :data-testid="'cible-' + t.id"><option value="">Choisir un poste…</option><option v-for="q in activePosts" :key="q.id" :value="q.id">{{ q.name }}</option></select>
              <button type="button" class="btn-soft" :disabled="!target[t.id]" :data-testid="'affecter-' + t.id" @click="assign(t)">Affecter</button>
            </span>
          </li>
        </ul>
      </section>

      <section class="admin-card space-y-3" aria-labelledby="creer-poste-titre">
        <h2 id="creer-poste-titre" class="text-heading font-bold">Créer un poste</h2>
        <form class="space-y-3" @submit.prevent="createPost">
          <div class="grid gap-3 sm:grid-cols-3">
            <label class="block">Code (lettres minuscules, chiffres, tiret bas)<input v-model="create.code" class="field" maxlength="20" required data-testid="nouveau-code"></label>
            <label class="block">Nom<input v-model="create.name" class="field" maxlength="120" required data-testid="nouveau-nom"></label>
            <label class="block">Fonction<input v-model="create.function_label" class="field" maxlength="160" data-testid="nouvelle-fonction"></label>
          </div>
          <fieldset class="space-y-2">
            <legend class="font-semibold">Opérations autorisées</legend>
            <label v-for="(label, capability) in catalogue" :key="capability" class="flex min-h-12 items-center gap-3"><input type="checkbox" :checked="create.capabilities.includes(capability)" :data-testid="'nouvelle-capacite-' + capability" @change="toggleCreate(capability, ($event.target as HTMLInputElement).checked)">{{ label }}</label>
            <label class="flex min-h-12 items-center gap-3"><input v-model="create.direction_required" type="checkbox" :disabled="!create.capabilities.includes('scan')">Choix Entrée / Sortie obligatoire</label>
          </fieldset>
          <button type="submit" class="btn-main" data-testid="creer-poste">Créer le poste</button>
        </form>
      </section>

      <section class="admin-card space-y-3" aria-labelledby="historique-titre" data-testid="historique-affectations">
        <h2 id="historique-titre" class="text-heading font-bold">Historique des affectations</h2>
        <p v-if="!history.length">Aucune affectation enregistrée.</p>
        <ul v-else class="space-y-1">
          <li v-for="h in history" :key="h.id"><strong>{{ h.device }}</strong> → {{ h.checkpoint ?? 'sans poste' }} · depuis {{ displayDate(h.assigned_at) }}<template v-if="h.ended_at"> jusqu’au {{ displayDate(h.ended_at) }}</template><template v-else> (en cours)</template><template v-if="h.by"> · par {{ h.by }}</template><template v-if="h.reason"> · {{ h.reason }}</template></li>
        </ul>
      </section>
    </div>
  </AdminLayout>
</template>
