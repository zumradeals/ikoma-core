<script lang="ts">
export type Poste = { id: string; name: string; point_code: string | null; state: 'actif' | 'silencieux' | 'revoque'; last_synced_at: string | null; last_operation: { type: string; at: string; accepted: boolean } | null };
export type Operation = { id: string; type: string; at: string; accepted: boolean; poste: string };
export type PresentRow = { id: string; full_name: string; zone: string | null; entered_at: string; category: { name: string; color: string; priority_circuit: boolean } };
export type Movement = { id: string; occurred_at: string; type: string; checkpoint: string | null; visitor_id: string | null; full_name: string | null; badge_code: string | null; verdict: string | null; zone_ok: string | null; direction?: string | null };
export type VisitRow = { id: string; full_name: string; expected_at: string; state: string; plates: string | null; host: string | null };
export type VehicleRow = { id: string; plate: string; kind: string; entered_at: string; visitor_id: string; full_name: string };
export type ReturnRow = { id: string; code: string; visitor_id: string; full_name: string; status: string | null; closed_at: string | null; assigned_at: string };
export type RefusalRow = { id: string; reason: string; refused_at: string; decision: string | null; visitor_id: string; full_name: string };
export type Incident = { id: string; occurred_at: string; checkpoint: string | null; visitor_id: string | null; full_name: string | null; badge_code: string | null; zone_ok: string | null };
export type Closable = { id: string; full_name: string; status: string; category: string; color: string; badge_code: string | null; vehicles_present: number };
export type Centre = {
  now: string; postes: Poste[]; operations: Operation[]; presents: { count: number; rows: PresentRow[] }; movements: Movement[];
  visits: { counts: Record<string, number>; late_after_minutes: number; rows: VisitRow[] }; vehicles: VehicleRow[]; vehicles_count: number;
  badges_to_return: { count: number; rows: ReturnRow[] }; refusals: { pending: number; rows: RefusalRow[] }; incidents: Incident[]; closable: Closable[];
};
</script>
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { pilotageHref } from '../../pilotage/navigation';
import SupervisorClosure from './SupervisorClosure.vue';

/**
 * Centre de supervision : tous les blocs sont visibles ensemble. Un clic sur « Détail » ouvre un panneau latéral SANS quitter le tableau de bord
 * (aucune navigation, le tableau continue de se rafraîchir derrière). La clôture réservée n'est proposée qu'à qui détient le droit dédié (le serveur la revérifie).
 */
const props = defineProps<{ centre: Centre; eventId: string; eventName?: string; canClose: boolean }>();
type Kind = 'postes' | 'presents' | 'visites' | 'vehicules' | 'badges' | 'refus' | 'cloture';
const detail = ref<Kind | null>(null);
const closing = ref<string | null>(null);

// Horloge à la seconde, calée sur l'heure du serveur (l'écart de l'appareil est corrigé une fois, puis l'horloge avance seule).
const offset = ref(0);
const tick = ref(Date.now());
let timer: number | undefined;
watch(() => props.centre.now, now => { offset.value = Date.parse(now) - Date.now(); }, { immediate: true });
onMounted(() => { timer = window.setInterval(() => { tick.value = Date.now(); }, 1000); });
onBeforeUnmount(() => window.clearInterval(timer));
const clock = computed(() => new Date(tick.value + offset.value));
const clockTime = computed(() => clock.value.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
const clockDate = computed(() => clock.value.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));

