<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import AdminIcon from '../../components/admin/AdminIcon.vue';
import FormButton from '../../components/FormButton.vue';
import AccountRow from '../../components/AccountRow.vue';
import HostAccountLink from '../../components/hote/HostAccountLink.vue';
import { roleLabels } from '../../labels';

const page = usePage<{ auth: { id: string; permissions: string[] } }>();
const props = defineProps<{
  twoFactorReset: { user_id: string; token: string } | null;
  users: { id: string; name: string; email: string; active: boolean; role: string; two_factor_confirmed: boolean }[];
  roles: string[]; idle: number; hostTimeout: number;
  hosts: { id: string; name: string; event_name: string; user_id: string | null }[];
}>();
const canChangeRoles = computed(() => page.props.auth.permissions.includes('roles.attribuer'));
const query = ref('');
const normalize = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');
const filtered = computed(() => props.users.filter(u => normalize([u.name, u.email, roleLabels[u.role] ?? u.role, u.active ? 'actif' : 'désactivé'].join(' ')).includes(normalize(query.value.trim()))));
const activeCount = computed(() => props.users.filter(u => u.active).length);
const supervisors = computed(() => props.users.filter(u => u.role === 'superviseur' && u.active));
const hostUsers = computed(() => props.users.filter(u => u.role === 'hote'));
const selectedHost = ref(hostUsers.value[0]?.id ?? '');
const hostUser = computed(() => hostUsers.value.find(u => u.id === selectedHost.value) ?? hostUsers.value[0]);
const linkedHosts = computed(() => props.hosts.filter(h => h.user_id !== null).length);
const showPassword = ref(false);
const form = useForm({ name: '', email: '', password: '', role: 'agent_accueil' });
const settings = useForm({ minutes: props.idle });
const hostSettings = useForm({ minutes: props.hostTimeout });
</script>
<template>
  <AdminLayout active="comptes">
    <header class="mb-5"><h1>Administration · Comptes</h1><p class="admin-muted mt-1">Gérez les utilisateurs, les rattachements et les réglages communs sans quitter l’espace administrateur.</p></header>
    <section aria-label="Synthèse des comptes" class="account-summary">
      <div class="admin-card account-summary-tile"><span class="account-metric-icon"><AdminIcon name="users" :size="30" /></span><strong>{{ activeCount }}</strong><div><b>comptes actifs</b><p class="admin-muted">sur {{ users.length }} au total</p></div></div>
      <div class="admin-card account-summary-tile"><span class="account-metric-icon green"><AdminIcon name="shield" :size="30" /></span><strong>{{ supervisors.length }}</strong><div><b>superviseurs actifs</b><p class="admin-muted">Double authentification désactivée</p></div></div>
      <div class="admin-card account-summary-tile"><span class="account-metric-icon orange"><AdminIcon name="users" :size="30" /></span><strong>{{ linkedHosts }}</strong><div><b>fiches hôte rattachées</b><p class="admin-muted">sur {{ hosts.length }} fiches</p></div></div>
    </section>
    <section v-if="canChangeRoles" class="admin-card mt-4" aria-labelledby="creer-compte">
      <h2 id="creer-compte">Créer un compte</h2><p class="admin-muted">Un rôle par personne. Transmettez le mot de passe par un canal sûr.</p>
      <form class="account-create-form" @submit.prevent="form.post('/administration/comptes', { onSuccess: () => form.reset() })">
        <label>Nom<input v-model="form.name" class="field" placeholder="Nom complet" maxlength="120" required></label>
        <label>Adresse électronique<input v-model="form.email" class="field" type="email" placeholder="utilisateur@exemple.fr" maxlength="180" required></label>
        <div><label for="mot-de-passe-initial">Mot de passe initial</label><span class="account-password"><input id="mot-de-passe-initial" v-model="form.password" class="field" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" placeholder="Au moins 12 caractères" aria-describedby="password-help" required minlength="12"><button type="button" :aria-pressed="showPassword" :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" @click="showPassword = !showPassword">{{ showPassword ? 'Masquer' : 'Voir' }}</button></span></div>
        <label>Rôle<select v-model="form.role" class="field"><option v-for="role in roles" :key="role" :value="role">{{ roleLabels[role] }}</option></select></label>
        <button type="submit" class="admin-primary" :disabled="form.processing">Créer le compte</button>
        <p id="password-help" class="admin-muted account-form-help">Mot de passe : majuscule, minuscule, chiffre et symbole.</p>
        <p v-for="e in form.errors" :key="e" role="alert" class="account-form-help">{{ e }}</p>
      </form>
    </section>
    <section class="admin-card mt-4" aria-labelledby="comptes-existants">
      <div class="admin-section-heading"><div><h2 id="comptes-existants">Comptes existants</h2><p class="admin-muted">{{ users.length }} comptes · Gérez les accès, la sécurité et les rôles.</p></div><label class="account-search"><span class="sr-only">Rechercher un compte</span><input v-model="query" type="search" class="field" placeholder="Rechercher (nom, email, rôle…)" aria-controls="liste-comptes"></label></div>
      <p v-if="users.filter(u => u.active && u.role === 'administrateur').length === 1" class="my-3">Le dernier administrateur actif est protégé : son rôle et son accès doivent être conservés.</p>
      <div class="account-table-scroll" tabindex="0" role="region" aria-label="Liste des comptes, défilement horizontal si nécessaire">
        <table id="liste-comptes" class="account-table">
