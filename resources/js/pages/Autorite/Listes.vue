<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import BaseLayout from '../../layouts/BaseLayout.vue'; import BaseCard from '../../components/BaseCard.vue';
import { displayDate } from '../../labels';
type Batch = { key: string; label: string; count: number; created_at: string | null };
type Row = { id: string; full_name: string; organisation: string | null; category: string; host: string | null };
const props = defineProps<{ event: { id: string; name: string } | null; batches: Batch[]; selected: string; visitors: Row[] }>();
const chosen = ref<string[]>([]);
const current = computed(() => props.batches.find(b => b.key === props.selected));
function decide(decision: 'approuver' | 'refuser', all: boolean) {
  const count = all ? (current.value?.count ?? 0) : chosen.value.length;
  if (count === 0) return;
  const what = decision === 'approuver' ? 'approuver' : 'refuser';
  if (!window.confirm(`${what[0].toUpperCase()}${what.slice(1)} ${count} invité${count > 1 ? 's' : ''} ?`)) return;
  router.post(`/autorite/listes/${props.selected}/${decision}`, { event: props.event?.id, ids: all ? undefined : chosen.value }, { preserveScroll: true, onSuccess: () => { chosen.value = []; } });
}
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link>
<h1 class="mb-2 text-display font-bold">Approbation des listes</h1>
<p v-if="event" class="mb-4">Événement : <strong>{{ event.name }}</strong>. Un invité n’est accueilli qu’après votre approbation. Vous ne voyez ici que ceux qui l’attendent.</p>
<p v-if="!batches.length" class="rounded-xl bg-white p-4" data-testid="rien-a-approuver">Aucun invité n’attend votre approbation.</p>
<div v-else class="space-y-6">
<nav aria-label="Lots en attente">
<ul class="flex flex-wrap gap-3">
<li v-for="b in batches" :key="b.key"><Link :href="`/autorite/listes?event=${event?.id}&lot=${b.key}`" class="btn-soft" :class="b.key === selected ? '!bg-ikoma-navy !text-white' : ''" :aria-current="b.key === selected ? 'page' : undefined" data-testid="lot">{{ b.label }} · {{ b.count }}</Link></li>
</ul>
</nav>
<BaseCard v-if="current">
<p v-if="current.created_at" class="mb-3">Arrivé le {{ displayDate(current.created_at) }} · {{ current.count }} invité{{ current.count > 1 ? 's' : '' }}</p>
<div class="mb-4 flex flex-wrap gap-3">
<button type="button" class="btn-main !w-auto" data-testid="approuver-lot" @click="decide('approuver', true)">Approuver tout le lot</button>
<button type="button" class="btn-soft" :disabled="!chosen.length" data-testid="approuver-selection" @click="decide('approuver', false)">Approuver la sélection ({{ chosen.length }})</button>
<button type="button" class="btn-soft" :disabled="!chosen.length" data-testid="refuser-selection" @click="decide('refuser', false)">Refuser la sélection</button>
</div>
<ul class="divide-y divide-slate-200" data-testid="liste-invites">
<li v-for="v in visitors" :key="v.id" class="flex min-h-14 items-center gap-3 py-2">
<input :id="'v-' + v.id" v-model="chosen" type="checkbox" :value="v.id" class="size-6" :aria-label="'Sélectionner ' + v.full_name">
<label :for="'v-' + v.id" class="flex-1"><strong>{{ v.full_name }}</strong><span class="block text-base">{{ v.category }} · {{ v.organisation || 'organisation non indiquée' }} · reçu par {{ v.host || 'non indiqué' }}</span></label>
</li>
</ul>
<p v-if="visitors.length < current.count" class="mt-3 text-base">Les {{ visitors.length }} premiers sont affichés. « Approuver tout le lot » concerne les {{ current.count }}.</p>
</BaseCard>
</div>
</BaseLayout>
</template>
