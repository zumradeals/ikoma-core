<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BaseCard from '../../components/BaseCard.vue';
import FormButton from '../../components/FormButton.vue';

type Row = { id: string; full_name: string; organisation: string | null; status: string; participations: number | string; permanent: boolean };
const props = defineProps<{ query: string; persons: Row[] }>();
const search = ref(props.query);
const form = useForm({ first_name: '', last_name: '', full_name: '', organisation: '', homonym_confirmed: false });
const needsConfirmation = () => Object.values(form.errors).some(message => String(message).includes('de même nom'));
function find() { router.get('/administration/personnes', { q: search.value }, { preserveState: true }); }
function create() { form.post('/administration/personnes', { onSuccess: () => form.reset() }); }
</script>
<template>
<AdminLayout active="personnes">
<h1 class="mb-4 text-display font-bold">Administration · Personnes</h1>
<p class="mb-6">Une personne a une identité unique, réutilisée d’une activité à l’autre. Deux personnes ne sont jamais fusionnées automatiquement sur le seul nom.</p>
<p class="mb-6"><Link href="/administration/imports" class="font-semibold underline" data-testid="importer-liste">Importer une liste d’invités (fichier CSV ou Excel)</Link> · <Link href="/administration/invites" class="font-semibold underline" data-testid="fiches-invites">Fiches des invités d’une activité</Link></p>
<div class="space-y-6">
<BaseCard>
<h2 class="mb-4 text-heading font-bold">Rechercher une personne</h2>
<form class="flex flex-wrap items-end gap-3" role="search" aria-label="Rechercher une personne" @submit.prevent="find">
<label class="block grow">Nom, prénom ou organisation<input v-model="search" class="field" maxlength="160" data-testid="recherche-personne"></label>
<FormButton>Rechercher</FormButton>
</form>
<ul v-if="persons.length" class="mt-5 space-y-3" data-testid="liste-personnes">
<li v-for="person in persons" :key="person.id" class="rounded-xl border border-slate-200 p-4">
<Link :href="'/administration/personnes/' + person.id" class="font-bold" :data-testid="'ouvrir-personne-' + person.id">{{ person.full_name }}</Link>
<p class="text-sm text-slate-600"><template v-if="person.organisation">{{ person.organisation }} · </template>{{ Number(person.participations) }} participation{{ Number(person.participations) > 1 ? 's' : '' }}<template v-if="person.permanent"> · Personnel permanent</template><template v-if="person.status === 'archive'"> · Archivée</template></p>
</li>
</ul>
<p v-else class="mt-5" data-testid="aucune-personne">Aucune personne ne correspond.</p>
</BaseCard>
<BaseCard>
<h2 class="mb-4 text-heading font-bold">Enregistrer une nouvelle personne</h2>
<p class="mb-4 text-sm text-slate-600">Vérifiez d’abord la recherche ci-dessus : si la personne existe, ouvrez sa fiche et inscrivez-la à l’activité, sans créer de doublon.</p>
<form class="space-y-4" aria-label="Enregistrer une personne" data-testid="form-personne" @submit.prevent="create">
<label class="block">Prénom<input v-model="form.first_name" class="field" maxlength="100"></label>
<label class="block">Nom<input v-model="form.last_name" class="field" maxlength="100"></label>
<label class="block">Ou nom complet seul<input v-model="form.full_name" class="field" maxlength="160"></label>
<label class="block">Organisation (facultatif)<input v-model="form.organisation" class="field" maxlength="160"></label>
<label v-if="needsConfirmation()" class="flex min-h-14 items-center gap-3" data-testid="confirmer-homonyme"><input v-model="form.homonym_confirmed" type="checkbox" class="h-6 w-6">Je confirme qu’il s’agit d’une autre personne que celles de même nom</label>
<p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
<FormButton :busy="form.processing">Enregistrer la personne</FormButton>
</form>
</BaseCard>
</div>
</AdminLayout>
</template>