<caption class="sr-only">Comptes et actions disponibles</caption><thead><tr><th scope="col">Nom</th><th scope="col">Adresse électronique</th><th scope="col">Rôle</th><th scope="col">Statut</th><th scope="col">Actions</th></tr></thead><tbody>
          <AccountRow v-for="user in filtered" :key="user.id" :user="user" :roles="roles" :can-change-roles="canChangeRoles" :can-reset-two-factor="false" :challenge="twoFactorReset" />
          <tr v-if="!filtered.length"><td colspan="5" role="status">{{ users.length ? 'Aucun compte ne correspond à cette recherche.' : 'Aucun compte enregistré.' }}</td></tr>
        </tbody>
</table>
      </div>
    </section>
    <div class="account-settings mt-4">
      <section class="admin-card" aria-labelledby="rattachement-hotes">
<h2 id="rattachement-hotes">Rattachement des fiches hôte</h2><p class="admin-muted mb-3">Les fiches restent classées par événement.</p>
        <label v-if="hostUsers.length > 1" class="mb-3 block">Compte hôte<select :value="hostUser?.id" class="field" @change="selectedHost = ($event.target as HTMLSelectElement).value"><option v-for="u in hostUsers" :key="u.id" :value="u.id">{{ u.name }}</option></select></label>
        <HostAccountLink v-if="hostUser" :key="hostUser.id" :user="hostUser" :hosts="hosts" /><p v-else class="admin-muted">Créez un compte avec le rôle Hôte pour rattacher ses fiches.</p>
      </section>
      <section class="admin-card"><h2>Alerte sans réponse de l’hôte</h2><p class="admin-muted">Délai actuel : {{ hostTimeout }} minute(s).</p><form class="mt-4 space-y-3" @submit.prevent="hostSettings.put('/administration/comptes/delai-hote')"><label>Délai en minutes<input v-model="hostSettings.minutes" type="number" min="1" max="120" class="field" required></label><p v-for="e in hostSettings.errors" :key="e" role="alert">{{ e }}</p><FormButton :busy="hostSettings.processing">Enregistrer le délai</FormButton></form></section>
      <section class="admin-card"><h2>Déconnexion après inactivité</h2><p class="admin-muted">Durée actuelle : {{ idle }} minutes. Réglage commun à tous les comptes.</p><form class="mt-4 space-y-3" @submit.prevent="settings.put('/administration/comptes/inactivite')"><label>Nouvelle durée en minutes<input v-model="settings.minutes" class="field" type="number" min="1" max="120" required></label><p v-for="e in settings.errors" :key="e" role="alert">{{ e }}</p><FormButton :busy="settings.processing">Enregistrer la durée</FormButton></form></section>
    </div>
  </AdminLayout>
</template>
