<script setup lang="ts">
import { computed } from 'vue';
import CategorySwatch from '../CategorySwatch.vue';
import PortraitThumb from './PortraitThumb.vue';
import type { PosteRepository } from '../../poste/repository';
import type { Fiche } from '../../poste/types';

/**
 * Panneau « visiteur » : reste visible à chaque étape de l'accueil, pour que l'agent ait toujours sous les yeux la personne en cours.
 * Tout vient de la fiche locale ; la photo est celle qui vient d'être prise (disponible même hors ligne) ou, à défaut, le portrait lu en ligne.
 */
const props = defineProps<{ fiche: Fiche; repository: PosteRepository; photoUrl?: string | null; badgeCode?: string | null }>();
const when = (iso: string) => new Date(iso).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
const validity = computed(() => (props.fiche.valid_from && props.fiche.valid_until ? `Valable du ${when(props.fiche.valid_from)} au ${when(props.fiche.valid_until)}` : null));
</script>
<template>
  <aside class="poste-visitor" aria-label="Visiteur en cours" data-testid="panneau-visiteur">
    <div class="poste-visitor-photo">
      <img v-if="photoUrl" :src="photoUrl" :alt="'Photo de ' + fiche.full_name" data-testid="panneau-photo" width="260" height="180">
      <PortraitThumb v-else-if="fiche.photo_done" :repository="repository" :visitor-id="fiche.id" :name="fiche.full_name" :has-portrait="true" />
      <template v-else>
        <svg width="64" height="64" viewBox="0 0 72 72" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><circle cx="36" cy="26" r="12" /><path d="M12 62c3-13 13-20 24-20s21 7 24 20" /></svg>
        <span data-testid="panneau-sans-photo">Photo non prise</span>
      </template>
    </div>
    <p class="poste-visitor-name" data-testid="panneau-nom">{{ fiche.full_name }}</p>
    <p><CategorySwatch :name="fiche.category.name" :color="fiche.category.color" :priority="fiche.category.priority_circuit" /></p>
    <dl class="poste-visitor-meta">
      <div v-if="fiche.organisation"><dt class="sr-only">Organisation</dt><dd>{{ fiche.organisation }}</dd></div>
      <div v-if="fiche.host"><dt class="sr-only">Personne reçue</dt><dd>À voir : {{ fiche.host }}</dd></div>
      <div v-if="validity"><dt class="sr-only">Validité</dt><dd>{{ validity }}</dd></div>
    </dl>
    <p v-if="badgeCode" class="poste-visitor-badge" data-testid="panneau-badge"><small>Badge associé</small><strong>{{ badgeCode }}</strong></p>
  </aside>
</template>
