<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminIcon from '../admin/AdminIcon.vue';
import { useInstitution } from '../../composables/useInstitution';

export type Poste = { id: string; code: string; name: string; function_label: string | null; state: 'actif' | 'silencieux' | 'inactif'; passages: number };
export type Activite = { id: string; name: string; kind: string; phase: 'en_cours' | 'a_venir' | 'terminee'; starts_at: string | null; ends_at: string | null; participants: number };
export type Tableau = { postes: Poste[]; activites: { en_cours: number; stages: number; evenements: number; rows: Activite[] } };
type Site = { presents: { total: number }; mouvements: { entrees: number; sorties: number; controles: number; refus: number }; postes: { total: number; actifs: number; silencieux: number } };

const props = defineProps<{ tableau: Tableau; site?: Site | null; openAlerts: number; href: (path: string) => string; canAlerts: boolean }>();
/** Fichier statique servi tel quel (dossier public) : il ne passe pas par le build. */
const PHOTO_URL = '/brand/ailct-batiment.jpg';
const institution = useInstitution();
const showPhoto = computed(() => institution.value.custom === true);
const operational = computed(() => props.openAlerts === 0 && (props.site?.postes.silencieux ?? 0) === 0);
const kpis = computed(() => [
  { key: 'presents', label: 'Personnes présentes', value: props.site?.presents.total ?? 0, hint: 'sur le site', icon: 'users' },
  { key: 'entrees', label: 'Entrées', value: props.site?.mouvements.entrees ?? 0, hint: 'sur 24 h', icon: 'arrow' },
  { key: 'sorties', label: 'Sorties', value: props.site?.mouvements.sorties ?? 0, hint: 'sur 24 h', icon: 'arrow' },
  { key: 'refus', label: 'Accès refusés', value: props.site?.mouvements.refus ?? 0, hint: 'sur 24 h', icon: 'shield' },
  { key: 'alertes', label: 'Alertes à traiter', value: props.openAlerts, hint: props.openAlerts ? 'à traiter' : 'aucune', icon: 'alert' },
]);
const stateLabel = { actif: 'Actif', silencieux: 'Sans nouvelles', inactif: 'Désactivé' } as const;
const phaseLabel = { en_cours: 'En cours', a_venir: 'À venir', terminee: 'Terminée' } as const;
const kindLabel = (k: string) => (k === 'stage' ? 'Stage' : 'Événement');
const fmt = (d: string | null) => (d ? new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—');
const period = (a: Activite) => (a.starts_at && a.ends_at && fmt(a.starts_at) !== fmt(a.ends_at) ? `${fmt(a.starts_at)} – ${fmt(a.ends_at)}` : fmt(a.starts_at));
</script>
<template>
  <section class="tdb" aria-labelledby="tdb-titre" data-testid="tableau-superviseur">
    <div class="tdb-head">
      <div><h2 id="tdb-titre">Centre de supervision des accès</h2><p class="pilotage-muted">Vue d’ensemble du site en temps réel.</p></div>
      <span class="tdb-pill" :data-ok="operational" data-testid="etat-systeme"><span aria-hidden="true">●</span> {{ operational ? 'Système opérationnel' : 'Points à surveiller' }}</span>
    </div>

    <ul class="tdb-kpis" aria-label="Chiffres clés" data-testid="tdb-kpis">
      <li v-for="k in kpis" :key="k.key" class="tdb-kpi" :data-testid="'tdb-kpi-' + k.key"><span class="tdb-kpi-icon" aria-hidden="true"><AdminIcon :name="k.icon" :size="24" /></span><div><p class="tdb-kpi-label">{{ k.label }}</p><p class="tdb-kpi-value" data-testid="valeur">{{ k.value }}</p><p class="pilotage-muted">{{ k.hint }}</p></div></li>
    </ul>

    <section class="tdb-card" aria-labelledby="tdb-postes" data-testid="tdb-postes">
      <div class="pilotage-section-heading"><h3 id="tdb-postes">Postes de contrôle</h3><span v-if="site" class="pilotage-muted">{{ site.postes.actifs }} actif{{ site.postes.actifs > 1 ? 's' : '' }} sur {{ site.postes.total }}</span></div>
      <ul v-if="tableau.postes.length" class="tdb-postes">
        <li v-for="p in tableau.postes" :key="p.id" :data-testid="'tdb-poste-' + p.code" :data-state="p.state">
          <p class="tdb-poste-name">{{ p.name }}</p><p v-if="p.function_label" class="pilotage-muted">{{ p.function_label }}</p>
          <p class="tdb-poste-state"><span aria-hidden="true">●</span> {{ stateLabel[p.state] }}</p>
          <p class="tdb-poste-n"><strong>{{ p.passages }}</strong> passage{{ p.passages > 1 ? 's' : '' }} aujourd’hui</p>
        </li>
      </ul>
      <p v-else class="pilotage-empty">Aucun poste de contrôle n’est encore créé.</p>
    </section>

    <div class="tdb-split">
      <section class="tdb-card" aria-labelledby="tdb-activites" data-testid="tdb-activites">
        <div class="pilotage-section-heading"><h3 id="tdb-activites">Activités</h3><span class="pilotage-muted" data-testid="tdb-activites-resume">{{ tableau.activites.en_cours }} en cours · {{ tableau.activites.stages }} stage{{ tableau.activites.stages > 1 ? 's' : '' }} · {{ tableau.activites.evenements }} événement{{ tableau.activites.evenements > 1 ? 's' : '' }}</span></div>
        <ul v-if="tableau.activites.rows.length" class="tdb-activites">
          <li v-for="a in tableau.activites.rows" :key="a.id" :data-phase="a.phase"><div><p class="tdb-activite-name">{{ a.name }}</p><p class="pilotage-muted">{{ kindLabel(a.kind) }} · {{ period(a) }}</p></div><div class="tdb-activite-side"><span class="tdb-phase">{{ phaseLabel[a.phase] }}</span><span class="pilotage-muted">{{ a.participants }} participant{{ a.participants > 1 ? 's' : '' }}</span></div></li>
        </ul>
        <p v-else class="pilotage-empty">Aucune activité ouverte.</p>
      </section>
      <figure v-if="showPhoto" class="tdb-photo" data-testid="tdb-photo"><img :src="PHOTO_URL" alt="Bâtiment de l’AILCT" width="1600" height="900" loading="lazy"><figcaption>{{ institution.fullName || institution.name }}</figcaption></figure>
      <section v-else-if="canAlerts" class="tdb-card"><h3>Alertes</h3><Link :href="href('/pilotage/alertes')" class="pilotage-text-link">Voir les alertes</Link></section>
    </div>
  </section>
</template>
