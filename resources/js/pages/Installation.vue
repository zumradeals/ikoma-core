<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BaseLayout from '../layouts/BaseLayout.vue'; import BaseCard from '../components/BaseCard.vue'; import FormButton from '../components/FormButton.vue';
type Check = { label: string; ok: boolean; critical: boolean; help: string };
const props = defineProps<{ checks: Check[]; ready: boolean }>();
const form = useForm({ token: '', name: '', email: '', password: '', password_confirmation: '', event_name: '', site: '', starts_at: '', ends_at: '' });
const problems = computed(() => props.checks.filter(c => !c.ok));
</script>
<template>
<BaseLayout>
<BaseCard>
<h1 class="mb-2 text-display font-bold">Installation d’IKOMA Accueil</h1>
<p class="mb-6">Cet assistant ne sert qu’une fois. Il crée le premier administrateur et le premier événement, puis il se ferme pour toujours.</p>
<section aria-labelledby="verifs" class="mb-8">
<h2 id="verifs" class="mb-3 text-xl font-bold">1. Vérifications</h2>
<ul class="space-y-2" data-testid="verifications">
<li v-for="c in checks" :key="c.label" class="rounded-xl border-2 p-3" :class="c.ok ? 'border-green-700' : c.critical ? 'border-red-700' : 'border-amber-600'">
<strong><span aria-hidden="true">{{ c.ok ? '✓' : c.critical ? '✕' : '⚠' }}</span> {{ c.label }}</strong>
<span class="ml-2">{{ c.ok ? 'Réussi' : c.critical ? 'Obligatoire : à corriger' : 'À corriger avant la mise en service' }}</span>
<p v-if="!c.ok" class="mt-1 text-body">{{ c.help }}</p>
</li>
</ul>
<p v-if="!ready" role="alert" class="mt-3 font-semibold text-red-800">Corrigez les points obligatoires, puis rechargez cette page.</p>
<p v-else-if="problems.length" class="mt-3 font-semibold text-amber-900">Vous pouvez continuer, mais traitez les avertissements avant d’accueillir de vrais visiteurs.</p>
</section>
<form v-if="ready" class="space-y-5" @submit.prevent="form.post('/installation', { onFinish: () => form.reset('password', 'password_confirmation') })">
<h2 class="text-xl font-bold">2. Jeton d’installation</h2>
<label class="block">Jeton affiché à la fin de install.sh<input v-model="form.token" required autocomplete="off" class="field" data-testid="jeton"></label>
<h2 class="text-xl font-bold">3. Premier administrateur</h2>
<label class="block">Nom complet<input v-model="form.name" required maxlength="120" class="field" data-testid="nom"></label>
<label class="block">Adresse électronique<input v-model="form.email" type="email" required autocomplete="username" class="field" data-testid="courriel"></label>
<label class="block">Mot de passe (14 caractères au moins, avec majuscules, minuscules, chiffres et symboles)<input v-model="form.password" type="password" required autocomplete="new-password" class="field" data-testid="mot-de-passe"></label>
<label class="block">Répétez le mot de passe<input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="field"></label>
<h2 class="text-xl font-bold">4. Premier événement</h2>
<label class="block">Nom de l’événement<input v-model="form.event_name" required maxlength="160" class="field" data-testid="evenement"></label>
<label class="block">Lieu (facultatif)<input v-model="form.site" maxlength="255" class="field"></label>
<label class="block">Début<input v-model="form.starts_at" type="datetime-local" required class="field"></label>
<label class="block">Fin<input v-model="form.ends_at" type="datetime-local" required class="field"></label>
<p v-for="error in form.errors" :key="error" role="alert" class="font-semibold text-red-800">{{ error }}</p>
<FormButton :busy="form.processing">Terminer l’installation</FormButton>
</form>
</BaseCard>
</BaseLayout>
</template>
