<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';

type Module = { id: string; name: string; included: boolean; active: boolean; toggleable: boolean };
const props = defineProps<{ modules: Module[] }>();
const f06 = computed(() => props.modules.find(m => m.id === 'F06'));
const target = ref(false);
const confirmOpen = ref(false);
const form = useForm({ module: 'F06', active: false, confirmation: '' });
const requiredPhrase = computed(() => target.value ? 'ACTIVER F06' : 'DESACTIVER F06');
function propose(enabled: boolean) { target.value = enabled; form.active = enabled; form.confirmation = ''; form.clearErrors(); confirmOpen.value = true; }
function cancel() { confirmOpen.value = false; form.reset('confirmation'); }
function submit() {
  form.put('/administration/modules/F06', {
    preserveScroll: true,
    onSuccess: () => { confirmOpen.value = false; form.reset('confirmation'); router.reload({ only: ['modules'] }); },
  });
}
</script>

<template>
  <AdminLayout active="modules">
    <div class="space-y-6" data-testid="catalogue-modules">
      <header class="space-y-2">
        <h1 class="text-display font-bold">Modules fondateurs</h1>
        <p>Les neuf modules F01 à F09 sont inclus dans chaque licence IKOMA ACCESS standard de 12 mois. Aucun module fondateur ne peut être retiré de cette licence ou facturé séparément.</p>
      </header>
      <section class="grid gap-3 lg:grid-cols-2">
        <article v-for="module in modules" :key="module.id" class="rounded-xl border bg-white p-5" :data-testid="'module-' + module.id">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-bold">{{ module.id }} — {{ module.name }}</h2>
            <span class="rounded-lg bg-slate-100 px-3 py-1 text-sm font-semibold">Inclus dans la licence</span>
          </div>
          <p v-if="!module.toggleable" class="mt-3 text-sm">Permanent — aucune désactivation disponible.</p>
          <div v-else class="mt-3 space-y-3">
            <p class="font-semibold" :data-testid="'etat-' + module.id">Exploitation {{ module.active ? 'active' : 'inactive' }}</p>
            <p class="text-sm">Cette option n'a aucun effet sur le droit contractuel d'utiliser F06. Les données, badges et historiques ne sont pas supprimés.</p>
            <button type="button" class="btn-soft" data-testid="basculer-F06" @click="propose(!module.active)">
              {{ module.active ? 'Désactiver Groupes et cars' : 'Activer Groupes et cars' }}
            </button>
          </div>
        </article>
      </section>
      <p v-if="!f06?.active" class="rounded-lg border border-amber-300 bg-amber-50 p-4">F06 est désactivé. Les autres modules, y compris l'accueil individuel et les contrôles d'accès, restent fonctionnels.</p>
      <section v-if="confirmOpen" role="dialog" aria-modal="true" aria-label="Confirmation du changement F06" class="rounded-xl border-2 border-slate-400 bg-white p-5">
        <h2 class="text-xl font-bold">{{ target ? 'Activer' : 'Désactiver' }} F06 — Groupes et cars</h2>
        <p v-if="target">Après activation, les utilisateurs autorisés retrouveront les parcours de groupes. Les tablettes devront synchroniser leur liste.</p>
        <p v-else class="font-semibold text-amber-900">Terminez les groupes déjà engagés et synchronisez les tablettes avant de désactiver. Les opérations collectives hors ligne réalisées depuis une copie ancienne pourront être rejetées par le serveur et devront être traitées en conflit. Aucune donnée ne sera effacée.</p>
        <form class="mt-3 space-y-3" @submit.prevent="submit">
          <label class="block font-semibold">Saisissez {{ requiredPhrase }} pour confirmer :
            <input v-model="form.confirmation" data-testid="confirmation-F06" class="field mt-2" autocomplete="off">
          </label>
          <p v-if="form.errors.confirmation" role="alert" class="text-red-800">{{ form.errors.confirmation }}</p>
          <p v-if="form.errors.module" role="alert" class="text-red-800">{{ form.errors.module }}</p>
          <div class="flex gap-3">
            <button type="button" class="btn-soft" @click="cancel">Annuler</button>
            <button type="submit" class="btn-main" data-testid="confirmer-F06" :disabled="form.processing || form.confirmation !== requiredPhrase">Confirmer le changement</button>
          </div>
        </form>
      </section>
    </div>
  </AdminLayout>
</template>
