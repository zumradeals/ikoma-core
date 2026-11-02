<script setup lang="ts">
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';

type Category = { code: string; label: string; color: string; symbol: string };
type Holder = {
  id: string; identifier: string; full_name: string; organisation: string | null; position: string | null; institutional_category: string | null;
  category_label: string | null; category_color: string | null; category_symbol: string | null; engagement_starts_on: string | null; engagement_ends_on: string | null;
  status: 'actif' | 'clos'; closed_at: string | null; closed_reason: string | null;
};
type Page = { data: Holder[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
const props = defineProps<{
  holders: Page; filters: { q: string; statut: string; categorie: string }; categories: Category[];
  identifierFormat: { prefix: string; width: number; check: boolean }; identifierExample: string; permanentContext: { id: string; name: string } | null; canConfigure: boolean;
}>();

const search = useForm({ q: props.filters.q, statut: props.filters.statut, categorie: props.filters.categorie });
function runSearch() { search.get('/administration/titulaires', { preserveState: true, preserveScroll: true }); }

const empty = { full_name: '', organisation: '', position: '', institutional_category: '', engagement_starts_on: '', engagement_ends_on: '', confirm_homonym: false };
const create = useForm({ ...empty });
function submitCreate() { create.post('/administration/titulaires', { preserveScroll: true, onSuccess: () => create.reset() }); }

const editing = ref<string | null>(null);
const edit = useForm({ ...empty });
function startEdit(h: Holder) {
  editing.value = h.id; closing.value = null;
  edit.defaults({ full_name: h.full_name, organisation: h.organisation ?? '', position: h.position ?? '', institutional_category: h.institutional_category ?? '', engagement_starts_on: h.engagement_starts_on ?? '', engagement_ends_on: h.engagement_ends_on ?? '', confirm_homonym: false }).reset();
}
function submitEdit(h: Holder) { edit.put('/administration/titulaires/' + h.id, { preserveScroll: true, onSuccess: () => { editing.value = null; } }); }

const closing = ref<string | null>(null);
const closeForm = useForm({ reason: '', confirmation: false });
function startClose(h: Holder) { closing.value = h.id; editing.value = null; closeForm.reset(); closeForm.clearErrors(); }
function submitClose(h: Holder) { closeForm.post('/administration/titulaires/' + h.id + '/cloturer', { preserveScroll: true, onSuccess: () => { closing.value = null; } }); }

const format = useForm({ prefix: props.identifierFormat.prefix, width: props.identifierFormat.width, check: props.identifierFormat.check });
function saveFormat() { format.put('/administration/titulaires/format', { preserveScroll: true }); }
function createContext() { router.post('/administration/titulaires/contexte-permanent', {}, { preserveScroll: true }); }
</script>

<template>
  <AdminLayout active="titulaires">
    <div class="space-y-6" data-testid="page-titulaires">
      <header class="col-span-full space-y-2">
        <h1 class="text-display font-bold">Titulaires permanents</h1>
        <p>Registre d’identité des personnes dont la présence ne dépend pas d’un événement : une fiche unique, un identifiant imprimable stable et jamais réattribué. Ce registre n’accorde aucun droit d’accès.</p>
        <p class="text-sm font-semibold" data-testid="contexte-permanent">
          Contexte permanent :
          <span v-if="permanentContext">{{ permanentContext.name }} (protégé : jamais clos, archivé, anonymisé ni supprimé)</span>
          <span v-else>non créé. <button type="button" class="btn-soft ml-2" data-testid="creer-contexte" @click="createContext">Créer le contexte permanent</button></span>
        </p>
      </header>

      <form class="col-span-full grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto]" role="search" @submit.prevent="runSearch">
        <label class="font-semibold">Rechercher (nom, organisation, identifiant)<input v-model="search.q" class="field mt-1" data-testid="recherche-titulaires" placeholder="Nom ou identifiant"></label>
        <label class="font-semibold">État<select v-model="search.statut" class="field mt-1" data-testid="filtre-statut"><option value="actif">Actifs</option><option value="clos">Clos</option><option value="tous">Tous</option></select></label>
        <label class="font-semibold">Catégorie<select v-model="search.categorie" class="field mt-1" data-testid="filtre-categorie"><option value="">Toutes</option><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.code }} · {{ c.label }}</option></select></label>
        <button type="submit" class="btn-soft self-end" data-testid="lancer-recherche">Rechercher</button>
      </form>

      <details class="col-span-full rounded-xl border bg-white p-4" data-testid="bloc-creation">
        <summary class="cursor-pointer text-heading font-bold">Enregistrer un titulaire</summary>
        <form class="mt-4 grid gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
          <label class="font-semibold sm:col-span-2">Nom complet<input v-model="create.full_name" class="field mt-1" required maxlength="160" data-testid="creer-nom"><span v-if="create.errors.full_name" class="block text-sm text-red-800" role="alert">{{ create.errors.full_name }}</span></label>
          <label class="font-semibold">Organisation<input v-model="create.organisation" class="field mt-1" maxlength="160" data-testid="creer-organisation"></label>
          <label class="font-semibold">Fonction<input v-model="create.position" class="field mt-1" maxlength="160" data-testid="creer-fonction"></label>
          <label class="font-semibold">Catégorie institutionnelle
            <select v-model="create.institutional_category" class="field mt-1" data-testid="creer-categorie"><option value="">Aucune</option><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.code }} · {{ c.label }}</option></select>
            <span class="block text-sm font-normal">Étiquette d’identité : elle ne donne aucun accès.</span>
          </label>
          <label class="font-semibold">Début d’engagement<input v-model="create.engagement_starts_on" type="date" class="field mt-1" data-testid="creer-debut"></label>
          <label class="font-semibold">Fin d’engagement (facultatif)<input v-model="create.engagement_ends_on" type="date" class="field mt-1" data-testid="creer-fin"><span v-if="create.errors.engagement_ends_on" class="block text-sm text-red-800" role="alert">{{ create.errors.engagement_ends_on }}</span></label>
          <p v-if="create.errors.confirm_homonym" class="rounded-lg border-2 border-amber-600 bg-amber-50 p-3 sm:col-span-2" role="alert" data-testid="alerte-homonyme">
            {{ create.errors.confirm_homonym }}
            <label class="mt-2 flex items-center gap-2 font-semibold"><input v-model="create.confirm_homonym" type="checkbox" class="size-6" data-testid="confirmer-homonyme"> C’est une autre personne</label>
          </p>
          <div class="sm:col-span-2"><button type="submit" class="btn-soft" :disabled="create.processing" data-testid="enregistrer-titulaire">Enregistrer le titulaire</button></div>
        </form>
      </details>

      <section aria-label="Liste des titulaires" class="col-span-full space-y-3">
        <p class="font-semibold" data-testid="total-titulaires">{{ holders.total }} titulaire(s)</p>
        <p v-if="holders.data.length === 0" class="rounded-xl border bg-white p-5" data-testid="aucun-titulaire">Aucun titulaire ne correspond. Les données de ce registre sont à saisir par l’institution après confirmation de la base légale et de la conservation.</p>
        <article v-for="h in holders.data" :key="h.id" class="rounded-xl border bg-white p-4" :data-testid="'titulaire-' + h.id">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-lg font-bold">{{ h.full_name }}</p>
              <p class="text-sm">{{ [h.position, h.organisation].filter(Boolean).join(' · ') || '—' }}</p>
              <p class="mt-1 font-mono text-sm font-bold" data-testid="identifiant-titulaire">{{ h.identifier }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span v-if="h.institutional_category" class="inline-flex items-center gap-2 rounded-lg border px-3 py-1 text-sm font-semibold"><span class="inline-grid size-6 place-items-center rounded text-xs font-bold text-white" :style="{ backgroundColor: h.category_color ?? '#64748B' }" aria-hidden="true">{{ h.category_symbol }}</span>{{ h.institutional_category }} · {{ h.category_label }}</span>
              <span class="rounded-lg px-3 py-1 text-sm font-bold" :class="h.status === 'actif' ? 'bg-green-100 text-green-900' : 'bg-slate-200 text-slate-800'" data-testid="etat-titulaire">{{ h.status === 'actif' ? 'Actif' : 'Clos' }}</span>
            </div>
          </div>
          <p v-if="h.engagement_starts_on || h.engagement_ends_on" class="mt-2 text-sm">Engagement : {{ h.engagement_starts_on ?? '…' }} → {{ h.engagement_ends_on ?? 'sans fin prévue' }}</p>
          <p v-if="h.status === 'clos'" class="mt-2 text-sm">Clos : {{ h.closed_reason }}</p>
          <div v-if="h.status === 'actif'" class="mt-3 flex flex-wrap gap-3">
            <button type="button" class="btn-soft" :data-testid="'modifier-' + h.id" @click="startEdit(h)">Modifier</button>
            <button type="button" class="btn-soft" :data-testid="'clore-' + h.id" @click="startClose(h)">Clore</button>
          </div>

          <form v-if="editing === h.id" class="mt-4 grid gap-4 border-t pt-4 sm:grid-cols-2" @submit.prevent="submitEdit(h)">
            <label class="font-semibold sm:col-span-2">Nom complet<input v-model="edit.full_name" class="field mt-1" required maxlength="160" data-testid="edit-nom"><span v-if="edit.errors.full_name" class="block text-sm text-red-800" role="alert">{{ edit.errors.full_name }}</span></label>
            <label class="font-semibold">Organisation<input v-model="edit.organisation" class="field mt-1" maxlength="160"></label>
            <label class="font-semibold">Fonction<input v-model="edit.position" class="field mt-1" maxlength="160"></label>
            <label class="font-semibold">Catégorie institutionnelle<select v-model="edit.institutional_category" class="field mt-1"><option value="">Aucune</option><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.code }} · {{ c.label }}</option></select></label>
            <label class="font-semibold">Début d’engagement<input v-model="edit.engagement_starts_on" type="date" class="field mt-1"></label>
            <label class="font-semibold">Fin d’engagement<input v-model="edit.engagement_ends_on" type="date" class="field mt-1"></label>
            <p v-if="edit.errors.confirm_homonym" class="rounded-lg border-2 border-amber-600 bg-amber-50 p-3 sm:col-span-2" role="alert">{{ edit.errors.confirm_homonym }}<label class="mt-2 flex items-center gap-2 font-semibold"><input v-model="edit.confirm_homonym" type="checkbox" class="size-6"> C’est une autre personne</label></p>
            <p class="text-sm sm:col-span-2">L’identifiant <strong>{{ h.identifier }}</strong> ne change jamais.</p>
            <div class="flex gap-3 sm:col-span-2"><button type="submit" class="btn-soft" :disabled="edit.processing" data-testid="enregistrer-modification">Enregistrer</button><button type="button" class="btn-soft" @click="editing = null">Annuler</button></div>
          </form>

          <form v-if="closing === h.id" class="mt-4 space-y-3 border-t pt-4" @submit.prevent="submitClose(h)">
            <p class="font-semibold">La clôture est définitive : le titulaire ne se modifie plus et son identifiant n’est jamais réattribué.</p>
            <label class="block font-semibold">Motif de clôture<input v-model="closeForm.reason" class="field mt-1" required maxlength="200" data-testid="motif-cloture"><span v-if="closeForm.errors.reason" class="block text-sm text-red-800" role="alert">{{ closeForm.errors.reason }}</span></label>
            <label class="flex items-center gap-2 font-semibold"><input v-model="closeForm.confirmation" type="checkbox" class="size-6" data-testid="confirmer-cloture"> Je confirme la clôture</label>
            <div class="flex gap-3"><button type="submit" class="btn-soft" :disabled="closeForm.processing || !closeForm.confirmation" data-testid="valider-cloture">Clore ce titulaire</button><button type="button" class="btn-soft" @click="closing = null">Annuler</button></div>
          </form>
        </article>
        <nav v-if="holders.links.length > 3" class="flex flex-wrap gap-2" aria-label="Pagination">
          <template v-for="link in holders.links" :key="link.label"><Link v-if="link.url" :href="link.url" class="btn-soft" :class="link.active ? 'font-bold underline' : ''" preserve-scroll>{{ link.label.replace('&laquo; Previous', 'Précédent').replace('Next &raquo;', 'Suivant') }}</Link></template>
        </nav>
      </section>

      <section v-if="canConfigure" class="col-span-full space-y-3 rounded-xl border bg-white p-4">
        <h2 class="text-heading font-bold">Format de l’identifiant imprimable</h2>
        <p>Exemple du prochain format : <strong class="font-mono" data-testid="exemple-identifiant">{{ identifierExample }}</strong>. Le format n’est pas publié tant que l’institution ne l’a pas validé ; les identifiants déjà émis ne changent jamais.</p>
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
