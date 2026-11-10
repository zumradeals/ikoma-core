<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BadgesCartesShell from '../../components/admin/BadgesCartesShell.vue';
import BadgePreparerPanel from '../../components/admin/BadgePreparerPanel.vue';
import SupportRegistryPanel from '../../components/admin/SupportRegistryPanel.vue';
import SupportPreview from '../../components/admin/SupportPreview.vue';
import { request, submitPrint, type Person, type Registre, type SupportRow, type SupportView } from '../../components/admin/badge-api';

/**
 * Espace de travail « Badges et cartes » : registre commun (gauche), préparation d'un badge (centre) et aperçu (droite).
 * C'est l'écran d'arrivée de l'espace : aucun moteur n'est ajouté ici, les actions passent par les contrats d'émission (BadgeIssuance) et d'impression (SupportPrinting).
 * Les lots et réservations historiques restent consultables à part, hors du parcours normal.
 */
const props = defineProps<{
  registre: Registre; filters: { q: string; type: string; etat: string }; states: Record<string, string>;
  canPrepare: boolean; events: { id: string; name: string; kind: string; starts_at: string; ends_at: string }[]; max: number; preset?: { event: string; ids: string[] } | null;
}>();

// Petits écrans : un seul volet à la fois (les trois sont visibles côte à côte sur grand écran).
const panel = ref<'registre' | 'preparer' | 'apercu'>(props.preset ? 'preparer' : 'registre');
const registry = ref<InstanceType<typeof SupportRegistryPanel> | null>(null);
const selected = ref<SupportRow | null>(null);
const view = ref<SupportView | null>(null);
const person = ref<Person | null>(null);
const loading = ref(false);
const viewError = ref<string | null>(null);
const reprint = ref<{ kind: string; refusal: string | null; printable: boolean } | null>(null);
const reason = ref('');

const indicative = computed(() => (person.value && !view.value ? { name: person.value.name, subtitle: [person.value.organisation, person.value.category].filter(Boolean).join(' · ') || null, model: person.value.model } : null));

async function showSupport(badgeId: string) {
  loading.value = true; viewError.value = null; view.value = null; reprint.value = null; reason.value = '';
  const { data, errors } = await request<{ supports: SupportView[] }>('/administration/badges/impression/donnees', { badges: [badgeId] });
  loading.value = false;
  if (data?.supports[0]) view.value = data.supports[0]; else viewError.value = errors[0] ?? 'Aperçu indisponible pour ce support.';
  panel.value = 'apercu';
}
async function select(row: SupportRow) {
  selected.value = row; person.value = null;
  if (props.canPrepare) await showSupport(row.id); else { view.value = null; viewError.value = null; panel.value = 'apercu'; }
}
function onPerson(p: Person | null) { person.value = p; if (p) { view.value = null; selected.value = null; viewError.value = null; } }
async function onGenerated(ids: string[]) {
  router.reload({ only: ['badgesCartes'] });
  await registry.value?.load(1);
  if (ids[0]) { person.value = null; selected.value = null; await showSupport(ids[0]); panel.value = 'preparer'; }
}
async function focusGenerated(badgeId: string) { person.value = null; await showSupport(badgeId); }
async function planPrint() {
  if (!selected.value) return;
  const { data, errors } = await request<{ supports: { printable: boolean; kind: string; refusal: string | null }[] }>('/administration/badges/impression/recapitulatif', { badges: [selected.value.id] });
  const s = data?.supports[0];
  reprint.value = s ?? { printable: false, kind: 'emission', refusal: errors[0] ?? 'Impossible de vérifier ce support.' };
}
function printSelected() {
  if (!selected.value || !reprint.value?.printable) return;
  if (reprint.value.kind === 'reimpression' && reason.value.trim() === '') return;
  submitPrint([selected.value.id], reason.value.trim() || null);
}
const panels = [{ key: 'registre', label: 'Registre' }, { key: 'preparer', label: 'Préparer' }, { key: 'apercu', label: 'Aperçu' }] as const;
const hide = (key: string) => (panel.value === key ? '' : 'hidden min-[1180px]:block');
</script>

