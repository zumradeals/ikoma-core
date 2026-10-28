<script setup lang="ts">
import { computed } from 'vue'; import { Link, router } from '@inertiajs/vue3';
import BaseLayout from '../layouts/BaseLayout.vue'; import BaseCard from '../components/BaseCard.vue';
type Member = { visitor_id: string; full_name: string; organisation: string | null; called_at: string | null; checked_at: string | null; confirmed_at: string | null; absent_at: string | null; boarded_at: string | null; arrived_at: string | null; badge_code: string | null };
const props = defineProps<{ groups: { id: string; name: string; bus_number: string | null }[]; state: { group: { id: string; name: string; bus_number: string | null; departed_at: string | null }; members: Member[]; progress: { total: number; badged: number; boarded: number; arrived: number; absent: number; complete: boolean } } | null }>();
const departed = computed(() => props.state?.group.departed_at != null);
function confirm(id: string) { router.post(`/mon-groupe/${id}/confirmer`, {}, { preserveScroll: true, async: true }); }
function label(m: Member): string { return m.absent_at ? 'Absent' : m.arrived_at ? 'Arrivé' : m.boarded_at ? 'Embarqué' : m.badge_code ? 'Badge remis' : m.confirmed_at ? 'Confirmé' : 'À confirmer'; }
</script>
<template>
<BaseLayout>
<Link href="/espace" class="block min-h-14 underline">Mon espace</Link><h1 class="mb-4 text-display font-bold">Mon groupe</h1>
<BaseCard v-if="!state"><p>Aucun groupe ne vous est confié pour le moment. Demandez à l’administrateur de vous désigner encadreur.</p></BaseCard>
<template v-else>
<nav v-if="groups.length > 1" class="mb-4 flex flex-wrap gap-3" aria-label="Mes groupes">
<Link v-for="g in groups" :key="g.id" :href="`/mon-groupe?groupe=${g.id}`" class="btn-soft" :aria-current="state.group.id === g.id ? 'page' : undefined">{{ g.bus_number ?? g.name }}</Link>
</nav>
<p class="mb-2 text-xl font-bold" data-testid="mon-groupe">{{ state.group.bus_number ?? state.group.name }} · {{ state.group.name }}</p>
<p class="mb-6 text-lg" data-testid="avancement">{{ state.progress.badged }} badge(s) remis sur {{ state.progress.total }} · {{ state.progress.boarded }} embarqué(s) · {{ state.progress.arrived }} arrivé(s)<template v-if="state.progress.absent"> · {{ state.progress.absent }} absent(s)</template></p>
<p v-if="departed" class="mb-6 rounded-xl border-2 border-amber-700 bg-amber-50 p-4 font-semibold text-amber-950" role="status">Le car est parti : les confirmations sont closes.</p>
<ul class="space-y-3">
<li v-for="m in state.members" :key="m.visitor_id">
<BaseCard>
<div class="flex flex-wrap items-center justify-between gap-3">
<div><p class="text-xl font-bold">{{ m.full_name }}</p><p><span aria-hidden="true">{{ m.confirmed_at ? '✓' : m.absent_at ? '✕' : '○' }}</span> {{ label(m) }}</p></div>
<button v-if="!m.confirmed_at && !departed" type="button" class="action !min-h-16" data-testid="confirmer" @click="confirm(m.visitor_id)">Confirmer la présence</button>
</div>
</BaseCard>
</li>
</ul>
</template>
</BaseLayout>
</template>
