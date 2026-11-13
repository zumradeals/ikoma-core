<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminIcon from '../admin/AdminIcon.vue';
import { useInstitution } from '../../composables/useInstitution';
import type { Centre, Movement } from './CentreSupervision.vue';

export type Poste = { id: string; code: string; name: string; function_label: string | null; state: 'actif' | 'silencieux' | 'inactif'; passages: number };
export type Activite = { id: string; name: string; kind: string; phase: 'en_cours' | 'a_venir' | 'terminee'; starts_at: string | null; ends_at: string | null; participants: number };
export type Tableau = { postes: Poste[]; activites: { en_cours: number; stages: number; evenements: number; rows: Activite[] } };
type Site = { presents: { total: number }; mouvements: { entrees: number; sorties: number; controles: number; refus: number }; postes: { total: number; actifs: number; silencieux: number } };

const props = defineProps<{ tableau: Tableau; site?: Site | null; centre?: Centre | null; openAlerts: number; href: (path: string) => string; canAlerts: boolean }>();
/** Fichier statique servi tel quel (dossier public) : il ne passe pas par le build. */
const PHOTO_URL = '/brand/ailct-batiment.jpg';
const institution = useInstitution();
const showPhoto = computed(() => institution.value.custom === true);
const silent = computed(() => props.site?.postes.silencieux ?? 0);
const operational = computed(() => props.openAlerts === 0 && silent.value === 0);
const plural = (n: number, one: string, many: string) => `${n} ${n > 1 ? many : one}`;

const waiting = computed(() => (props.centre?.visits.counts.attendu ?? 0) + (props.centre?.visits.counts.en_retard ?? 0));
const late = computed(() => props.centre?.visits.counts.en_retard ?? 0);
type Kpi = { key: string; label: string; value: number; icon: string; hint: string; tone: 'ok' | 'warn' | 'neutral' };
const kpis = computed<Kpi[]>(() => [
  { key: 'presents', label: 'Présents sur le site', value: props.site?.presents.total ?? 0, icon: 'users', hint: plural(props.site?.mouvements.entrees ?? 0, 'entrée sur 24 h', 'entrées sur 24 h'), tone: 'ok' },
  { key: 'vehicules', label: 'Véhicules en circulation', value: props.centre?.vehicles_count ?? 0, icon: 'bus', hint: 'sur le site', tone: 'ok' },
  { key: 'attendus', label: 'Visiteurs attendus', value: waiting.value, icon: 'calendar', hint: late.value ? plural(late.value, 'en retard', 'en retard') : 'aucun retard', tone: late.value ? 'warn' : 'ok' },
  { key: 'activites', label: 'Activités en cours', value: props.tableau.activites.en_cours, icon: 'check', hint: `${plural(props.tableau.activites.stages, 'stage', 'stages')} · ${plural(props.tableau.activites.evenements, 'événement', 'événements')}`, tone: 'neutral' },
  { key: 'postes', label: 'Postes opérationnels', value: props.site?.postes.actifs ?? 0, icon: 'shield', hint: silent.value ? plural(silent.value, 'sans nouvelles', 'sans nouvelles') : 'tous actifs', tone: silent.value ? 'warn' : 'ok' },
]);
const stateLabel = { actif: 'Actif', silencieux: 'Sans nouvelles', inactif: 'Désactivé' } as const;
const phaseLabel = { en_cours: 'En cours', a_venir: 'À venir', terminee: 'Terminée' } as const;
const kindLabel = (k: string) => (k === 'stage' ? 'Stage' : 'Événement');
const fmt = (d: string | null) => (d ? new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '—');
const period = (a: Activite) => (a.starts_at && a.ends_at && fmt(a.starts_at) !== fmt(a.ends_at) ? `${fmt(a.starts_at)} – ${fmt(a.ends_at)}` : fmt(a.starts_at));
const kindIcon = (k: string) => (k === 'stage' ? 'list' : 'users');

