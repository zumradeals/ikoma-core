<script setup lang="ts">
import { ref } from 'vue'; import { useForm } from '@inertiajs/vue3'; import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue';
const recovery = ref(false); const form = useForm({ code: '', recovery_code: '' });
</script>
<template>
<BaseLayout>
<BaseCard>
<h1 class="mb-4 text-display font-bold">Vérifier votre connexion</h1><p class="mb-6">Saisissez le code de votre application d'authentification.</p>
<form class="space-y-5" @submit.prevent="form.post('/two-factor-challenge', { onFinish: () => form.reset() })"><label v-if="!recovery">Code à six chiffres<input v-model="form.code" inputmode="numeric" autocomplete="one-time-code" class="field" required pattern="[0-9]{6}"></label><label v-else>Code de secours<input v-model="form.recovery_code" class="field" required autocomplete="off"></label><p v-for="e in form.errors" :key="e" role="alert">{{ e }}</p><FormButton :busy="form.processing">Vérifier</FormButton></form>
<button class="mt-4 min-h-14 underline" @click="recovery = !recovery; form.reset()">{{ recovery ? 'Utiliser un code à six chiffres' : 'Utiliser un code de secours' }}</button>
</BaseCard>
</BaseLayout>
</template>
