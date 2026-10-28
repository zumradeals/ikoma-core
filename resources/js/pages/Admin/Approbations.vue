<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue';
import { displayDate } from '../../labels';
type Pending = { id: string; summary: string; requester: string; created_at: string; expires_at: string; mine: boolean };
defineProps<{ pending: Pending[]; soleAdministrator: boolean }>();
const errors = useForm({});
function decide(item: Pending, action: 'approuver' | 'refuser') {
  if (action === 'refuser' && !window.confirm('Refuser cette demande ?')) return;
  router.post(`/administration/approbations/${item.id}/${action}`, {}, { preserveScroll: true });
}
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link>
<h1 class="mb-4 text-display font-bold">Administration · Validations</h1>
<p class="mb-6">Dans le profil de sécurité renforcé, une action critique (compte privilégié, retour au profil standard) n’est exécutée qu’après la validation d’un <strong>second administrateur</strong>. Vous ne pouvez pas valider votre propre demande.</p>
<p v-if="soleAdministrator" role="alert" class="mb-6 rounded-xl border-2 border-orange-700 bg-orange-50 p-4 font-semibold text-orange-950" data-testid="administrateur-unique"><span aria-hidden="true">⚠</span> Un seul administrateur est actif : la double validation ne peut pas s’appliquer. Créez un second administrateur.</p>
<p v-for="(message, key) in errors.errors" :key="key" role="alert" class="mb-4 rounded-xl border-2 border-red-700 bg-red-50 p-4 font-semibold text-red-900">{{ message }}</p>
<ul v-if="pending.length" class="space-y-4" data-testid="liste-approbations">
<li v-for="item in pending" :key="item.id">
<BaseCard>
<p class="text-xl font-bold" data-testid="resume">{{ item.summary }}</p>
<p class="mt-1">Demandé par {{ item.requester }} · {{ displayDate(item.created_at) }} · valable jusqu’au {{ displayDate(item.expires_at) }}</p>
<p v-if="item.mine" class="mt-3 font-semibold" data-testid="ma-demande"><span aria-hidden="true">◷</span> Votre demande : en attente d’un autre administrateur.</p>
<div v-else class="mt-4 flex flex-wrap gap-3">
<button type="button" class="btn-main !w-auto" data-testid="approuver" @click="decide(item, 'approuver')">Valider et exécuter</button>
<button type="button" class="btn-soft" data-testid="refuser" @click="decide(item, 'refuser')">Refuser</button>
</div>
</BaseCard>
</li>
</ul>
<p v-else class="rounded-xl bg-white p-4" data-testid="aucune-approbation">Aucune demande en attente.</p>
</BaseLayout>
</template>
