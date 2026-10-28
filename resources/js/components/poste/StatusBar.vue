<script setup lang="ts">
import { computed } from 'vue';
import type { SyncState } from '../../poste/local/sync';
const props = defineProps<{ poste: string | null; agent: string; online: boolean; pending: number; sync?: SyncState | null }>();
const emit = defineEmits<{ sync: []; lock: [] }>();
const last = computed(() => (props.sync?.lastSyncAt ? new Date(props.sync.lastSyncAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : 'jamais'));
</script>
<template>
  <header class="bg-ikoma-navy px-4 py-3 text-white" aria-label="État du poste">
    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
      <strong class="text-xl">IKOMA <span class="font-normal">Accueil</span></strong>
      <span class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-1 font-semibold" :class="online ? 'text-green-800' : 'text-red-800'" role="status"><span aria-hidden="true">{{ online ? '●' : '✕' }}</span>{{ online ? 'En ligne' : 'Hors ligne' }}</span>
      <span>Opérations en attente : <strong data-testid="en-attente">{{ pending }}</strong></span>
      <span>Poste : <strong>{{ poste ?? 'non choisi' }}</strong></span>
      <span>Agent : <strong>{{ agent }}</strong></span>
      <span v-if="sync">Dernière synchronisation : <strong data-testid="derniere-sync">{{ last }}</strong><template v-if="sync.syncing"> · envoi en cours…</template></span>
    </div>
    <div v-if="sync" class="mt-3 flex flex-wrap items-center gap-3">
      <button type="button" class="btn-soft !min-h-12 !py-1" :disabled="sync.syncing" data-testid="synchroniser" @click="emit('sync')">Synchroniser maintenant</button>
      <button type="button" class="btn-soft !min-h-12 !py-1" data-testid="verrouiller" @click="emit('lock')">Verrouiller</button>
    </div>
    <p v-if="sync?.needsLogin" role="alert" class="mt-2 rounded-lg bg-amber-100 px-3 py-2 font-semibold text-amber-950" data-testid="reconnexion"><span aria-hidden="true">⚠</span> Votre session a expiré : <a class="underline" href="/login">reconnectez-vous</a> pour envoyer vos opérations (elles restent enregistrées sur la tablette).</p>
    <p v-if="sync?.stale" class="mt-2 rounded-lg bg-amber-100 px-3 py-2 font-semibold text-amber-950" data-testid="liste-ancienne"><span aria-hidden="true">⚠</span> La liste des visiteurs n’a pas été actualisée depuis plus d’une heure : un visiteur ajouté ou modifié depuis peut manquer.</p>
    <p v-if="sync?.clockWarning" class="mt-2 rounded-lg bg-amber-100 px-3 py-2 font-semibold text-amber-950" data-testid="horloge"><span aria-hidden="true">⚠</span> L’heure de cette tablette est décalée : signalez-le au superviseur.</p>
    <p v-if="sync?.lastError" class="mt-2 rounded-lg bg-amber-100 px-3 py-2 font-semibold text-amber-950" role="alert"><span aria-hidden="true">⚠</span> {{ sync.lastError }}</p>
  </header>
</template>
