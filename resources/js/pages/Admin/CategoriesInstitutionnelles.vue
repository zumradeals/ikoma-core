<script setup lang="ts">
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';

type Category = { code: string; label: string; color: string; symbol: string };
type EventCategory = { id: string; name: string; color: string; institutional_code: string | null };
const props = defineProps<{
  categories: Category[]; initialized: boolean; events: { id: string; name: string }[]; eventId: string | null; eventCategories: EventCategory[]; neutralColor: string;
}>();
const drafts = reactive<Record<string, Category>>({});
// Les brouillons suivent les données du serveur (initialisation, enregistrement) : jamais de ligne sans brouillon.
watch(() => props.categories, list => { for (const c of list) drafts[c.code] = { ...c }; }, { immediate: true });
function initialize() { router.post('/administration/categories-institutionnelles/initialiser', {}, { preserveScroll: true }); }
function save(code: string) { router.put('/administration/categories-institutionnelles/' + code, { label: drafts[code].label, color: drafts[code].color, symbol: drafts[code].symbol }, { preserveScroll: true }); }
function pickEvent(event: Event) { router.get('/administration/categories-institutionnelles', { event: (event.target as HTMLSelectElement).value }, { preserveScroll: true }); }
function link(categoryId: string, event: Event) {
  router.put('/administration/categories-institutionnelles/liaison', { event_id: props.eventId, category_id: categoryId, code: (event.target as HTMLSelectElement).value }, { preserveScroll: true });
}
</script>

<template>
  <AdminLayout active="categories-institutionnelles">
    <div class="space-y-6" data-testid="page-categories-institutionnelles">
      <header class="col-span-full space-y-2">
        <h1 class="text-display font-bold">Catégories institutionnelles</h1>
        <p>Étiquettes d’identité (code, libellé, couleur, repère) des titulaires. <strong>Elles n’accordent jamais d’accès</strong> : les zones accessibles restent celles de la catégorie d’événement.</p>
        <p class="text-sm">Les couleurs et repères sont neutres tant que l’institution n’a pas fourni les siens. Le repère doit rester lisible sans la couleur.</p>
      </header>

      <section v-if="!initialized" class="col-span-full rounded-xl border bg-white p-5">
        <p>Le référentiel n’est pas encore créé. Il contient huit catégories (AILCT-01 à AILCT-08) avec des valeurs neutres, modifiables.</p>
        <button type="button" class="btn-soft mt-3" data-testid="initialiser-categories" @click="initialize">Créer les huit catégories</button>
      </section>

      <section v-else class="col-span-full space-y-3" aria-label="Référentiel">
        <article v-for="c in categories" :key="c.code" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-2 lg:grid-cols-[8rem_1fr_13rem_6rem_auto] lg:items-end" :data-testid="'categorie-' + c.code">
          <p class="flex items-center gap-2 font-mono font-bold"><span class="inline-grid size-8 place-items-center rounded text-xs text-white" :style="{ backgroundColor: drafts[c.code].color }" aria-hidden="true">{{ drafts[c.code].symbol }}</span>{{ c.code }}</p>
          <label class="font-semibold">Libellé<input v-model="drafts[c.code].label" class="field mt-1" maxlength="80" :data-testid="'libelle-' + c.code"></label>
          <label class="font-semibold">Couleur<span class="mt-1 flex items-center gap-2"><input v-model="drafts[c.code].color" type="color" class="h-12 w-14 shrink-0 rounded border" :aria-label="'Couleur ' + c.code"><input v-model="drafts[c.code].color" class="field" maxlength="7" :data-testid="'couleur-' + c.code"></span></label>
          <label class="font-semibold">Repère<input v-model="drafts[c.code].symbol" class="field mt-1" maxlength="8" :data-testid="'repere-' + c.code"></label>
          <button type="button" class="btn-soft" :data-testid="'enregistrer-' + c.code" @click="save(c.code)">Enregistrer</button>
        </article>
      </section>

      <section v-if="initialized" class="col-span-full space-y-3 rounded-xl border bg-white p-4">
        <h2 class="text-heading font-bold">Association facultative aux catégories d’événement</h2>
        <label class="block font-semibold">Événement
          <select class="field mt-1" :value="eventId ?? ''" data-testid="choix-evenement" @change="pickEvent"><option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </label>
        <p v-if="eventCategories.length === 0" class="text-sm">Aucune catégorie d’événement pour cet événement.</p>
        <div v-for="ec in eventCategories" :key="ec.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3" :data-testid="'liaison-' + ec.id">
          <p class="font-semibold">{{ ec.name }}</p>
          <select class="field max-w-xs" :value="ec.institutional_code ?? ''" :aria-label="'Catégorie institutionnelle de ' + ec.name" :data-testid="'select-liaison-' + ec.id" @change="link(ec.id, $event)">
            <option value="">Aucune</option><option v-for="c in categories" :key="c.code" :value="c.code">{{ c.code }} · {{ c.label }}</option>
          </select>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