const href = (path: string) => pilotageHref(path, props.eventId);
const hm = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—');
const opLabels: Record<string, string> = {
  controle: 'Contrôle', sortie: 'Sortie', entree: 'Entrée', badge: 'Badge remis', valider: 'Admission', refuser: 'Refus', identite: 'Identité', non_liste: 'Non listé', restitution: 'Restitution',
  vehicule_entree: 'Entrée véhicule', vehicule_sortie: 'Sortie véhicule', groupe_embarquer: 'Embarquement', groupe_absent: 'Absence car', groupe_depart: 'Départ du car', groupe_arrivee: 'Arrivée du car', groupe_badge: 'Badge de groupe',
};
const moveLabels: Record<string, string> = { entree: 'Entrée', sortie: 'Sortie', controle: 'Contrôle', correction: 'Correction', embarquement: 'Embarquement', debarquement: 'Débarquement', vehicule_entree: 'Entrée véhicule', vehicule_sortie: 'Sortie véhicule' };
const pointLabels: Record<string, string> = { poste_1: 'Poste 1', poste_2: 'Poste 2 (accueil)', poste_3: 'Poste 3', poste_4: 'Poste 4', soute: 'Soute (P5)', hotel: 'Hôtel' };
const stateLabels: Record<string, string> = { actif: 'En service', silencieux: 'Sans nouvelles', revoque: 'Révoqué' };
const kinds: Record<string, string> = { voiture: 'Voiture', moto: 'Moto', minibus: 'Minibus', camion: 'Camion', autre: 'Autre' };
const moveText = (m: Movement) => (m.type === 'controle' ? (m.verdict === 'valide' && m.zone_ok !== 'false' ? 'Contrôle valide' : m.zone_ok === 'false' ? 'Zone non autorisée' : 'Contrôle refusé') + (m.direction ? ' · ' + (m.direction === 'entree' ? 'entrée' : 'sortie') : '') : moveLabels[m.type] ?? m.type);
const lateRows = computed(() => props.centre.visits.rows.filter(r => r.state === 'en_retard'));
const silent = computed(() => props.centre.postes.filter(p => p.state !== 'actif').length);
const titles: Record<Kind, string> = { postes: 'Postes et dernières opérations', presents: 'Personnes présentes', visites: 'Visites annoncées', vehicules: 'Véhicules présents', badges: 'Badges non restitués', refus: 'Refus et incidents', cloture: 'Clôture réservée au superviseur' };
const closeTarget = computed(() => props.centre.closable.find(c => c.id === closing.value) ?? null);
function open(kind: Kind) { detail.value = kind; closing.value = null; }
function close() { detail.value = null; closing.value = null; }
</script>

<template>
  <section class="centre" aria-label="Centre de supervision" data-testid="centre">
    <div class="centre-clock" role="timer" aria-live="off">
      <time :datetime="clock.toISOString()" data-testid="horloge">{{ clockTime }}</time>
      <p data-testid="date-heure" class="capitalize">{{ clockDate }}<span v-if="eventName"> · {{ eventName }}</span></p>
    </div>

    <div class="centre-grid">
      <article class="pilotage-card centre-card" aria-labelledby="c-postes" data-testid="centre-postes">
        <div class="pilotage-section-heading"><h3 id="c-postes">Postes</h3><span v-if="silent" class="pilotage-tag">▲ {{ silent }} sans nouvelles</span></div>
        <ul v-if="centre.postes.length" class="centre-list">
          <li v-for="p in centre.postes.slice(0, 5)" :key="p.id" :data-testid="'poste-' + p.id">
<span>{{ p.name }} <small>({{ pointLabels[p.point_code ?? ''] ?? 'non identifié' }})</small></span><span :class="p.state === 'actif' ? 'centre-ok' : 'centre-warn'">{{ stateLabels[p.state] }}</span>
            <small v-if="p.last_operation">Dernière opération : {{ opLabels[p.last_operation.type] ?? p.last_operation.type }} à {{ hm(p.last_operation.at) }}<template v-if="!p.last_operation.accepted"> · refusée</template></small>
            <small v-else>Aucune opération reçue</small>