<template>
  <AdminLayout active="badges-cartes">
    <BadgesCartesShell active="registre" titre />
    <div class="mb-3 flex gap-2 min-[1180px]:hidden" role="tablist" aria-label="Volets de l’espace de travail" data-testid="volets">
      <button v-for="p in panels" :key="p.key" type="button" role="tab" class="site-btn grow border" :class="panel === p.key ? 'border-blue-600 bg-blue-50 text-blue-900' : 'border-slate-300 bg-white'" :aria-selected="panel === p.key" :data-testid="'volet-' + p.key" @click="panel = p.key">{{ p.label }}</button>
    </div>

    <div class="grid items-start gap-4 min-[1180px]:grid-cols-[minmax(270px,1fr)_minmax(330px,1.15fr)_minmax(260px,.9fr)]" data-testid="espace-travail">
      <div class="min-w-0" :class="hide('registre')">
        <SupportRegistryPanel ref="registry" :initial="registre" :filters="filters" :states="states" :selected-id="selected?.id ?? null" @select="select">
          <template #footer>
            <Link href="/administration/badges/lots" class="underline" data-testid="lien-lots">Lots et réservations (stock existant)</Link> ·
            <Link href="/administration/badges/registre" class="underline" data-testid="lien-mise-a-disposition">Mise à disposition entre activités</Link>
          </template>
        </SupportRegistryPanel>
      </div>

      <div class="min-w-0" :class="hide('preparer')">
        <BadgePreparerPanel v-if="canPrepare" :events="events" :max="max" :preset="preset" @person="onPerson" @supports="onGenerated" @focus="focusGenerated" />
        <section v-else class="rounded-xl border border-slate-200 bg-white p-4" data-testid="preparer-indisponible">
          <h2 class="mb-2 text-heading font-bold">Préparer un badge</h2>
          <p>La préparation de badges demande aussi la permission d’importer des listes. Le registre reste consultable.</p>
        </section>
      </div>

      <div class="min-w-0" :class="hide('apercu')">
        <section class="rounded-xl border border-slate-200 bg-white p-3" aria-labelledby="apercu-titre" data-testid="colonne-apercu">
          <h2 id="apercu-titre" class="mb-2 flex items-center gap-2 text-heading font-bold">Aperçu du badge</h2>
          <SupportPreview :indicative="indicative" :view="view" :loading="loading" :error="viewError" />
          <div v-if="selected" class="mt-3 space-y-2 rounded-xl border bg-slate-50 p-3 text-sm" data-testid="support-selectionne">
            <p><strong>{{ selected.code }}</strong> · {{ selected.state_label }} · {{ selected.type === 'carte_permanente' ? 'carte permanente' : 'badge temporaire' }}<br>{{ selected.holder_name ?? 'Sans titulaire nominatif' }} · {{ selected.event_name }}</p>
            <div class="flex flex-wrap gap-2">
              <Link :href="`/administration/badges/${selected.id}`" class="site-btn site-btn-outline !min-h-10" data-testid="consulter-support">Consulter la fiche</Link>
              <button v-if="canPrepare" type="button" class="site-btn site-btn-outline !min-h-10" data-testid="verifier-impression" @click="planPrint">Imprimer ou réimprimer</button>
            </div>
            <template v-if="reprint">
              <p v-if="!reprint.printable" role="alert" class="text-amber-900" data-testid="impression-refusee">{{ reprint.refusal }}</p>
              <template v-else>
                <p data-testid="impression-genre">{{ reprint.kind === 'reimpression' ? 'Réimpression : le numéro et le QR du support sont conservés ; un motif est obligatoire.' : 'Première impression de ce support.' }}</p>
                <label v-if="reprint.kind === 'reimpression'" class="block">Motif de la réimpression<input v-model="reason" class="field" maxlength="200" data-testid="motif-reimpression"></label>
                <button type="button" class="site-btn site-btn-accent" :disabled="reprint.kind === 'reimpression' && reason.trim() === ''" data-testid="lancer-impression" @click="printSelected">{{ reprint.kind === 'reimpression' ? 'Réimprimer' : 'Imprimer' }}</button>
              </template>
            </template>
          </div>
        </section>
      </div>
    </div>
    <p class="mt-4 max-w-4xl rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm" data-testid="rappel-accueil">L’attribution d’un badge à une personne et sa restitution se font à l’accueil, par l’agent : la restitution est un acte distinct de la sortie physique. Générer ou imprimer un support ne le remet pas, ne l’active pas et ne donne aucune habilitation.</p>
  </AdminLayout>
</template>
