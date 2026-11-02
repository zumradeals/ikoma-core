<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { useInstitution } from '../../composables/useInstitution';

type Fields = {
  nom: string; nom_complet: string; slogan: string; accueil_titre: string; accueil_description: string; connexion_accroche: string; pied: string;
  primary: string; secondary: string; accent: string;
};
const props = defineProps<{ form: Fields & { accueil_actif: boolean }; limits: Record<string, number>; defaults: Record<string, string>; custom: boolean; hasLogo: boolean }>();
const institution = useInstitution();
const homeActive = computed(() => props.form.accueil_actif);
const form = useForm<Fields>({ ...props.form });
const home = useForm({ actif: props.form.accueil_actif });
function saveHome() { home.put('/administration/identite/accueil', { preserveScroll: true }); }
const logo = useForm<{ logo: File | null }>({ logo: null });
const reset = useForm({ confirmation: '' });
const resetOpen = ref(false);

function save() { form.put('/administration/identite', { preserveScroll: true }); }
function pick(event: Event) { logo.logo = (event.target as HTMLInputElement).files?.[0] ?? null; }
function upload() {
  if (!logo.logo) return;
  logo.post('/administration/identite/logo', { preserveScroll: true, forceFormData: true, onSuccess: () => logo.reset() });
}
function removeLogo() { router.delete('/administration/identite/logo', { preserveScroll: true }); }
function restore() {
  reset.post('/administration/identite/reinitialiser', { preserveScroll: true, onSuccess: () => { resetOpen.value = false; reset.reset(); router.visit('/administration/identite'); } });
}
const textFields: { key: keyof Fields; label: string; help: string; long?: boolean }[] = [
  { key: 'nom', label: 'Nom affiché', help: 'Nom court de l’institution (ex. : AILCT). Tant qu’il est vide, l’identité IKOMA ACCESS standard reste affichée.' },
  { key: 'nom_complet', label: 'Dénomination complète', help: 'Affichée sur la page d’accueil et la page de connexion.' },
  { key: 'slogan', label: 'Slogan (facultatif)', help: 'Phrase courte sous le titre.' },
  { key: 'accueil_titre', label: 'Titre de la page d’accueil', help: 'À défaut, la dénomination complète est utilisée.' },
  { key: 'accueil_description', label: 'Description de la page d’accueil', help: 'Texte de présentation, également utilisé comme description du site.', long: true },
  { key: 'connexion_accroche', label: 'Accroche de la page de connexion', help: 'À défaut : « Bienvenue à » suivi du nom.' },
  { key: 'pied', label: 'Pied de page', help: 'Mention en bas des pages publiques.' },
];
const colorFields: { key: 'primary' | 'secondary' | 'accent'; label: string }[] = [
  { key: 'primary', label: 'Couleur principale (en-têtes, fonds)' }, { key: 'secondary', label: 'Couleur secondaire' }, { key: 'accent', label: 'Couleur d’accent (boutons)' },
];
</script>