</li>
        </ul>
        <p v-else class="pilotage-empty" data-testid="aucun-poste">Aucun poste enrôlé pour cet événement.</p>
        <ul v-if="centre.operations.length" class="centre-list" aria-label="Dernières opérations" data-testid="dernieres-operations">
          <li v-for="o in centre.operations.slice(0, 4)" :key="o.id"><span>{{ opLabels[o.type] ?? o.type }} · {{ o.poste }}</span><span :class="o.accepted ? '' : 'centre-bad'">{{ hm(o.at) }}<template v-if="!o.accepted"> · refusée</template></span></li>
        </ul>
        <button type="button" class="btn-soft" data-testid="detail-postes" @click="open('postes')">Détail des postes</button>
      </article>

      <article class="pilotage-card centre-card" aria-labelledby="c-presents" data-testid="centre-presents">
        <h3 id="c-presents">Présents et mouvements</h3>
        <p class="centre-big" data-testid="presents-total">{{ centre.presents.count }} <small class="text-base font-normal">personne{{ centre.presents.count > 1 ? 's' : '' }} présente{{ centre.presents.count > 1 ? 's' : '' }}</small></p>
        <ul v-if="centre.movements.length" class="centre-list" aria-label="Mouvements récents" data-testid="mouvements-recents">
          <li v-for="m in centre.movements.slice(0, 5)" :key="m.id"><span>{{ moveText(m) }}<template v-if="m.full_name"> · {{ m.full_name }}</template></span><span>{{ hm(m.occurred_at) }}</span></li>
        </ul>
        <p v-else class="pilotage-empty">Aucun mouvement enregistré.</p>
        <div class="flex flex-wrap gap-3"><button type="button" class="btn-soft" data-testid="detail-presents" @click="open('presents')">Détail des présents</button><Link :href="href('/pilotage/mouvements')" class="pilotage-text-link">Tous les mouvements</Link></div>
      </article>

      <article class="pilotage-card centre-card" aria-labelledby="c-visites" data-testid="centre-visites">
        <div class="pilotage-section-heading"><h3 id="c-visites">Visites annoncées</h3><span v-if="lateRows.length" class="pilotage-tag" data-testid="visites-retard">▲ {{ lateRows.length }} en retard</span></div>
        <p data-testid="visites-compteurs">{{ centre.visits.counts.attendu ?? 0 }} attendue{{ (centre.visits.counts.attendu ?? 0) > 1 ? 's' : '' }} · {{ centre.visits.counts.en_retard ?? 0 }} en retard · {{ centre.visits.counts.absent ?? 0 }} absente{{ (centre.visits.counts.absent ?? 0) > 1 ? 's' : '' }} constatée{{ (centre.visits.counts.absent ?? 0) > 1 ? 's' : '' }}</p>
        <ul v-if="centre.visits.rows.length" class="centre-list">
          <li v-for="v in centre.visits.rows.slice(0, 4)" :key="v.id"><span>{{ v.full_name }}</span><span :class="v.state === 'en_retard' ? 'centre-warn' : ''">{{ v.state === 'en_retard' ? 'En retard · ' : '' }}{{ hm(v.expected_at) }}</span></li>
        </ul>
        <p v-else class="pilotage-empty">Aucune visite annoncée en attente.</p>
        <div class="flex flex-wrap gap-3"><button type="button" class="btn-soft" data-testid="detail-visites" @click="open('visites')">Détail des visites</button><Link :href="href('/pilotage/attendus')" class="pilotage-text-link">Constater une absence</Link></div>
      </article>

      <article class="pilotage-card centre-card" aria-labelledby="c-vehicules" data-testid="centre-vehicules">
        <h3 id="c-vehicules">Véhicules présents</h3>
        <p class="centre-big" data-testid="vehicules-total">{{ centre.vehicles_count }}</p>
        <ul v-if="centre.vehicles.length" class="centre-list"><li v-for="x in centre.vehicles.slice(0, 4)" :key="x.id"><span><strong>{{ x.plate }}</strong> · {{ kinds[x.kind] ?? x.kind }}</span><span>{{ x.full_name }}</span></li></ul>
        <p v-else class="pilotage-empty" data-testid="aucun-vehicule-centre">Aucun véhicule présent sur le site.</p>
        <button type="button" class="btn-soft" data-testid="detail-vehicules" @click="open('vehicules')">Détail des véhicules</button>
      </article>

      <article class="pilotage-card centre-card" aria-labelledby="c-badges" data-testid="centre-badges">
        <div class="pilotage-section-heading"><h3 id="c-badges">Badges non restitués</h3><span v-if="centre.badges_to_return.count" class="pilotage-tag">▲ À récupérer</span></div>
        <p class="centre-big" data-testid="badges-total">{{ centre.badges_to_return.count }}</p>
        <p class="text-sm">Badges encore attribués à une personne sortie ou clôturée : la sortie ne vaut pas restitution.</p>
        <ul v-if="centre.badges_to_return.rows.length" class="centre-list"><li v-for="b in centre.badges_to_return.rows.slice(0, 4)" :key="b.id"><span><strong>{{ b.code }}</strong> · {{ b.full_name }}</span></li></ul>
        <button type="button" class="btn-soft" data-testid="detail-badges" @click="open('badges')">Détail des badges</button>
      </article>

      <article class="pilotage-card centre-card" aria-labelledby="c-refus" data-testid="centre-refus">
        <div class="pilotage-section-heading"><h3 id="c-refus">Refus et incidents</h3><span v-if="centre.refusals.pending" class="pilotage-count">{{ centre.refusals.pending }}</span></div>
        <p data-testid="refus-compteur">{{ centre.refusals.pending }} refus à décider · {{ centre.incidents.length }} contrôle{{ centre.incidents.length > 1 ? 's' : '' }} refusé{{ centre.incidents.length > 1 ? 's' : '' }} récent{{ centre.incidents.length > 1 ? 's' : '' }}</p>
        <ul v-if="centre.incidents.length" class="centre-list"><li v-for="i in centre.incidents.slice(0, 3)" :key="i.id"><span>{{ i.zone_ok === 'false' ? 'Zone non autorisée' : 'Contrôle refusé' }}<template v-if="i.checkpoint"> · {{ i.checkpoint }}</template></span><span>{{ hm(i.occurred_at) }}</span></li></ul>
        <div class="flex flex-wrap gap-3"><button type="button" class="btn-soft" data-testid="detail-refus" @click="open('refus')">Détail</button><Link :href="href('/pilotage/alertes')" class="pilotage-text-link">Alertes</Link></div>
      </article>

      <article v-if="canClose" class="pilotage-card centre-card" aria-labelledby="c-cloture" data-testid="centre-cloture">
        <h3 id="c-cloture">Clôture réservée</h3>
        <p data-testid="cloture-compteur">{{ centre.closable.length }} personne{{ centre.closable.length > 1 ? 's' : '' }} à clôture réservée, participation ouverte.</p>
        <button type="button" class="btn-main" :disabled="!centre.closable.length" data-testid="detail-cloture" @click="open('cloture')">Ouvrir la clôture</button>
      </article>
    </div>

    <aside v-if="detail" class="centre-drawer" role="dialog" aria-modal="false" :aria-label="titles[detail]" data-testid="panneau-detail" @keydown.esc="close">
      <div class="pilotage-section-heading"><h2 class="text-heading font-bold">{{ titles[detail] }}</h2><button type="button" class="btn-soft !w-auto" data-testid="fermer-detail" @click="close">Fermer</button></div>
      <p class="text-sm">Le tableau de bord reste visible et continue de se mettre à jour.</p>

      <template v-if="detail === 'postes'">
        <ul class="centre-list"><li v-for="p in centre.postes" :key="p.id"><span>{{ p.name }} <small>({{ pointLabels[p.point_code ?? ''] ?? 'non identifié' }})</small></span><span :class="p.state === 'actif' ? 'centre-ok' : 'centre-warn'">{{ stateLabels[p.state] }}</span><small>Synchronisé à {{ hm(p.last_synced_at) }}<template v-if="p.last_operation"> · {{ opLabels[p.last_operation.type] ?? p.last_operation.type }} à {{ hm(p.last_operation.at) }}</template></small></li></ul>
        <ul class="centre-list" aria-label="Dernières opérations"><li v-for="o in centre.operations" :key="o.id"><span>{{ opLabels[o.type] ?? o.type }} · {{ o.poste }}</span><span :class="o.accepted ? '' : 'centre-bad'">{{ hm(o.at) }}<template v-if="!o.accepted"> · refusée</template></span></li></ul>
      </template>

      <template v-else-if="detail === 'presents'">
        <ul class="centre-list" data-testid="detail-liste-presents"><li v-for="p in centre.presents.rows" :key="p.id"><span><Link :href="href('/pilotage/visiteurs/' + p.id)" class="underline">{{ p.full_name }}</Link> · {{ p.category.name }}</span><span>{{ p.zone ?? 'Accueil' }} · depuis {{ hm(p.entered_at) }}</span></li></ul>
        <p v-if="centre.presents.count > centre.presents.rows.length">{{ centre.presents.count - centre.presents.rows.length }} autre(s) : <Link :href="href('/pilotage/presents')" class="underline">liste complète</Link>.</p>
        <ul class="centre-list" aria-label="Mouvements récents"><li v-for="m in centre.movements" :key="m.id"><span>{{ moveText(m) }}<template v-if="m.full_name"> · {{ m.full_name }}</template><template v-if="m.checkpoint"> · {{ m.checkpoint }}</template></span><span>{{ hm(m.occurred_at) }}</span></li></ul>
      </template>

      <template v-else-if="detail === 'visites'">
        <ul class="centre-list" data-testid="detail-liste-visites"><li v-for="v in centre.visits.rows" :key="v.id"><span><Link :href="href('/pilotage/visiteurs/' + v.id)" class="underline">{{ v.full_name }}</Link><template v-if="v.plates"> · {{ v.plates }}</template></span><span :class="v.state === 'en_retard' ? 'centre-warn' : ''">{{ v.state === 'en_retard' ? 'En retard · ' : 'Attendue · ' }}{{ hm(v.expected_at) }}</span></li></ul>
        <p v-if="!centre.visits.rows.length" class="pilotage-empty">Aucune visite en attente.</p>
        <Link :href="href('/pilotage/attendus?etat=en_retard')" class="btn-main" data-testid="ouvrir-attendus">Constater une absence (visites annoncées)</Link>
      </template>

      <template v-else-if="detail === 'vehicules'">
        <ul class="centre-list" data-testid="detail-liste-vehicules"><li v-for="x in centre.vehicles" :key="x.id"><span><strong>{{ x.plate }}</strong> · {{ kinds[x.kind] ?? x.kind }}</span><span>{{ x.full_name }} · depuis {{ hm(x.entered_at) }}</span></li></ul>
        <p v-if="!centre.vehicles.length" class="pilotage-empty">Aucun véhicule présent.</p>
        <p class="text-sm">Un véhicule présent n’indique pas que la personne est présente. Sa sortie s’enregistre à un poste d’accueil.</p>
      </template>

      <template v-else-if="detail === 'badges'">
        <ul class="centre-list" data-testid="detail-liste-badges"><li v-for="b in centre.badges_to_return.rows" :key="b.id"><span><strong>{{ b.code }}</strong> · <Link :href="href('/pilotage/visiteurs/' + b.visitor_id)" class="underline">{{ b.full_name }}</Link></span><span>{{ b.closed_at ? 'Participation clôturée' : 'Sortie' }}</span></li></ul>
        <p v-if="!centre.badges_to_return.rows.length" class="pilotage-empty">Aucun badge à récupérer.</p>
        <p class="text-sm">La restitution se fait à l’accueil.</p>
      </template>

      <template v-else-if="detail === 'refus'">
        <ul class="centre-list" data-testid="detail-liste-refus"><li v-for="r in centre.refusals.rows" :key="r.id"><span><Link :href="href('/pilotage/visiteurs/' + r.visitor_id)" class="underline">{{ r.full_name }}</Link> · {{ r.reason }}</span><span :class="r.decision ? '' : 'centre-warn'">{{ r.decision ? 'Décidé' : 'À décider' }} · {{ hm(r.refused_at) }}</span></li></ul>
        <ul class="centre-list" aria-label="Contrôles refusés"><li v-for="i in centre.incidents" :key="i.id"><span>{{ i.zone_ok === 'false' ? 'Zone non autorisée' : 'Contrôle refusé' }}<template v-if="i.full_name"> · {{ i.full_name }}</template><template v-if="i.badge_code"> · {{ i.badge_code }}</template></span><span>{{ hm(i.occurred_at) }}</span></li></ul>
        <p v-if="!centre.refusals.rows.length && !centre.incidents.length" class="pilotage-empty">Aucun refus ni incident.</p>
        <Link :href="href('/pilotage/litiges')" class="pilotage-text-link">Cas à décider</Link>
      </template>

      <template v-else-if="detail === 'cloture' && canClose">
        <ul v-if="!closeTarget" class="centre-list" data-testid="detail-liste-cloture">
          <li v-for="c in centre.closable" :key="c.id">
<span>{{ c.full_name }} · {{ c.category }}<template v-if="c.badge_code"> · badge {{ c.badge_code }}</template></span><span>{{ c.status === 'present' ? 'Présent' : 'Sorti' }}</span>
            <button type="button" class="btn-no !w-auto" :data-testid="'choisir-cloture-' + c.id" @click="closing = c.id">Clôturer…</button>
</li>
        </ul>
        <template v-else>
          <p class="text-lg font-bold" data-testid="cloture-cible">{{ closeTarget.full_name }}</p>
          <SupervisorClosure :visitor-id="closeTarget.id" :offer="{ possible: true, vehicles_present: closeTarget.vehicles_present }" />
          <button type="button" class="btn-soft" @click="closing = null">Choisir une autre personne</button>
        </template>
        <p v-if="!centre.closable.length" class="pilotage-empty" data-testid="cloture-vide">Plus aucune clôture en attente.</p>
      </template>
    </aside>
  </section>
</template>
