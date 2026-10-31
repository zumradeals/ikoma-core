<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AgentLayout from '../../layouts/AgentLayout.vue';
import AdminIcon from '../admin/AdminIcon.vue';
import { PosteDb, getMeta } from '../../poste/local/db';
import { wipePilotageLocal } from '../../pilotage/evacuation';
const page = usePage<{ f06Active?: boolean }>();
const props = defineProps<{ agent: { id?: string; name: string; permissions: string[] } }>();
const online = ref(navigator.onLine);
const local = ref<{ enrolled: boolean; poste?: string; last?: string | null } | null>(null);
const unavailable = ref(false);
const db = new PosteDb();
const setOnline = () => { online.value = navigator.onLine; };
const cards = [
  { title: 'Poste d’accueil', text: 'Accueillir un visiteur, de la recherche à l’entrée.', icon: 'users', href: '/poste', right: 'visiteurs.rechercher', tone: 'blue' },
  { title: 'Groupes et cars', text: 'Appel nominal, badges et arrivée des groupes.', icon: 'bus', href: '/groupe', right: 'groupes.appel_nominal', tone: 'orange' },
  { title: 'Contrôle sur site', text: 'Vérifier un badge et enregistrer une sortie.', icon: 'qr', href: '/controle', right: 'badges.controler', tone: 'green' },
  { title: 'Sécurité', text: 'Protéger votre compte et accéder à ses réglages.', icon: 'shield', href: '/securite', right: null, tone: 'navy' },
];
onMounted(async () => {
  window.addEventListener('online', setOnline); window.addEventListener('offline', setOnline);
  try { const [vault, poste, last] = await Promise.all([getMeta(db, 'vault'), getMeta(db, 'posteName'), getMeta(db, 'lastSyncAt')]); local.value = { enrolled: !!vault, poste, last }; }
  catch { unavailable.value = true; }
});
onBeforeUnmount(() => { window.removeEventListener('online', setOnline); window.removeEventListener('offline', setOnline); db.close(); });
</script>
<template>
  <AgentLayout active="home" :agent="agent.name" :online="online" :permissions="agent.permissions">
    <div class="agent-page-heading"><div><h1>Mon espace</h1><p>Choisissez une fonction pour commencer.</p></div><span class="agent-section-label">Accueil des visiteurs</span></div>
    <section class="agent-home-grid" aria-label="Fonctions de l’agent"><template v-for="card in cards" :key="card.href"><a v-if="(!card.right || props.agent.permissions.includes(card.right)) && (card.href !== '/groupe' || page.props.f06Active === true)" :href="online ? card.href : undefined" :aria-disabled="!online || undefined" class="agent-function-card" :class="'tone-' + card.tone"><span class="agent-function-icon"><AdminIcon :name="card.icon" :size="32" /></span><div><h2>{{ card.title }}</h2><p>{{ card.text }}</p></div><span aria-hidden="true">›</span></a></template></section>
    <section class="agent-card agent-device-info" aria-labelledby="appareil-titre"><div class="agent-section-heading"><h2 id="appareil-titre"><AdminIcon name="shield" /> Informations de cet appareil</h2><a v-if="online && agent.permissions.includes('visiteurs.rechercher')" href="/poste" class="agent-text-link">Ouvrir le poste</a></div><p v-if="unavailable" role="status">Informations locales indisponibles. Ouvrez le poste pour vérifier l’appareil.</p><dl v-else-if="local" class="agent-device-grid"><div><dt>Poste mémorisé sur l’appareil</dt><dd>{{ local.poste || 'À définir lors de l’enrôlement' }}</dd></div><div><dt>Tablette</dt><dd>{{ local.enrolled ? 'Copie locale protégée · PIN requis' : 'À enrôler' }}</dd></div><div><dt>Dernière synchronisation connue</dt><dd>{{ local.last ? new Date(local.last).toLocaleString('fr-FR') : 'Aucune synchronisation connue' }}</dd></div></dl><p v-else role="status">Lecture de l’état de l’appareil…</p><p class="agent-help">L’affectation est vérifiée au déverrouillage du poste. Les informations de l’appareil ne remplacent pas ce contrôle.</p></section>
    <Link as="button" method="post" href="/logout" class="agent-text-link" @click="wipePilotageLocal">Se déconnecter</Link>
  </AgentLayout>
</template>
