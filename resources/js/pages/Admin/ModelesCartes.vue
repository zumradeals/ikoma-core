<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import '../../../../public/badges/holder-cards.css';

type Specimen = { code: string; label: string; recto: string; verso: string };
const props = defineProps<{
  specimens: Specimen[]; settings: { ligne1: string; ligne2: string; contact: string; avis: string; valide: boolean };
  limits: Record<string, number>; initialized: boolean; canConfigure: boolean;
}>();
const form = useForm({ ...props.settings });
function save() { form.put('/administration/titulaires/modeles', { preserveScroll: true }); }
</script>

<template>
  <AdminLayout active="titulaires">
    <div class="space-y-6" data-testid="page-modeles-cartes">
      <header class="space-y-2">
        <Link href="/administration/titulaires" class="font-semibold underline">← Titulaires permanents</Link>
        <h1 class="text-display font-bold">Modèles de cartes institutionnelles</h1>
        <p>Un modèle par catégorie institutionnelle (AILCT-01 à AILCT-08). Les libellés, couleurs et repères viennent de l’écran « Catégories institutionnelles » ; le logo et le nom viennent de l’identité de l’installation. Les exemples ci-dessous sont fictifs et leur QR ne correspond à aucun badge.</p>
        <p v-if="!settings.valide" class="rounded-lg border-2 border-amber-600 bg-amber-50 p-3 font-semibold" role="status" data-testid="modele-provisoire">Modèles provisoires : aucun élément graphique officiel n’a été fourni. Couleurs et repères neutres jusqu’à validation par l’institution.</p>
        <p v-if="!initialized" class="rounded-lg border bg-white p-3" data-testid="categories-absentes">Les catégories institutionnelles ne sont pas encore initialisées (écran « Catégories institutionnelles »).</p>
        <p><a href="/administration/titulaires/alignement" target="_blank" rel="noopener" class="font-semibold underline" data-testid="lien-alignement">Page d’essai d’alignement (sans QR ni donnée personnelle)</a></p>
      </header>

      <section class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4" aria-label="Huit modèles">
        <!-- eslint-disable vue/no-v-html -->
        <figure v-for="s in specimens" :key="s.code" class="space-y-2 rounded-xl border bg-white p-3" :data-testid="'modele-' + s.code">
          <figcaption class="font-bold">{{ s.code }} · {{ s.label }}</figcaption>
          <div class="flex flex-wrap gap-3"><div class="hc-preview" v-html="s.recto"></div><div class="hc-preview" v-html="s.verso"></div></div>
        </figure>
        <!-- eslint-enable vue/no-v-html -->
      </section>

      <form v-if="canConfigure" class="space-y-4 rounded-xl border bg-white p-5" @submit.prevent="save">
        <h2 class="text-heading font-bold">Mentions du verso</h2>
        <p class="text-sm">Ces textes sont imprimés sur le verso de toutes les cartes. Laissez vide pour utiliser les valeurs par défaut. Aucune donnée personnelle ni secret ne doit y figurer.</p>
        <label class="block font-semibold">Ligne principale<input v-model="form.ligne1" class="field mt-1" :maxlength="limits.ligne1" data-testid="verso-ligne1"><span v-if="form.errors.ligne1" class="block text-sm text-red-800" role="alert">{{ form.errors.ligne1 }}</span></label>
        <label class="block font-semibold">Seconde ligne (facultatif)<input v-model="form.ligne2" class="field mt-1" :maxlength="limits.ligne2" data-testid="verso-ligne2"></label>
        <label class="block font-semibold">Contact en cas de perte (facultatif)<input v-model="form.contact" class="field mt-1" :maxlength="limits.contact" data-testid="verso-contact"></label>
        <label class="block font-semibold">Avis<input v-model="form.avis" class="field mt-1" :maxlength="limits.avis" data-testid="verso-avis"></label>
        <label class="flex items-center gap-2 font-semibold"><input v-model="form.valide" type="checkbox" class="size-6" data-testid="modele-valide"> L’institution a validé ces modèles (retire la mention « provisoire »)</label>
        <button type="submit" class="admin-primary" :disabled="form.processing" data-testid="enregistrer-modeles">Enregistrer</button>
      </form>
    </div>
  </AdminLayout>
</template>
