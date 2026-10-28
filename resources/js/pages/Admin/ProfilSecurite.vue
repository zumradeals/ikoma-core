<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue';
const props = defineProps<{ profile: 'standard' | 'renforce'; production: boolean }>();
const form = useForm({ profile: props.profile });
const enforced = [
  'Authentification à deux facteurs (code à six chiffres) pour tous les rôles : agents, hôtes et encadreurs comme administrateur et superviseur.',
  'Double validation : la création d’un compte administrateur ou superviseur, un changement de rôle privilégié, la réactivation d’un compte privilégié et le retour au profil standard exigent la validation d’un second administrateur (menu « Validations »).',
];
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link>
<h1 class="mb-4 text-display font-bold">Administration · Profil de sécurité</h1>
<div class="space-y-6">
<BaseCard>
<p v-if="profile === 'standard'" role="status" class="mb-4 rounded-xl border-2 border-orange-700 bg-orange-50 p-4 font-semibold text-orange-950" data-testid="profil-standard"><span aria-hidden="true">⚠</span> Profil « standard » : convient à une démonstration avec des données fictives. Ne l’utilisez jamais avec des données réelles.</p>
<p v-else role="status" class="mb-4 rounded-xl border-2 border-emerald-700 bg-emerald-50 p-4 font-semibold text-emerald-950" data-testid="profil-renforce"><span aria-hidden="true">✓</span> Profil « renforcé » : adapté aux données réelles.</p>
<form class="space-y-4" aria-label="Profil de sécurité" @submit.prevent="form.put('/administration/profil-securite')">
<fieldset class="space-y-3">
<legend class="sr-only">Profil de sécurité</legend>
<label class="flex min-h-14 items-center gap-3"><input v-model="form.profile" type="radio" value="renforce" class="size-6" data-testid="profil-choix-renforce"> Renforcé (recommandé, obligatoire avec des données réelles)</label>
<label class="flex min-h-14 items-center gap-3"><input v-model="form.profile" type="radio" value="standard" class="size-6" data-testid="profil-choix-standard"> Standard (démonstration, données fictives)</label>
</fieldset>
<p v-for="error in form.errors" :key="error" role="alert">{{ error }}</p>
<FormButton :busy="form.processing">Enregistrer le profil</FormButton>
</form>
</BaseCard>
<BaseCard>
<h2 class="mb-3 text-heading font-bold">Ce que le profil renforcé impose</h2>
<ul class="list-disc space-y-2 pl-6"><li v-for="item in enforced" :key="item">{{ item }}</li></ul>
<p class="mt-4 text-base">Un compte sans second facteur est dirigé vers la page « Sécurité » à sa prochaine connexion. {{ production ? 'Cette installation est en production.' : '' }}</p>
</BaseCard>
</div>
</BaseLayout>
</template>
