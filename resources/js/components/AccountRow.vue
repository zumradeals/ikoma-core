<script setup lang="ts">
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { roleLabels } from '../labels';
import FormButton from './FormButton.vue';
import TwoFactorReset from './TwoFactorReset.vue';

type User = { id: string; name: string; email: string; active: boolean; role: string };
const props = defineProps<{ user: User; roles: string[]; canChangeRoles: boolean; canResetTwoFactor: boolean; challenge: { user_id: string; token: string } | null }>();
const panel = ref<'edit' | 'password' | '2fa' | null>(null);
const form = useForm({ active: props.user.active, role: props.user.role });
const password = useForm({ password: '' });
watch(() => [props.user.active, props.user.role] as const, ([active, role]) => { form.active = active; form.role = role; });
watch(() => props.challenge, challenge => { if (challenge?.user_id === props.user.id) panel.value = '2fa'; }, { immediate: true });
function toggle(next: typeof panel.value) { panel.value = panel.value === next ? null : next; password.reset(); }
function save() {
  if ((form.active !== props.user.active || form.role !== props.user.role) && !window.confirm(`Modifier les accès de « ${props.user.name} » ? Toutes ses sessions seront fermées.`)) return;
  form.put(`/administration/comptes/${props.user.id}`, { preserveScroll: true, onSuccess: () => { panel.value = null; } });
}
function resetPassword() {
  if (!window.confirm(`Réinitialiser le mot de passe de « ${props.user.name} » et fermer ses sessions ?`)) return;
  password.put(`/administration/comptes/${props.user.id}/mot-de-passe`, { preserveScroll: true, onSuccess: () => { password.reset(); panel.value = null; } });
}
</script>
<template>
  <tr :data-testid="'compte-' + user.id">
    <th scope="row"><span class="account-name"><span class="account-initials" aria-hidden="true">{{ user.name.split(' ').slice(0, 2).map(s => s.charAt(0)).join('').toUpperCase() }}</span>{{ user.name }}</span></th>
    <td class="account-email">{{ user.email }}</td>
    <td><span class="account-role">{{ roleLabels[user.role] ?? user.role }}</span></td>
    <td><span class="account-status" :class="user.active ? 'is-active' : 'is-disabled'">{{ user.active ? '● Actif' : '○ Désactivé' }}</span></td>
    <td>
<div class="account-actions">
      <button v-if="canChangeRoles" type="button" class="admin-outline" :aria-expanded="panel === 'edit'" :aria-controls="'actions-' + user.id" @click="toggle('edit')">Modifier<span class="sr-only"> {{ user.name }}</span></button>
      <button type="button" class="admin-outline" :aria-expanded="panel === 'password'" :aria-controls="'actions-' + user.id" @click="toggle('password')">Mot de passe<span class="sr-only"> de {{ user.name }}</span></button>
      <button v-if="canResetTwoFactor" type="button" class="admin-outline" :aria-expanded="panel === '2fa'" :aria-controls="'actions-' + user.id" @click="toggle('2fa')">Réinitialiser 2FA<span class="sr-only"> de {{ user.name }}</span></button>
    </div>
</td>
  </tr>
  <tr v-if="panel" class="account-expanded">
<td :id="'actions-' + user.id" colspan="5">
    <section :aria-label="'Actions pour ' + user.name" class="account-panel">
      <div class="admin-section-heading"><h3 class="font-bold">{{ panel === 'edit' ? 'Modifier les accès' : panel === 'password' ? 'Réinitialiser le mot de passe' : 'Double authentification' }} · {{ user.name }}</h3><button type="button" class="admin-outline" @click="toggle(null)">Fermer</button></div>
      <form v-if="panel === 'edit'" class="account-edit-form" @submit.prevent="save">
        <label>Rôle<select v-model="form.role" class="field"><option v-for="role in roles" :key="role" :value="role">{{ roleLabels[role] }}</option></select></label>
        <label class="flex min-h-14 items-center gap-3"><input v-model="form.active" type="checkbox" class="size-5">Compte actif</label>
        <p class="admin-muted">Décocher bloque l’accès. Enregistrer ferme les sessions du compte.</p>
        <p v-for="e in form.errors" :key="e" role="alert">{{ e }}</p>
        <FormButton :busy="form.processing">Enregistrer le compte</FormButton>
      </form>
      <form v-else-if="panel === 'password'" class="mt-4 max-w-lg space-y-3" @submit.prevent="resetPassword">
        <label>Nouveau mot de passe<input v-model="password.password" type="password" class="field" autocomplete="new-password" minlength="12" required></label>
        <p class="admin-muted">Au moins 12 caractères, majuscule, minuscule, chiffre et symbole.</p>
        <p v-for="e in password.errors" :key="e" role="alert">{{ e }}</p><FormButton :busy="password.processing">Réinitialiser</FormButton>
      </form>
      <TwoFactorReset v-else :user="user" :challenge="challenge" />
    </section>
  </td>
</tr>
</template>
