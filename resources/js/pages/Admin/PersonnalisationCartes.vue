<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import '../../../../public/badges/holder-cards.css';

type Model = {
  category_code: string; label: string; color: string; mention: string; show_validity: boolean; qr_side: 'recto' | 'verso';
  version: number; status: string; validated: boolean; validated_at: string | null; validated_by: string | null; saved: boolean;
};
const props = defineProps<{
  categories: { code: string; label: string }[]; selected: string | null; model: Model | null; preview: { recto: string; verso: string } | null;
  settings: { ligne1: string; ligne2: string; contact: string; avis: string }; limits: Record<string, number>; initialized: boolean; canConfigure: boolean;
}>();

const choice = ref(props.selected ?? '');
function pick() { router.get('/administration/titulaires/modeles', { categorie: choice.value }, { preserveScroll: true }); }

const form = useForm({ color: props.model?.color ?? '#2E7D32', mention: props.model?.mention ?? '', show_validity: props.model?.show_validity ?? true, qr_side: props.model?.qr_side ?? 'verso' });
const live = ref(props.preview);
let timer: ReturnType<typeof setTimeout> | undefined;
async function refresh() {
  if (!props.selected) return;
  const token = decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
  const res = await fetch(`/administration/titulaires/modeles/${props.selected}/apercu`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token }, credentials: 'same-origin',
    body: JSON.stringify({ color: form.color, mention: form.mention, show_validity: form.show_validity, qr_side: form.qr_side }),
  });
  if (res.ok) live.value = await res.json();
}
watch(() => props.model, m => { if (m) { form.defaults({ color: m.color, mention: m.mention, show_validity: m.show_validity, qr_side: m.qr_side }).reset(); form.clearErrors(); } live.value = props.preview; choice.value = props.selected ?? ''; });
watch(() => [form.color, form.mention, form.show_validity, form.qr_side], () => { clearTimeout(timer); timer = setTimeout(refresh, 250); });

function save() { if (props.selected) form.put(`/administration/titulaires/modeles/${props.selected}`, { preserveScroll: true }); }
function validate() { if (props.selected) router.post(`/administration/titulaires/modeles/${props.selected}/valider`, {}, { preserveScroll: true }); }

const verso = useForm({ ...props.settings });
function saveVerso() { verso.put('/administration/titulaires/modeles', { preserveScroll: true }); }
</script>

<template>
  <AdminLayout active="modeles-cartes">
    <div class="space-y-6" data-testid="page-modeles-cartes">
      <header class="space-y-2">
        <Link href="/administration/titulaires" class="font-semibold underline">← Registre des personnes</Link>
        <h1 class="text-display font-bold">Personnalisation des cartes</h1>
        <p>Choisissez une catégorie, réglez sa couleur et sa mention, et voyez la carte se modifier. L’exemple est fictif : son QR ne correspond à aucune vraie carte.</p>
        <p v-if="!initialized" class="rounded-lg border bg-white p-3" data-testid="categories-absentes">Les catégories institutionnelles ne sont pas encore initialisées (écran « Catégories institutionnelles »).</p>
      </header>

      <section v-if="model" class="space-y-4" aria-label="Modèle de carte">
        <label class="block max-w-md font-semibold">Catégorie
          <select v-model="choice" class="field mt-1" data-testid="choix-categorie" @change="pick"><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.label }}</option></select>
        </label>

        <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
          <form class="space-y-4 rounded-xl border bg-white p-5" @submit.prevent="save">
            <label class="block font-semibold">Couleur<input v-model="form.color" type="color" class="mt-1 block h-12 w-24 rounded border" :disabled="!canConfigure" data-testid="modele-couleur"><span v-if="form.errors.color" class="block text-sm text-red-800" role="alert">{{ form.errors.color }}</span></label>
            <label class="block font-semibold">Mention sur la carte<input v-model="form.mention" class="field mt-1" maxlength="60" :disabled="!canConfigure" data-testid="modele-mention"><span v-if="form.errors.mention" class="block text-sm text-red-800" role="alert">{{ form.errors.mention }}</span></label>
            <label class="flex items-center gap-2 font-semibold"><input v-model="form.show_validity" type="checkbox" class="size-6" :disabled="!canConfigure" data-testid="modele-validite"> Afficher la validité</label>
            <fieldset class="space-y-1">
<legend class="font-semibold">Place du QR</legend>
              <label class="flex items-center gap-2"><input v-model="form.qr_side" type="radio" value="verso" class="size-5" :disabled="!canConfigure" data-testid="qr-verso"> Au verso</label>
              <label class="flex items-center gap-2"><input v-model="form.qr_side" type="radio" value="recto" class="size-5" :disabled="!canConfigure" data-testid="qr-recto"> Au recto, près de la photo</label>
            </fieldset>
            <p class="text-sm" data-testid="modele-etat">Version {{ model.version }} · <strong>{{ model.validated ? 'Validé' : 'À valider' }}</strong><span v-if="model.validated_at"> le {{ model.validated_at }}<span v-if="model.validated_by"> par {{ model.validated_by }}</span></span></p>
            <div v-if="canConfigure" class="flex flex-wrap gap-3">
              <button type="submit" class="admin-primary" :disabled="form.processing" data-testid="enregistrer-modele">Enregistrer</button>
              <button v-if="model.saved && !model.validated" type="button" class="admin-secondary" data-testid="valider-modele" @click="validate">Valider ce modèle</button>
            </div>
          </form>

          <!-- eslint-disable vue/no-v-html -->
          <div v-if="live" class="flex flex-wrap items-start gap-6" data-testid="apercu-modele">
            <div class="hc-preview hc-preview--large" data-testid="apercu-recto" v-html="live.recto"></div>
            <div class="hc-preview hc-preview--large" data-testid="apercu-verso" v-html="live.verso"></div>
          </div>
          <!-- eslint-enable vue/no-v-html -->
        </div>
      </section>

      <form v-if="canConfigure" class="space-y-4 rounded-xl border bg-white p-5" @submit.prevent="saveVerso">
        <h2 class="text-heading font-bold">Texte du verso (commun à toutes les cartes)</h2>
        <label class="block font-semibold">Ligne principale<input v-model="verso.ligne1" class="field mt-1" :maxlength="limits.ligne1" data-testid="verso-ligne1"><span v-if="verso.errors.ligne1" class="block text-sm text-red-800" role="alert">{{ verso.errors.ligne1 }}</span></label>
        <label class="block font-semibold">Seconde ligne (facultatif)<input v-model="verso.ligne2" class="field mt-1" :maxlength="limits.ligne2" data-testid="verso-ligne2"></label>
        <label class="block font-semibold">Contact en cas de perte (facultatif)<input v-model="verso.contact" class="field mt-1" :maxlength="limits.contact" data-testid="verso-contact"></label>
        <label class="block font-semibold">Avis<input v-model="verso.avis" class="field mt-1" :maxlength="limits.avis" data-testid="verso-avis"></label>
        <button type="submit" class="admin-primary" :disabled="verso.processing" data-testid="enregistrer-modeles">Enregistrer</button>
      </form>
    </div>
  </AdminLayout>
</template>
