<script setup lang="ts">
import AgentLayout from '../../layouts/AgentLayout.vue';
import AdminLayout from '../../layouts/AdminLayout.vue';
import { usePage } from '@inertiajs/vue3';
const page = usePage<{ auth: { isAdmin?: boolean; name: string; permissions: string[] } | null }>();
import { ref } from 'vue'; import { router, useForm } from '@inertiajs/vue3'; import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue';
defineProps<{ confirmed: boolean }>(); const qr = ref(''); const codes = ref<string[]>([]); const message = ref(''); const form = useForm({ code: '' });
async function load() {
 const response = await fetch('/user/two-factor-qr-code', { headers: { Accept: 'application/json' } });
 if (!response.ok) { message.value = 'Confirmez votre mot de passe avant de poursuivre.'; return; }
 const data = await response.json(); qr.value = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(data.svg)}`;
 const recovery = await fetch('/user/two-factor-recovery-codes', { headers: { Accept: 'application/json' } }); codes.value = await recovery.json();
}
function enable() { router.post('/user/two-factor-authentication', {}, { onSuccess: load }); }
</script>
<template>
<component :is="page.props.auth?.isAdmin ? AdminLayout : page.props.auth?.permissions.includes('visiteurs.rechercher') ? AgentLayout : BaseLayout" active="securite" :agent="page.props.auth?.name">
<BaseCard>
<h1 class="mb-4 text-display font-bold">Sécuriser mon compte</h1><p class="mb-6">La double authentification est obligatoire pour les administrateurs et superviseurs. Conservez vos codes de secours dans un endroit sûr.</p>
<p v-if="confirmed" role="status">✓ Double authentification activée.</p><template v-else>
<button class="action" @click="enable">Configurer la double authentification</button><p role="status">{{ message }}</p>
<!-- SVG produit uniquement par Fortify côté serveur, jamais par une saisie utilisateur. -->
<img v-if="qr" :src="qr" alt="Code QR pour votre application d’authentification" class="my-6 max-w-full bg-white p-4">
<form v-if="qr" class="space-y-5" @submit.prevent="form.post('/user/confirmed-two-factor-authentication')"><label>Code de confirmation<input v-model="form.code" class="field" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" required></label><p v-for="e in form.errors" :key="e" role="alert">{{ e }}</p><FormButton :busy="form.processing">Activer et confirmer</FormButton></form>
</template>
<div v-if="codes.length" class="my-6"><h2 class="text-heading font-bold">Codes de secours — à conserver</h2><ul><li v-for="code in codes" :key="code" class="font-mono">{{ code }}</li></ul></div><a v-if="!page.props.auth?.isAdmin" class="mt-6 block min-h-14 underline" href="/espace">Accéder à mon espace</a>
</BaseCard>
</component>
</template>