const moveTitle = (m: Movement) => (m.type === 'entree' ? 'Entrée confirmée' : m.type === 'sortie' ? 'Sortie enregistrée' : m.type === 'controle' ? (m.verdict === 'valide' && m.zone_ok !== 'false' ? 'Contrôle valide' : 'Accès refusé') : m.type === 'vehicule_entree' ? 'Entrée véhicule' : m.type === 'vehicule_sortie' ? 'Sortie véhicule' : 'Mouvement');
const moveIcon = (m: Movement) => (m.type === 'sortie' || m.type === 'vehicule_sortie' ? 'arrow' : m.type === 'controle' && !(m.verdict === 'valide' && m.zone_ok !== 'false') ? 'alert' : 'check');
const moveTime = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const moves = computed(() => (props.centre?.movements ?? []).slice(0, 3));
</script>
<template>
  <section class="tdb" aria-label="Centre de supervision des accès" data-testid="tableau-superviseur">
    <div class="tdb-main">
      <div class="tdb-head"><span class="tdb-pill" :data-ok="operational" data-testid="etat-systeme"><span aria-hidden="true">●</span> {{ operational ? 'Système opérationnel' : 'Points à surveiller' }}</span></div>

      <ul class="tdb-kpis" aria-label="Chiffres clés" data-testid="tdb-kpis">
        <li v-for="k in kpis" :key="k.key" class="tdb-kpi" :data-testid="'tdb-kpi-' + k.key">
          <span class="tdb-kpi-icon" :data-icon="k.key" aria-hidden="true"><AdminIcon :name="k.icon" :size="28" /></span>
          <p class="tdb-kpi-value" data-testid="valeur">{{ k.value }}</p><p class="tdb-kpi-label">{{ k.label }}</p>
          <p class="tdb-chip" :data-tone="k.tone" data-testid="indice">{{ k.hint }}</p>
        </li>
      </ul>

      <section class="tdb-card" aria-labelledby="tdb-postes" data-testid="tdb-postes">
        <div class="pilotage-section-heading"><h3 id="tdb-postes"><AdminIcon name="shield" :size="26" /> Postes de contrôle</h3><span v-if="site" class="pilotage-muted">{{ site.postes.actifs }} actif{{ site.postes.actifs > 1 ? 's' : '' }} sur {{ site.postes.total }}</span></div>
        <ul v-if="tableau.postes.length" class="tdb-postes">
          <li v-for="p in tableau.postes" :key="p.id" :data-testid="'tdb-poste-' + p.code" :data-state="p.state">
            <span class="tdb-poste-icon" aria-hidden="true"><AdminIcon name="shield" :size="26" /></span>
            <p class="tdb-poste-name">{{ p.name }}</p><p v-if="p.function_label" class="pilotage-muted">{{ p.function_label }}</p>
            <p class="tdb-poste-state"><span aria-hidden="true">●</span> {{ stateLabel[p.state] }}</p>
            <p class="tdb-poste-n"><strong>{{ p.passages }}</strong> passage{{ p.passages > 1 ? 's' : '' }} aujourd’hui</p>
          </li>
        </ul>
        <p v-else class="pilotage-empty">Aucun poste de contrôle n’est encore créé.</p>
      </section>

      <section class="tdb-card" aria-labelledby="tdb-activites" data-testid="tdb-activites">
        <div class="pilotage-section-heading"><h3 id="tdb-activites"><AdminIcon name="calendar" :size="26" /> Activités en cours</h3><span class="pilotage-muted" data-testid="tdb-activites-resume">{{ tableau.activites.en_cours }} en cours · {{ tableau.activites.stages }} stage{{ tableau.activites.stages > 1 ? 's' : '' }} · {{ tableau.activites.evenements }} événement{{ tableau.activites.evenements > 1 ? 's' : '' }}</span></div>
        <ul v-if="tableau.activites.rows.length" class="tdb-activites">
          <li v-for="a in tableau.activites.rows" :key="a.id" :data-phase="a.phase">
            <span class="tdb-activite-icon" aria-hidden="true"><AdminIcon :name="kindIcon(a.kind)" :size="24" /></span>
            <div class="tdb-activite-title"><p class="tdb-activite-name">{{ a.name }}</p><p class="pilotage-muted">{{ kindLabel(a.kind) }} · {{ a.participants }} participant{{ a.participants > 1 ? 's' : '' }}</p></div>
            <span class="tdb-phase">{{ phaseLabel[a.phase] }}</span>
            <span class="tdb-activite-period"><AdminIcon name="calendar" :size="20" /> {{ period(a) }}</span>
            <span class="tdb-activite-n"><AdminIcon name="user" :size="20" /> {{ a.participants }}</span>
          </li>
        </ul>
        <p v-else class="pilotage-empty">Aucune activité ouverte.</p>
      </section>
    </div>

    <aside class="tdb-side">
      <figure v-if="showPhoto" class="tdb-photo" data-testid="tdb-photo"><img :src="PHOTO_URL" alt="Entrée de l’Académie" width="1080" height="474" loading="lazy"></figure>

      <section class="tdb-card" aria-labelledby="tdb-alertes" data-testid="tdb-alertes">
        <div class="pilotage-section-heading"><h3 id="tdb-alertes"><AdminIcon name="alert" :size="26" /> Alertes &amp; situation opérationnelle</h3><Link v-if="canAlerts" :href="href('/pilotage/alertes')" class="pilotage-text-link">Voir le détail →</Link></div>
        <div class="tdb-situation" :data-ok="operational">
          <span class="tdb-situation-icon" aria-hidden="true"><AdminIcon :name="operational ? 'check' : 'alert'" :size="26" /></span>
          <div v-if="openAlerts === 0 && silent === 0"><p><strong>Aucune alerte en cours</strong></p><p>L’ensemble des postes et services est opérationnel.</p></div>
          <div v-else><p><strong>{{ openAlerts ? plural(openAlerts, 'alerte à traiter', 'alertes à traiter') : 'Aucune alerte à traiter' }}</strong></p><p>{{ silent ? plural(silent, 'poste sans nouvelles récentes.', 'postes sans nouvelles récentes.') : 'Ouvrez le détail pour les traiter.' }}</p></div>
        </div>
      </section>

      <section class="tdb-card" aria-labelledby="tdb-mouvements" data-testid="tdb-mouvements">
        <div class="pilotage-section-heading"><h3 id="tdb-mouvements"><AdminIcon name="history" :size="26" /> Derniers mouvements</h3><Link :href="href('/pilotage/mouvements')" class="pilotage-text-link">Voir l’historique →</Link></div>
        <ul v-if="moves.length" class="tdb-mouvements">
          <li v-for="m in moves" :key="m.id"><span class="tdb-move-icon" aria-hidden="true"><AdminIcon :name="moveIcon(m)" :size="22" /></span><div><p class="tdb-move-title">{{ moveTitle(m) }}</p><p class="pilotage-muted">{{ m.checkpoint ?? 'Poste inconnu' }}<template v-if="m.full_name"> · {{ m.full_name }}</template></p></div><time :datetime="m.occurred_at">{{ moveTime(m.occurred_at) }}</time></li>
        </ul>
        <p v-else class="pilotage-empty">Aucun mouvement récent.</p>
      </section>
    </aside>
  </section>
</template>
