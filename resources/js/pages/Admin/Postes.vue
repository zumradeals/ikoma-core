<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue'; import FormButton from '../../components/FormButton.vue';
import { displayDate } from '../../labels';
type Poste = { id: string; name: string; station: string | null; event_id: string; event_name: string; state: 'non_enrole' | 'enrole' | 'revoque'; enrolled_at: string | null; last_synced_at: string | null; code_pending: boolean };
const props = defineProps<{
  events: { id: string; name: string }[]; postes: Poste[]; settings: { inactivity: number; skew: number; pin: number };
  newCode: { device_id: string; code: string } | null; conflicts: { id: string; kind: string; reason: string; operation_type: string | null; created_at: string; device_name: string }[];
}>();
const create = useForm({ event_id: props.events[0]?.id || '', name: '', station: '' });
const settings = useForm({ inactivity: props.settings.inactivity, skew: props.settings.skew, pin: props.settings.pin });
const states = { non_enrole: ['○', 'Non enrôlé'], enrole: ['●', 'Enrôlé'], revoque: ['⛔', 'Révoqué'] } as const;
const reasons: Record<string, string> = { deja_associe: 'Badge déjà associé', autre_poste: 'Badge réservé à un autre poste', deja_present: 'Visiteur déjà entré', agent_desactive: 'Agent désactivé', fiche_modifiee: 'Fiche modifiée entre-temps', horloge_suspecte: 'Horloge de la tablette décalée', hote_en_attente: 'Accord de l’hôte manquant', visiteur_refuse: 'Visiteur refusé', annule: 'Badge annulé' };
function revoke(poste: Poste) { if (window.confirm(`Révoquer « ${poste.station || poste.name} » ? La tablette sera effacée à sa prochaine connexion.`)) router.post(`/administration/postes/${poste.id}/revoquer`); }
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link><h1 class="mb-4 text-display font-bold">Administration · Postes</h1>
<p class="mb-6">Un poste est une tablette d’accueil. Pour l’enrôler : générez un code, saisissez-le sur la tablette dans les 15 minutes ; il ne sert qu’une fois. Une tablette révoquée est effacée dès qu’elle contacte le serveur.</p>
<div v-if="newCode" role="status" class="mb-6 rounded-2xl border-4 border-ikoma-orange bg-orange-50 p-6" data-testid="code">
  <p class="text-heading font-bold">Code d’enrôlement (affiché une seule fois)</p>
  <p class="my-3 text-display font-bold tracking-widest">{{ newCode.code }}</p>
  <p>Valable 15 minutes, à usage unique. Notez-le maintenant : il ne sera plus affiché.</p>
</div>
<div class="space-y-6">
<BaseCard>
<h2 class="mb-5 text-heading font-bold">Créer un poste</h2>
<form class="space-y-5" aria-label="Créer un poste" @submit.prevent="create.post('/administration/postes', { onSuccess: () => create.reset('name', 'station') })">
<label class="block">Événement<select v-model="create.event_id" class="field" required><option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option></select></label>
<label class="block">Nom de la tablette<input v-model="create.name" class="field" maxlength="120" required></label>
<label class="block">Nom du poste d’accueil<input v-model="create.station" class="field" maxlength="120"></label>
<p v-for="error in create.errors" :key="error" role="alert">{{ error }}</p>
<FormButton :busy="create.processing">Créer le poste</FormButton>
</form>
</BaseCard>
<BaseCard v-for="poste in postes" :key="poste.id">
<h2 class="text-heading font-bold">{{ poste.station || poste.name }}</h2><p>{{ poste.name }} · {{ poste.event_name }}</p>
<p class="my-3"><span class="inline-flex items-center gap-2 rounded-full border-2 border-ikoma-blue px-4 py-2 font-semibold"><span aria-hidden="true">{{ states[poste.state][0] }}</span>{{ states[poste.state][1] }}</span><span v-if="poste.code_pending" class="ml-3">Un code d’enrôlement est en attente.</span></p>
<p>Dernière synchronisation : {{ poste.last_synced_at ? displayDate(poste.last_synced_at) : 'jamais' }}</p>
<div class="mt-4 flex flex-wrap gap-4"><button type="button" class="action" @click="router.post(`/administration/postes/${poste.id}/code`)">{{ poste.state === 'enrole' ? 'Réenrôler : nouveau code' : 'Générer un code d’enrôlement' }}</button><button v-if="poste.state === 'enrole'" type="button" class="min-h-14 rounded-xl border-2 border-red-700 px-6 py-3 font-semibold text-red-800" @click="revoke(poste)">Révoquer ce poste</button></div>
</BaseCard>
<p v-if="!postes.length">Aucun poste pour le moment.</p>
<BaseCard>
<h2 class="mb-5 text-heading font-bold">Réglages des postes</h2>
<form class="space-y-5" aria-label="Réglages des postes" @submit.prevent="settings.put('/administration/postes/reglages')">
<label class="block">Verrouillage après inactivité (minutes)<input v-model.number="settings.inactivity" type="number" min="1" max="60" class="field" required></label>
<label class="block">Écart d’horloge toléré avant alerte (secondes)<input v-model.number="settings.skew" type="number" min="30" max="3600" class="field" required></label>
<label class="block">Échecs de PIN avant effacement de la tablette<input v-model.number="settings.pin" type="number" min="3" max="30" class="field" required></label>
<p v-for="error in settings.errors" :key="error" role="alert">{{ error }}</p>
<FormButton :busy="settings.processing">Enregistrer les réglages</FormButton>
</form>
</BaseCard>
<BaseCard>
<h2 class="mb-5 text-heading font-bold">Conflits de synchronisation ouverts</h2>
<ul class="space-y-3"><li v-for="conflict in conflicts" :key="conflict.id" class="rounded-xl border border-slate-300 p-4"><strong>{{ reasons[conflict.reason] || conflict.reason }}</strong><br>{{ conflict.device_name }} · {{ displayDate(conflict.created_at) }}<template v-if="conflict.operation_type"> · opération « {{ conflict.operation_type }} »</template></li></ul>
<p v-if="!conflicts.length">Aucun conflit en attente.</p><p class="mt-3">Le superviseur les traitera dans l’écran de pilotage (lot L8).</p>
</BaseCard>
</div>
</BaseLayout>
</template>
