<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { pilotageHref } from '../../pilotage/navigation';
import PilotageLayout from '../../layouts/PilotageLayout.vue';

type Entitlement = { id: string; visitor_id: string; full_name: string; zone: string; kind: 'standard' | 'exceptionnelle'; reason: string | null; valid_from: string; valid_until: string; suspension_reason: string | null; state: 'active' | 'suspendue' | 'expiree' | 'a_venir' };
type ToReturn = { id: string; badge_id: string; code: string; visitor_id: string; full_name: string; status: string; closed_at: string | null; assigned_at: string };
defineProps<{ event: { id: string; name: string } | null; events: { id: string; name: string }[]; openAlerts: number; entitlements: Entitlement[]; badgesToReturn: ToReturn[] }>();
const states: Record<string, string> = { active: 'Active', suspendue: 'Suspendue', expiree: 'Expirée', a_venir: 'À venir' };
const when = (iso: string) => new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
</script>
<template>
  <PilotageLayout active="acces" :event="event" :events="events" :open-alerts="openAlerts" title="Accès et badges">
    <section class="space-y-3" aria-labelledby="titre-badges" data-testid="badges-a-recuperer">
      <h2 id="titre-badges" class="text-xl font-bold">Badges à récupérer ({{ badgesToReturn.length }})</h2>
      <p>Ces personnes sont sorties ou leur participation est clôturée, mais leur badge n’a pas été restitué. La restitution se fait au poste d’accueil.</p>
      <ul class="pilotage-record-grid">
        <li v-for="b in badgesToReturn" :key="b.id" class="rounded-2xl border-2 border-slate-300 bg-white p-4" data-testid="badge-a-recuperer">
          <Link :href="pilotageHref('/pilotage/visiteurs/' + b.visitor_id, event?.id)" class="underline"><strong>{{ b.full_name }}</strong></Link> · Badge <strong>{{ b.code }}</strong>
          <span class="block text-sm">Attribué le {{ when(b.assigned_at) }}</span>
        </li>
      </ul>
      <p v-if="!badgesToReturn.length" class="rounded-xl bg-white p-4">Aucun badge à récupérer.</p>
    </section>
    <section class="mt-6 space-y-3" aria-labelledby="titre-habilitations" data-testid="habilitations">
      <h2 id="titre-habilitations" class="text-xl font-bold">Habilitations de zone ({{ entitlements.length }})</h2>
      <p>Consultation seule : seul l’administrateur accorde, suspend ou retire une habilitation.</p>
      <ul class="pilotage-record-grid">
        <li v-for="e in entitlements" :key="e.id" class="rounded-2xl border-2 bg-white p-4" :class="e.kind === 'exceptionnelle' ? 'border-amber-600' : 'border-slate-300'" data-testid="habilitation">
          <Link :href="pilotageHref('/pilotage/visiteurs/' + e.visitor_id, event?.id)" class="underline"><strong>{{ e.full_name }}</strong></Link> → {{ e.zone }}
          <span class="block font-semibold">{{ e.kind === 'exceptionnelle' ? 'Exceptionnelle' : 'Standard' }} · {{ states[e.state] }}</span>
          <span class="block text-sm">Jusqu’au {{ when(e.valid_until) }}<template v-if="e.reason"> · Motif : {{ e.reason }}</template><template v-if="e.suspension_reason"> · Suspendue : {{ e.suspension_reason }}</template></span>
        </li>
      </ul>
      <p v-if="!entitlements.length" class="rounded-xl bg-white p-4">Aucune habilitation à surveiller.</p>
    </section>
  </PilotageLayout>
</template>
