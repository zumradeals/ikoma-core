<script setup lang="ts">
import { computed } from 'vue';
import AdminIcon from '../admin/AdminIcon.vue';
import type { SyncState } from '../../poste/local/sync';

/**
 * Barre basse du poste d'accueil : appareil et poste, synchronisation, sécurité. Tout vient de l'état réel de la tablette (coffre déverrouillé, dernière synchronisation) ; rien n'est supposé.
 * « Enrôlé » : cette barre n'existe que lorsque la tablette est enrôlée et déverrouillée.
 */
const props = defineProps<{ poste: string | null; device?: string | null; agent: string; online: boolean; sync?: SyncState | null }>();
const emit = defineEmits<{ sync: []; lock: [] }>();
const last = computed(() => (props.sync?.lastSyncAt ? new Date(props.sync.lastSyncAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : 'jamais'));
const synced = computed(() => !!props.sync && !!props.sync.lastSyncAt && !props.sync.stale && !props.sync.lastError);
</script>
<template>
  <section id="poste-barre-basse" class="poste-footer" aria-label="Appareil, synchronisation et sécurité" data-testid="poste-barre-basse">
    <div class="poste-footer-block" data-testid="bloc-appareil">
      <AdminIcon name="tablet" :size="30" />
      <div><p class="poste-footer-title">Appareil et poste <span class="poste-chip poste-chip-ok">Enrôlé</span></p><p>Poste : {{ poste ?? 'non choisi' }}</p><p v-if="device">Appareil : {{ device }}</p><p>Agent : {{ agent }}</p></div>
    </div>
    <div class="poste-footer-block" data-testid="bloc-synchronisation">
      <AdminIcon name="refresh" :size="28" />
      <div>
        <p class="poste-footer-title">Synchronisation <span v-if="synced" class="poste-chip poste-chip-ok" aria-label="À jour"><span aria-hidden="true">✓</span> À jour</span><span v-else class="poste-chip poste-chip-warn">{{ sync?.lastError ? 'Erreur' : 'À vérifier' }}</span></p>
        <p>Dernière : <strong data-testid="derniere-sync">{{ last }}</strong><template v-if="sync?.syncing"> · envoi en cours…</template></p>
        <button v-if="sync" type="button" class="poste-link" :disabled="sync.syncing" data-testid="synchroniser" @click="emit('sync')">Synchroniser maintenant</button>
      </div>
    </div>
    <div class="poste-footer-block" data-testid="bloc-securite">
      <AdminIcon name="lock" :size="28" />
      <div><p class="poste-footer-title">Sécurité</p><p>Poste déverrouillé</p></div>
    </div>
    <button type="button" class="btn-soft poste-footer-lock" data-testid="verrouiller" @click="emit('lock')"><AdminIcon name="lock" :size="20" /> Verrouiller le poste</button>
  </section>
</template>