<template>
  <AdminLayout active="identite">
    <div class="space-y-6" data-testid="page-identite">
      <header class="space-y-2">
        <h1 class="text-display font-bold">Identité de l’installation</h1>
        <p>Nom, logo, couleurs et libellés affichés aux utilisateurs. Cette personnalisation ne change ni les droits, ni les rôles, ni les badges, ni les modules : elle n’agit que sur l’affichage.</p>
        <p v-if="!custom" class="rounded-lg border bg-white p-3 font-semibold" data-testid="identite-standard">Identité actuelle : IKOMA ACCESS (standard).</p>
        <p v-else class="rounded-lg border bg-white p-3 font-semibold" data-testid="identite-personnalisee">Identité actuelle : {{ institution.name }}.</p>
      </header>

      <form class="space-y-5 rounded-xl border bg-white p-5" @submit.prevent="save">
        <h2 class="text-heading font-bold">Nom et libellés</h2>
        <label v-for="field in textFields" :key="field.key" class="block font-semibold">
          {{ field.label }}
          <textarea v-if="field.long" v-model="form[field.key]" :maxlength="limits[field.key]" rows="3" class="field mt-1" :data-testid="'champ-' + field.key"></textarea>
          <input v-else v-model="form[field.key]" :maxlength="limits[field.key]" class="field mt-1" :data-testid="'champ-' + field.key">
          <span class="mt-1 block text-sm font-normal">{{ field.help }}</span>
          <span v-if="form.errors[field.key]" class="block text-sm font-semibold text-red-800" role="alert">{{ form.errors[field.key] }}</span>
        </label>

        <h2 class="pt-2 text-heading font-bold">Couleurs</h2>
        <div class="grid gap-4 sm:grid-cols-3">
          <label v-for="color in colorFields" :key="color.key" class="block font-semibold">
            {{ color.label }}
            <span class="mt-1 flex items-center gap-2">
              <input v-model="form[color.key]" type="color" class="h-12 w-16 shrink-0 cursor-pointer rounded border" :aria-label="color.label" :data-testid="'couleur-' + color.key">
              <input v-model="form[color.key]" maxlength="7" class="field" :aria-label="color.label + ' (code hexadécimal)'" :data-testid="'hex-' + color.key">
            </span>
            <span v-if="form.errors[color.key]" class="block text-sm font-semibold text-red-800" role="alert">{{ form.errors[color.key] }}</span>
          </label>
        </div>
        <p class="text-sm">Choisissez des couleurs suffisamment contrastées avec le blanc : le texte des en-têtes et des boutons est blanc. Les couleurs n’ont aucun effet tant qu’aucun nom n’est saisi.</p>
        <button type="submit" :disabled="form.processing" data-testid="enregistrer-identite">Enregistrer l’identité</button>
      </form>

      <section class="space-y-3 rounded-xl border bg-white p-5" data-testid="bloc-accueil">
        <h2 class="text-heading font-bold">Page d’accueil publique</h2>
        <p>Quand elle est désactivée, l’adresse racine conduit directement à la connexion. Désactiver ne supprime ni les textes ni le logo : ils sont conservés et reprennent effet à la réactivation. La page n’affiche que les informations publiques de l’institution, jamais de donnée opérationnelle.</p>
        <p class="font-semibold" data-testid="etat-accueil">État actuel : {{ homeActive ? 'activée' : 'désactivée' }}. {{ custom ? 'Par défaut, une installation institutionnelle n’affiche pas d’accueil.' : 'Par défaut, l’installation standard affiche l’accueil IKOMA ACCESS.' }}</p>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="saveHome">
          <label class="flex items-center gap-2 font-semibold"><input v-model="home.actif" type="checkbox" class="size-6" data-testid="accueil-actif"> Afficher la page d’accueil publique</label>
          <button type="submit" class="btn-soft" :disabled="home.processing" data-testid="enregistrer-accueil">Enregistrer</button>
        </form>
      </section>

      <section class="space-y-3 rounded-xl border bg-white p-5">
        <h2 class="text-heading font-bold">Logo</h2>
        <p>Image PNG ou JPEG, 1 Mo au plus, d’au moins 32 pixels. Elle est recadrée à 512 pixels maximum et nettoyée de ses métadonnées. Sans logo, l’icône IKOMA ACCESS reste affichée.</p>
        <img v-if="institution.logoUrl" :src="institution.logoUrl" alt="Logo actuel" class="h-20 w-20 rounded border object-contain" data-testid="logo-actuel">
        <div class="flex flex-wrap items-end gap-3">
          <label class="font-semibold">Choisir un fichier<input type="file" accept="image/png,image/jpeg" class="block" data-testid="fichier-logo" @change="pick"></label>
          <button type="button" class="btn-soft" :disabled="!logo.logo || logo.processing" data-testid="envoyer-logo" @click="upload">Envoyer le logo</button>
          <button v-if="hasLogo" type="button" class="btn-soft" data-testid="retirer-logo" @click="removeLogo">Retirer le logo</button>
        </div>
        <p v-if="logo.errors.logo" class="font-semibold text-red-800" role="alert">{{ logo.errors.logo }}</p>
      </section>

      <section class="space-y-3 rounded-xl border bg-white p-5">
        <h2 class="text-heading font-bold">Revenir à IKOMA ACCESS</h2>
        <p>Efface le nom, les libellés, les couleurs et le logo enregistrés. Aucune autre donnée n’est touchée.</p>
        <button v-if="!resetOpen" type="button" class="btn-soft" data-testid="ouvrir-restauration" @click="resetOpen = true">Restaurer l’identité standard</button>
        <form v-else class="space-y-3" @submit.prevent="restore">
          <label class="block font-semibold">Recopiez exactement RESTAURER IKOMA<input v-model="reset.confirmation" class="field mt-1" autocomplete="off" data-testid="confirmation-restauration"></label>
          <p v-if="reset.errors.confirmation" class="font-semibold text-red-800" role="alert">{{ reset.errors.confirmation }}</p>
          <div class="flex gap-3"><button type="submit" data-testid="confirmer-restauration">Restaurer</button><button type="button" class="btn-soft" @click="resetOpen = false">Annuler</button></div>
        </form>
      </section>
    </div>
  </AdminLayout>
</template>
