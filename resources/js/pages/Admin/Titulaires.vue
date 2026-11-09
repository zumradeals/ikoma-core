<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BadgesCartesShell from '../../components/admin/BadgesCartesShell.vue';

type Category = { code: string; label: string; color: string; symbol: string };
type Holder = {
  id: string; identifier: string; full_name: string; first_name: string | null; last_name: string | null; organisation: string | null; position: string | null; quality: string | null;
  institutional_category: string | null; category_label: string | null; category_color: string | null; category_symbol: string | null;
  engagement_starts_on: string | null; engagement_ends_on: string | null; status: 'actif' | 'clos'; closed_at: string | null; closed_reason: string | null;
  has_photo: boolean; photo_print_allowed: boolean;
};
type Page = { data: Holder[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
type ImportRow = { line: number; status: 'ok' | 'homonyme' | 'doublon_registre' | 'doublon_fichier' | 'erreur'; message: string | null; name: string; organisation: string | null; position: string | null; quality: string | null; category: string | null };
type ImportPreview = {
  id: string; filename: string; expires_at: number;
  summary: { lignes: number; a_creer: number; homonymes: number; doublons_registre: number; doublons_fichier: number; erreurs: number };
  columns: { recognized: string[]; ignored: string[] }; rows: ImportRow[];
};
const props = defineProps<{
  holders: Page; filters: { q: string; statut: string; categorie: string; photo: string }; categories: Category[]; importPreview: ImportPreview | null; bulkMax: number;
  identifierFormat: { prefix: string; width: number; check: boolean }; identifierExample: string; permanentContext: { id: string; name: string } | null; canConfigure: boolean; canCards: boolean;
  pipeline: { sans_carte: number; sans_photo: number; a_imprimer: number; a_remettre: number; actives: number; prochaine_remise: string | null } | null;
}>();

// --- Recherche et filtres ---------------------------------------------------------------------------------------------------------
const search = useForm({ q: props.filters.q, statut: props.filters.statut, categorie: props.filters.categorie, photo: props.filters.photo });
function runSearch() { search.get('/administration/titulaires', { preserveState: true, preserveScroll: true }); }

// --- Ajouter une personne ---------------------------------------------------------------------------------------------------------
const empty = { first_name: '', last_name: '', full_name: '', organisation: '', position: '', quality: '', institutional_category: '', engagement_starts_on: '', engagement_ends_on: '', confirm_homonym: false };
const createMode = ref<'split' | 'full'>('split');
const create = useForm({ ...empty });
function submitCreate() {
  create.transform(d => (createMode.value === 'split' ? { ...d, full_name: '' } : { ...d, first_name: '', last_name: '' }))
    .post('/administration/titulaires', { preserveScroll: true, onSuccess: () => create.reset() });
}

// --- Modifier une personne (et sa photo) ------------------------------------------------------------------------------------------
const editing = ref<string | null>(null);
const editMode = ref<'split' | 'full'>('split');
const edit = useForm({ ...empty });
function startEdit(h: Holder) {
  editing.value = h.id; closing.value = null;
  editMode.value = h.first_name ? 'split' : 'full';
  edit.defaults({
    first_name: h.first_name ?? '', last_name: h.last_name ?? '', full_name: h.full_name, organisation: h.organisation ?? '', position: h.position ?? '', quality: h.quality ?? '',
    institutional_category: h.institutional_category ?? '', engagement_starts_on: h.engagement_starts_on ?? '', engagement_ends_on: h.engagement_ends_on ?? '', confirm_homonym: false,
  }).reset();
  photo.reset(); photo.clearErrors();
}
function submitEdit(h: Holder) {
  // Mode « nom complet » : le prénom et le nom restent vides (fiche non découpée) ; mode « prénom + nom » : le nom complet en est déduit par le serveur.
  edit.transform(d => (editMode.value === 'split' ? { ...d, full_name: '' } : { ...d, first_name: '', last_name: '' }))
    .put('/administration/titulaires/' + h.id, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
}
const photo = useForm<{ photo: File | null; print_allowed: boolean }>({ photo: null, print_allowed: false });
function submitPhoto(h: Holder) { photo.post('/administration/titulaires/' + h.id + '/portrait', { forceFormData: true, preserveScroll: true, onSuccess: () => photo.reset() }); }
function removePhoto(h: Holder) { router.delete('/administration/titulaires/' + h.id + '/portrait', { preserveScroll: true }); }
function photoLabel(h: Holder) { return !h.has_photo ? 'Pas de photo' : h.photo_print_allowed ? 'Photo prête pour la carte' : 'Photo enregistrée, impression non autorisée'; }

// --- Clôture ----------------------------------------------------------------------------------------------------------------------
const closing = ref<string | null>(null);
const closeForm = useForm({ reason: '', confirmation: false });
function startClose(h: Holder) { closing.value = h.id; editing.value = null; closeForm.reset(); closeForm.clearErrors(); }
function submitClose(h: Holder) { closeForm.post('/administration/titulaires/' + h.id + '/cloturer', { preserveScroll: true, onSuccess: () => { closing.value = null; } }); }

// --- Sélection multiple -----------------------------------------------------------------------------------------------------------
const selected = ref<string[]>([]);
const selectable = computed(() => props.holders.data.filter(h => h.status === 'actif'));
watch(() => props.holders.data, () => { selected.value = selected.value.filter(id => selectable.value.some(h => h.id === id)); });
const allSelected = computed(() => selectable.value.length > 0 && selected.value.length === selectable.value.length);
function toggleAll() { selected.value = allSelected.value ? [] : selectable.value.map(h => h.id); }
const bulkCategory = ref('');
const bulkClosing = ref(false);
const bulkClose = useForm({ reason: '', confirmation: false });
function applyBulkCategory() {
  router.post('/administration/titulaires/groupe/categorie', { ids: selected.value, category: bulkCategory.value }, { preserveScroll: true, onSuccess: () => { selected.value = []; } });
}
function submitBulkClose() {
  bulkClose.transform(d => ({ ...d, ids: selected.value })).post('/administration/titulaires/groupe/cloturer', { preserveScroll: true, onSuccess: () => { selected.value = []; bulkClosing.value = false; bulkClose.reset(); } });
}

// --- Import d'un fichier ----------------------------------------------------------------------------------------------------------
const importForm = useForm<{ file: File | null }>({ file: null });
function onFile(e: Event) { importForm.file = (e.target as HTMLInputElement).files?.[0] ?? null; }
function preparePreview() { importForm.post('/administration/titulaires/import/apercu', { forceFormData: true, preserveScroll: true, onSuccess: () => importForm.reset() }); }
const confirmLines = ref<number[]>([]);
watch(() => props.importPreview?.id, () => { confirmLines.value = []; });
const willCreate = computed(() => (props.importPreview?.summary.a_creer ?? 0) + confirmLines.value.length);
function confirmImport() {
  if (!props.importPreview) return;
  router.post('/administration/titulaires/import/confirmer', { token: props.importPreview.id, confirm_lines: confirmLines.value }, { preserveScroll: true });
}
function abandonImport() {
  if (!props.importPreview) return;
  router.post('/administration/titulaires/import/abandonner', { token: props.importPreview.id }, { preserveScroll: true });
}
const rowLabel: Record<ImportRow['status'], string> = { ok: 'À enregistrer', homonyme: 'À confirmer', doublon_registre: 'Déjà enregistrée', doublon_fichier: 'Répétée dans le fichier', erreur: 'Erreur' };
const rowClass: Record<ImportRow['status'], string> = { ok: 'bg-green-100 text-green-900', homonyme: 'bg-amber-100 text-amber-900', doublon_registre: 'bg-slate-200 text-slate-800', doublon_fichier: 'bg-slate-200 text-slate-800', erreur: 'bg-red-100 text-red-900' };

// --- Réglages ---------------------------------------------------------------------------------------------------------------------
const format = useForm({ prefix: props.identifierFormat.prefix, width: props.identifierFormat.width, check: props.identifierFormat.check });
function saveFormat() { format.put('/administration/titulaires/format', { preserveScroll: true }); }
function prepareAll() { router.post('/administration/titulaires/cartes/preparer-toutes', {}, { preserveScroll: true }); }
function createContext() { router.post('/administration/titulaires/contexte-permanent', {}, { preserveScroll: true }); }
</script>

<template>
  <AdminLayout active="titulaires">
    <BadgesCartesShell active="cartes" />
    <div class="space-y-6" data-testid="page-titulaires">
      <header class="col-span-full space-y-2">
        <h1 class="text-display font-bold">Registre des personnes</h1>
        <p>Les personnes de l’institution (personnel, partenaires, stagiaires…), enregistrées une seule fois. Chacune reçoit un numéro stable, jamais réattribué. <strong>Enregistrer une personne ne crée aucune carte et ne donne aucun accès</strong> : la carte se prépare à part, depuis la fiche.</p>
        <p class="text-sm font-semibold" data-testid="contexte-permanent">
          Espace des cartes permanentes :
          <span v-if="permanentContext">en place ({{ permanentContext.name }}), il ne se ferme ni ne se supprime.</span>
          <span v-else>pas encore créé (à faire une fois avant de préparer des cartes). <button type="button" class="btn-soft ml-2" data-testid="creer-contexte" @click="createContext">Créer cet espace</button></span>
        </p>
      </header>

      <section v-if="canCards && pipeline && permanentContext" class="col-span-full grid gap-4 rounded-2xl border bg-white p-5 md:grid-cols-3" aria-label="Où en sont les cartes" data-testid="parcours-cartes">
        <div class="space-y-2 rounded-xl border-2 p-4" :class="pipeline.sans_carte > 0 ? 'border-[#0066B3]' : 'border-slate-200'" data-testid="etape-preparer">
          <h2 class="flex items-center gap-2 text-lg font-bold"><span class="grid size-8 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">1</span>Préparer</h2>
          <p><strong class="text-2xl" data-testid="nb-sans-carte">{{ pipeline.sans_carte }}</strong> personne(s) sans carte, avec photo.</p>
          <p v-if="pipeline.sans_photo > 0" class="text-sm" data-testid="nb-sans-photo">{{ pipeline.sans_photo }} autre(s) sans photo : ajoutez d’abord leur photo.</p>
          <button type="button" class="admin-primary" :disabled="pipeline.sans_carte === 0" data-testid="tout-preparer" @click="prepareAll">Tout préparer</button>
        </div>
        <div class="space-y-2 rounded-xl border-2 p-4" :class="pipeline.a_imprimer > 0 ? 'border-[#0066B3]' : 'border-slate-200'" data-testid="etape-imprimer">
          <h2 class="flex items-center gap-2 text-lg font-bold"><span class="grid size-8 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">2</span>Imprimer</h2>
          <p><strong class="text-2xl" data-testid="nb-a-imprimer">{{ pipeline.a_imprimer }}</strong> carte(s) à imprimer.</p>
          <Link href="/administration/atelier?mode=cartes" class="admin-primary inline-block" data-testid="aller-atelier">Ouvrir l’Atelier des badges</Link>
        </div>
        <div class="space-y-2 rounded-xl border-2 p-4" :class="pipeline.a_remettre > 0 ? 'border-[#0066B3]' : 'border-slate-200'" data-testid="etape-remettre">
          <h2 class="flex items-center gap-2 text-lg font-bold"><span class="grid size-8 place-items-center rounded-full bg-[#0B3C6B] text-white" aria-hidden="true">3</span>Remettre et activer</h2>
          <p><strong class="text-2xl" data-testid="nb-a-remettre">{{ pipeline.a_remettre }}</strong> carte(s) imprimée(s) à remettre. <span class="text-sm" data-testid="nb-actives">{{ pipeline.actives }} déjà active(s).</span></p>
          <Link v-if="pipeline.prochaine_remise" :href="'/administration/titulaires/' + pipeline.prochaine_remise + '/carte'" class="admin-primary inline-block" data-testid="remettre-suivante">Ouvrir la prochaine fiche</Link>
        </div>
      </section>

      <p v-if="canCards" class="col-span-full text-sm" data-testid="navigation-cartes">Cartes permanentes : <Link href="/administration/titulaires/modeles" class="font-semibold underline" data-testid="lien-modeles">personnalisation des cartes</Link> · <Link href="/administration/atelier?mode=cartes" class="font-semibold underline" data-testid="lien-atelier-cartes-registre">imprimer des cartes</Link> · <Link href="/administration/habilitations" class="font-semibold underline" data-testid="lien-habilitations-registre">habilitations et zones</Link>. Les badges des visiteurs temporaires se gèrent dans <Link href="/administration/badges" class="font-semibold underline" data-testid="lien-badges-visiteurs">Badges visiteurs</Link>.</p>

      <form class="col-span-full grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto]" role="search" @submit.prevent="runSearch">
        <label class="font-semibold">Rechercher (nom, fonction, organisation, numéro)<input v-model="search.q" class="field mt-1" data-testid="recherche-titulaires" placeholder="Nom ou numéro"></label>
        <label class="font-semibold">État<select v-model="search.statut" class="field mt-1" data-testid="filtre-statut"><option value="actif">En cours</option><option value="clos">Clôturées</option><option value="tous">Toutes</option></select></label>
        <label class="font-semibold">Catégorie<select v-model="search.categorie" class="field mt-1" data-testid="filtre-categorie"><option value="">Toutes</option><option value="aucune">Sans catégorie</option><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.code }} · {{ c.label }}</option></select></label>
        <label class="font-semibold">Photo<select v-model="search.photo" class="field mt-1" data-testid="filtre-photo"><option value="">Toutes</option><option value="avec">Avec photo</option><option value="sans">Sans photo</option></select></label>
        <button type="submit" class="btn-soft self-end" data-testid="lancer-recherche">Rechercher</button>
      </form>

      <details class="col-span-full rounded-xl border bg-white p-4" data-testid="bloc-creation">
        <summary class="cursor-pointer text-heading font-bold">Ajouter une personne</summary>
        <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
          <fieldset class="flex flex-wrap gap-4 sm:col-span-2">
            <legend class="font-semibold">Comment saisir le nom ?</legend>
            <label class="flex items-center gap-2"><input v-model="createMode" type="radio" value="split" class="size-5" data-testid="mode-prenom-nom"> Prénom et nom séparés</label>
            <label class="flex items-center gap-2"><input v-model="createMode" type="radio" value="full" class="size-5" data-testid="mode-nom-complet"> Nom complet seul</label>
          </fieldset>
          <template v-if="createMode === 'split'">
            <label class="font-semibold">Prénom<input v-model="create.first_name" class="field mt-1" maxlength="100" required data-testid="creer-prenom"><span v-if="create.errors.first_name" class="block text-sm text-red-800" role="alert">{{ create.errors.first_name }}</span></label>
            <label class="font-semibold">Nom<input v-model="create.last_name" class="field mt-1" maxlength="100" required data-testid="creer-nom"><span v-if="create.errors.last_name" class="block text-sm text-red-800" role="alert">{{ create.errors.last_name }}</span><span v-if="create.errors.full_name" class="block text-sm text-red-800" role="alert">{{ create.errors.full_name }}</span></label>
          </template>
          <label v-else class="font-semibold sm:col-span-2">Nom complet<input v-model="create.full_name" class="field mt-1" required maxlength="160" data-testid="creer-nom-complet"><span v-if="create.errors.full_name" class="block text-sm text-red-800" role="alert">{{ create.errors.full_name }}</span></label>
          <label class="font-semibold">Organisation<input v-model="create.organisation" class="field mt-1" maxlength="160" data-testid="creer-organisation"></label>
          <label class="font-semibold">Fonction<input v-model="create.position" class="field mt-1" maxlength="160" data-testid="creer-fonction"><span class="block text-sm font-normal">Ce que la personne fait (par exemple « Comptable »).</span></label>
          <label class="font-semibold">Qualité<input v-model="create.quality" class="field mt-1" maxlength="160" data-testid="creer-qualite"><span class="block text-sm font-normal">Le statut de la personne (par exemple « Stagiaire », « Représentant »).</span></label>
          <label class="font-semibold">Catégorie institutionnelle
            <select v-model="create.institutional_category" class="field mt-1" data-testid="creer-categorie"><option value="">Aucune</option><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.code }} · {{ c.label }}</option></select>
            <span class="block text-sm font-normal">Simple étiquette : elle ne donne aucun accès.</span>
          </label>
          <label class="font-semibold">Début d’engagement<input v-model="create.engagement_starts_on" type="date" class="field mt-1" data-testid="creer-debut"></label>
          <label class="font-semibold">Fin d’engagement (facultatif)<input v-model="create.engagement_ends_on" type="date" class="field mt-1" data-testid="creer-fin"><span v-if="create.errors.engagement_ends_on" class="block text-sm text-red-800" role="alert">{{ create.errors.engagement_ends_on }}</span></label>
          <p v-if="create.errors.confirm_homonym" class="rounded-lg border-2 border-amber-600 bg-amber-50 p-3 sm:col-span-2" role="alert" data-testid="alerte-homonyme">
            {{ create.errors.confirm_homonym }}
            <label class="mt-2 flex items-center gap-2 font-semibold"><input v-model="create.confirm_homonym" type="checkbox" class="size-6" data-testid="confirmer-homonyme"> C’est une autre personne</label>
          </p>
          <p class="text-sm sm:col-span-2">La photo se joint ensuite, depuis la fiche (bouton « Modifier »).</p>
          <div class="sm:col-span-2"><button type="submit" class="btn-soft" :disabled="create.processing" data-testid="enregistrer-titulaire">Enregistrer la personne</button></div>
        </form>
      </details>

      <details class="col-span-full rounded-xl border bg-white p-4" data-testid="bloc-import" :open="importPreview !== null">
        <summary class="cursor-pointer text-heading font-bold">Importer plusieurs personnes (fichier CSV ou Excel)</summary>
        <div class="mt-4 space-y-4">
          <p>Colonnes reconnues : <strong>prénom</strong> + <strong>nom</strong>, ou <strong>nom complet</strong> seul (jamais découpé) ; puis organisation, fonction, qualité, catégorie, début_engagement, fin_engagement (facultatives). {{ 500 }} lignes au plus. <a href="/administration/titulaires/import/modele" class="font-semibold underline" data-testid="modele-import">Télécharger un modèle de fichier</a></p>
          <p class="text-sm">Rien n’est enregistré avant votre confirmation. L’import ne crée aucune carte.</p>
          <form v-if="!importPreview" class="flex flex-wrap items-end gap-3" @submit.prevent="preparePreview">
            <label class="font-semibold">Fichier<input type="file" accept=".csv,.xlsx" class="field mt-1" data-testid="import-fichier" @change="onFile"></label>
            <button type="submit" class="btn-soft" :disabled="importForm.processing || !importForm.file" data-testid="import-apercu">Préparer l’aperçu</button>
            <span v-if="importForm.errors.file" class="block text-sm text-red-800" role="alert" data-testid="import-erreur">{{ importForm.errors.file }}</span>
          </form>

          <section v-else class="space-y-3" data-testid="import-resume" aria-label="Aperçu de l’import">
            <p class="font-semibold">Fichier « {{ importPreview.filename }} » : {{ importPreview.summary.lignes }} ligne(s) lues.</p>
            <ul class="flex flex-wrap gap-2 text-sm font-semibold">
              <li class="rounded-lg bg-green-100 px-3 py-1 text-green-900" data-testid="resume-ok">{{ importPreview.summary.a_creer }} à enregistrer</li>
              <li class="rounded-lg bg-amber-100 px-3 py-1 text-amber-900" data-testid="resume-homonymes">{{ importPreview.summary.homonymes }} à confirmer</li>
              <li class="rounded-lg bg-slate-200 px-3 py-1" data-testid="resume-doublons">{{ importPreview.summary.doublons_registre + importPreview.summary.doublons_fichier }} doublon(s)</li>
              <li class="rounded-lg bg-red-100 px-3 py-1 text-red-900" data-testid="resume-erreurs">{{ importPreview.summary.erreurs }} erreur(s)</li>
            </ul>
            <p v-if="importPreview.columns.ignored.length" class="text-sm">Colonnes ignorées : {{ importPreview.columns.ignored.join(', ') }}.</p>
            <div class="overflow-x-auto">
              <table class="w-full text-left text-sm">
                <thead><tr><th class="p-2">Ligne</th><th class="p-2">Résultat</th><th class="p-2">Personne</th><th class="p-2">Organisation</th><th class="p-2">Fonction · qualité</th><th class="p-2">Catégorie</th><th class="p-2">Remarque</th></tr></thead>
                <tbody>
                  <tr v-for="r in importPreview.rows" :key="r.line" class="border-t" :data-testid="'import-ligne-' + r.line">
                    <td class="p-2">{{ r.line }}</td>
                    <td class="p-2"><span class="rounded px-2 py-1 font-bold" :class="rowClass[r.status]">{{ rowLabel[r.status] }}</span></td>
                    <td class="p-2 font-semibold">{{ r.name || '—' }}</td>
                    <td class="p-2">{{ r.organisation ?? '—' }}</td>
                    <td class="p-2">{{ [r.position, r.quality].filter(Boolean).join(' · ') || '—' }}</td>
                    <td class="p-2">{{ r.category ?? '—' }}</td>
                    <td class="p-2">{{ r.message ?? '' }}<label v-if="r.status === 'homonyme'" class="mt-1 flex items-center gap-2 font-semibold"><input v-model="confirmLines" type="checkbox" :value="r.line" class="size-5" :data-testid="'confirmer-ligne-' + r.line"> C’est une autre personne : l’enregistrer</label></td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="text-sm">Les lignes en erreur, les doublons et les homonymes non confirmés ne sont pas enregistrés. L’enregistrement est « tout ou rien » : si un problème survient, rien n’est créé.</p>
            <div class="flex flex-wrap gap-3">
              <button type="button" class="admin-primary" :disabled="willCreate === 0" data-testid="import-confirmer" @click="confirmImport">Enregistrer {{ willCreate }} personne(s)</button>
              <button type="button" class="btn-soft" data-testid="import-abandonner" @click="abandonImport">Abandonner</button>
            </div>
          </section>
        </div>
      </details>

      <section aria-label="Liste des personnes" class="col-span-full space-y-3">
        <p class="font-semibold" data-testid="total-titulaires">{{ holders.total }} personne(s)</p>

        <div v-if="selectable.length > 0" class="flex flex-wrap items-center gap-3 rounded-xl border bg-white p-3" data-testid="barre-selection">
          <label class="flex items-center gap-2 font-semibold"><input type="checkbox" class="size-6" :checked="allSelected" data-testid="selectionner-tout" @change="toggleAll"> Tout sélectionner sur cette page</label>
          <span class="text-sm" data-testid="compteur-selection">{{ selected.length }} sélectionnée(s) (au plus {{ bulkMax }})</span>
          <template v-if="selected.length > 0">
            <label class="flex items-center gap-2 font-semibold">Catégorie
              <select v-model="bulkCategory" class="field" data-testid="groupe-categorie"><option value="">Aucune catégorie</option><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.code }} · {{ c.label }}</option></select>
            </label>
            <button type="button" class="btn-soft" data-testid="groupe-appliquer" @click="applyBulkCategory">Appliquer la catégorie</button>
            <button type="button" class="btn-soft" data-testid="groupe-clore" @click="bulkClosing = !bulkClosing">Clore la sélection…</button>
          </template>
        </div>
        <form v-if="bulkClosing && selected.length > 0" class="space-y-3 rounded-xl border-2 border-amber-600 bg-amber-50 p-4" data-testid="groupe-cloture" @submit.prevent="submitBulkClose">
          <p class="font-semibold">La clôture est définitive pour les {{ selected.length }} personne(s) sélectionnée(s) : elles ne se modifient plus et leur numéro n’est jamais réattribué.</p>
          <label class="block font-semibold">Motif (le même pour toutes)<input v-model="bulkClose.reason" class="field mt-1" required maxlength="200" data-testid="groupe-motif"><span v-if="bulkClose.errors.reason" class="block text-sm text-red-800" role="alert">{{ bulkClose.errors.reason }}</span></label>
          <label class="flex items-center gap-2 font-semibold"><input v-model="bulkClose.confirmation" type="checkbox" class="size-6" data-testid="groupe-confirmer"> Je confirme la clôture</label>
          <div class="flex gap-3"><button type="submit" class="btn-soft" :disabled="bulkClose.processing || !bulkClose.confirmation" data-testid="groupe-valider">Clore la sélection</button><button type="button" class="btn-soft" @click="bulkClosing = false">Annuler</button></div>
        </form>

        <p v-if="holders.data.length === 0" class="rounded-xl border bg-white p-5" data-testid="aucun-titulaire">Aucune personne ne correspond. Ce registre se remplit après confirmation, par l’institution, de la base légale et de la durée de conservation.</p>
        <article v-for="h in holders.data" :key="h.id" class="rounded-xl border bg-white p-4" :data-testid="'titulaire-' + h.id">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
              <input v-if="h.status === 'actif'" v-model="selected" type="checkbox" :value="h.id" class="mt-1 size-6" :aria-label="'Sélectionner ' + h.full_name" :data-testid="'choisir-' + h.id">
              <img v-if="h.has_photo" :src="'/administration/titulaires/' + h.id + '/portrait'" :alt="'Photo de ' + h.full_name" class="size-16 rounded-lg border object-cover" loading="lazy" :data-testid="'photo-' + h.id">
              <span v-else class="grid size-16 place-items-center rounded-lg border bg-slate-100 text-xs font-semibold text-slate-600" aria-hidden="true">Pas de photo</span>
              <div>
                <p class="text-lg font-bold">{{ h.full_name }}</p>
                <p class="text-sm">{{ [h.position, h.quality, h.organisation].filter(Boolean).join(' · ') || '—' }}</p>
                <p class="mt-1 font-mono text-sm font-bold" data-testid="identifiant-titulaire">{{ h.identifier }}</p>
                <p class="text-sm" data-testid="etat-photo">{{ photoLabel(h) }}</p>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span v-if="h.institutional_category" class="inline-flex items-center gap-2 rounded-lg border px-3 py-1 text-sm font-semibold"><span class="inline-grid size-6 place-items-center rounded text-xs font-bold text-white" :style="{ backgroundColor: h.category_color ?? '#64748B' }" aria-hidden="true">{{ h.category_symbol }}</span>{{ h.institutional_category }} · {{ h.category_label }}</span>
              <span class="rounded-lg px-3 py-1 text-sm font-bold" :class="h.status === 'actif' ? 'bg-green-100 text-green-900' : 'bg-slate-200 text-slate-800'" data-testid="etat-titulaire">{{ h.status === 'actif' ? 'En cours' : 'Clôturée' }}</span>
            </div>
          </div>
          <p v-if="h.engagement_starts_on || h.engagement_ends_on" class="mt-2 text-sm">Engagement (indicatif, n’ouvre ni ne ferme aucun accès) : {{ h.engagement_starts_on ?? '…' }} → {{ h.engagement_ends_on ?? 'sans fin prévue' }}</p>
          <p v-if="h.status === 'clos'" class="mt-2 text-sm">Clôturée : {{ h.closed_reason }}</p>
          <div v-if="h.status === 'actif'" class="mt-3 flex flex-wrap gap-3">
            <button type="button" class="btn-soft" :data-testid="'modifier-' + h.id" @click="startEdit(h)">Modifier</button>
            <button type="button" class="btn-soft" :data-testid="'clore-' + h.id" @click="startClose(h)">Clore</button>
            <Link v-if="canCards" :href="'/administration/titulaires/' + h.id + '/carte'" class="btn-soft" :data-testid="'carte-' + h.id">Carte</Link>
          </div>

          <div v-if="editing === h.id" class="mt-4 space-y-4 border-t pt-4">
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitEdit(h)">
              <fieldset class="flex flex-wrap gap-4 sm:col-span-2">
                <legend class="font-semibold">Nom</legend>
                <label class="flex items-center gap-2"><input v-model="editMode" type="radio" value="split" class="size-5"> Prénom et nom séparés</label>
                <label class="flex items-center gap-2"><input v-model="editMode" type="radio" value="full" class="size-5" data-testid="edit-mode-complet"> Nom complet seul</label>
              </fieldset>
              <template v-if="editMode === 'split'">
                <label class="font-semibold">Prénom<input v-model="edit.first_name" class="field mt-1" maxlength="100" required data-testid="edit-prenom"><span v-if="edit.errors.first_name" class="block text-sm text-red-800" role="alert">{{ edit.errors.first_name }}</span></label>
                <label class="font-semibold">Nom<input v-model="edit.last_name" class="field mt-1" maxlength="100" required data-testid="edit-nom-famille"><span v-if="edit.errors.last_name" class="block text-sm text-red-800" role="alert">{{ edit.errors.last_name }}</span><span v-if="edit.errors.full_name" class="block text-sm text-red-800" role="alert">{{ edit.errors.full_name }}</span></label>
              </template>
              <label v-else class="font-semibold sm:col-span-2">Nom complet<input v-model="edit.full_name" class="field mt-1" required maxlength="160" data-testid="edit-nom"><span v-if="edit.errors.full_name" class="block text-sm text-red-800" role="alert">{{ edit.errors.full_name }}</span></label>
              <label class="font-semibold">Organisation<input v-model="edit.organisation" class="field mt-1" maxlength="160"></label>
              <label class="font-semibold">Fonction<input v-model="edit.position" class="field mt-1" maxlength="160"></label>
              <label class="font-semibold">Qualité<input v-model="edit.quality" class="field mt-1" maxlength="160" data-testid="edit-qualite"></label>
              <label class="font-semibold">Catégorie institutionnelle<select v-model="edit.institutional_category" class="field mt-1"><option value="">Aucune</option><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.code }} · {{ c.label }}</option></select></label>
              <label class="font-semibold">Début d’engagement<input v-model="edit.engagement_starts_on" type="date" class="field mt-1"></label>
              <label class="font-semibold">Fin d’engagement<input v-model="edit.engagement_ends_on" type="date" class="field mt-1"></label>
              <p v-if="edit.errors.confirm_homonym" class="rounded-lg border-2 border-amber-600 bg-amber-50 p-3 sm:col-span-2" role="alert">{{ edit.errors.confirm_homonym }}<label class="mt-2 flex items-center gap-2 font-semibold"><input v-model="edit.confirm_homonym" type="checkbox" class="size-6"> C’est une autre personne</label></p>
              <p class="text-sm sm:col-span-2">Le numéro <strong>{{ h.identifier }}</strong> ne change jamais.</p>
              <div class="flex gap-3 sm:col-span-2"><button type="submit" class="btn-soft" :disabled="edit.processing" data-testid="enregistrer-modification">Enregistrer</button><button type="button" class="btn-soft" @click="editing = null">Annuler</button></div>
            </form>

            <form class="space-y-3 rounded-lg border p-3" data-testid="bloc-photo" @submit.prevent="submitPhoto(h)">
              <h3 class="font-bold">Photographie</h3>
              <p class="text-sm">{{ photoLabel(h) }}. La photo est conservée chiffrée ; elle n’est imprimée sur la carte que si vous l’autorisez.</p>
              <label class="block font-semibold">Choisir une photo (JPEG ou PNG, 5 Mo au plus)<input type="file" accept="image/jpeg,image/png" class="field mt-1" data-testid="photo-fichier" @change="(e: Event) => { photo.photo = (e.target as HTMLInputElement).files?.[0] ?? null; }"></label>
              <label class="flex items-center gap-2 font-semibold"><input v-model="photo.print_allowed" type="checkbox" class="size-6" data-testid="photo-imprimable"> Autoriser l’impression de la photo sur la carte</label>
              <span v-if="photo.errors.photo" class="block text-sm text-red-800" role="alert">{{ photo.errors.photo }}</span>
              <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-soft" :disabled="photo.processing || !photo.photo" data-testid="photo-enregistrer">Enregistrer la photo</button>
                <button v-if="h.has_photo" type="button" class="btn-soft" data-testid="photo-retirer" @click="removePhoto(h)">Retirer la photo</button>
              </div>
            </form>
          </div>

          <form v-if="closing === h.id" class="mt-4 space-y-3 border-t pt-4" @submit.prevent="submitClose(h)">
            <p class="font-semibold">La clôture est définitive : la fiche ne se modifie plus et son numéro n’est jamais réattribué.</p>
            <label class="block font-semibold">Motif de clôture<input v-model="closeForm.reason" class="field mt-1" required maxlength="200" data-testid="motif-cloture"><span v-if="closeForm.errors.reason" class="block text-sm text-red-800" role="alert">{{ closeForm.errors.reason }}</span></label>
            <label class="flex items-center gap-2 font-semibold"><input v-model="closeForm.confirmation" type="checkbox" class="size-6" data-testid="confirmer-cloture"> Je confirme la clôture</label>
            <div class="flex gap-3"><button type="submit" class="btn-soft" :disabled="closeForm.processing || !closeForm.confirmation" data-testid="valider-cloture">Clore cette personne</button><button type="button" class="btn-soft" @click="closing = null">Annuler</button></div>
          </form>
        </article>
        <nav v-if="holders.links.length > 3" class="flex flex-wrap gap-2" aria-label="Pagination">
          <template v-for="link in holders.links" :key="link.label"><Link v-if="link.url" :href="link.url" class="btn-soft" :class="link.active ? 'font-bold underline' : ''" preserve-scroll>{{ link.label.replace('&laquo; Previous', 'Précédent').replace('Next &raquo;', 'Suivant') }}</Link></template>
        </nav>
      </section>

      <section v-if="canConfigure" class="col-span-full space-y-3 rounded-xl border bg-white p-4">
        <h2 class="text-heading font-bold">Format du numéro imprimable</h2>
        <p>Exemple du prochain numéro : <strong class="font-mono" data-testid="exemple-identifiant">{{ identifierExample }}</strong>. Le format n’est pas publié tant que l’institution ne l’a pas validé ; les numéros déjà émis ne changent jamais.</p>
        <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="saveFormat">
          <label class="font-semibold">Préfixe (lettres majuscules et chiffres, facultatif)<input v-model="format.prefix" class="field mt-1" maxlength="8" data-testid="format-prefixe"></label>
          <label class="font-semibold">Nombre de chiffres<input v-model.number="format.width" type="number" min="4" max="12" class="field mt-1" data-testid="format-largeur"></label>
          <label class="flex items-center gap-2 self-end font-semibold"><input v-model="format.check" type="checkbox" class="size-6" data-testid="format-cle"> Clé de contrôle</label>
          <div class="sm:col-span-3"><button type="submit" class="btn-soft" data-testid="enregistrer-format">Enregistrer le format</button></div>
        </form>
      </section>
    </div>
  </AdminLayout>
</template>
