<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import '../../../../public/badges/holder-cards.css';

type Print = { id: string; kind: 'emission' | 'reimpression'; reason: string | null; created_at: string; by: string | null };
type CardRow = { id: string; rank: number; status: 'active' | 'perdue' | 'volee' | 'revoquee' | 'retiree'; issued_at: string; revoked_at: string | null; revocation_reason: string | null; revocation_note: string | null; replaced_by_card_id: string | null; badge_code: string; badge_status: string; revoked_by: string | null; prints: Print[] };
type Card = { id: string; issued_at: string; badge_code: string; badge_status: string; associated: boolean; prints: Print[] };
const props = defineProps<{
  holder: { id: string; identifier: string; full_name: string; status: 'actif' | 'clos'; institutional_category: string | null; has_photo: boolean; photo_print_allowed: boolean };
  category: { code: string; label: string } | null; card: Card | null; preview: { recto: string; verso: string } | null;
  permanentContext: boolean; provisional: boolean; csrf: string;
  cards: CardRow[]; reasons: Record<string, string>; entitlements: { id: string; event: string; zone: string; valid_from: string; valid_until: string; revoked_at: string | null }[];
}>();

const base = computed(() => '/administration/titulaires/' + props.holder.id);
const portrait = useForm<{ photo: File | null; print_allowed: boolean }>({ photo: null, print_allowed: props.holder.photo_print_allowed });
function pick(event: Event) { portrait.photo = (event.target as HTMLInputElement).files?.[0] ?? null; }
function sendPortrait() { portrait.post(base.value + '/portrait', { preserveScroll: true, forceFormData: true, onSuccess: () => portrait.reset('photo') }); }
function removePortrait() { router.delete(base.value + '/portrait', { preserveScroll: true }); }
function prepare() { router.post(base.value + '/carte', {}, { preserveScroll: true }); }
const reprint = useForm({ reason: '' });
const reprinting = computed(() => (props.card?.prints.length ?? 0) > 0);
// Le tirage s'ouvre dans un nouvel onglet : à notre retour, l'historique est relu.
function refresh() { router.reload({ only: ['card'] }); }
onMounted(() => window.addEventListener('focus', refresh));
onBeforeUnmount(() => window.removeEventListener('focus', refresh));
const lifecycle = ref<'revoquer' | 'remplacer' | null>(null);
const lifecycleForm = useForm({ reason: 'perdue', note: '', confirmation: false });
function openLifecycle(kind: 'revoquer' | 'remplacer') { lifecycle.value = kind; lifecycleForm.reset(); lifecycleForm.clearErrors(); }
function submitLifecycle() { lifecycleForm.post(base.value + '/carte/' + lifecycle.value, { preserveScroll: true, onSuccess: () => { lifecycle.value = null; } }); }
const statusLabel: Record<string, string> = { active: 'Active', perdue: 'Perdue', volee: 'Volée', revoquee: 'Révoquée', retiree: 'Retirée' };
const steps = ['Sélectionner un titulaire', 'Préparer la carte', 'Prévisualiser', 'Générer / imprimer'];
const stage = computed(() => (props.card === null ? 1 : props.card.prints.length === 0 ? 2 : 3));
function when(value: string) { return new Date(value).toLocaleString('fr-FR'); }
</script>

<template>
  <AdminLayout active="titulaires">
    <div class="space-y-6" data-testid="page-carte-titulaire">
      <header class="space-y-3">
        <Link href="/administration/titulaires" class="font-semibold underline" data-testid="retour-titulaires">← Titulaires permanents</Link>
        <h1 class="text-display font-bold">Carte de {{ holder.full_name }}</h1>
        <p class="font-mono text-lg font-bold" data-testid="identifiant-carte">{{ holder.identifier }}</p>
        <ol class="flex flex-wrap gap-2" aria-label="Étapes">
          <li v-for="(label, i) in steps" :key="label" class="rounded-lg border px-3 py-1 text-sm font-semibold" :class="i <= stage ? 'bg-white' : 'opacity-60'" :aria-current="i === stage ? 'step' : undefined">{{ i + 1 }}. {{ label }}</li>
        </ol>
        <p class="rounded-lg border bg-white p-3 text-sm" data-testid="avis-acces">Le titulaire, la carte physique et ses identifiants techniques sont trois choses distinctes. <strong>Posséder une carte ne donne aucun droit d’accès</strong> : les accès dépendent uniquement des règles de chaque événement.</p>
        <p v-if="provisional" class="rounded-lg border-2 border-amber-600 bg-amber-50 p-3 text-sm font-semibold" role="status" data-testid="avis-provisoire">Modèle provisoire : couleurs, repères et mentions ne sont pas encore validés par l’institution. Les cartes portent la mention « MODÈLE PROVISOIRE ».</p>
      </header>

      <p v-if="holder.status === 'clos'" class="rounded-xl border bg-white p-5 font-semibold" data-testid="titulaire-clos">Ce titulaire est clos : aucune carte ne peut être émise ou imprimée.</p>

      <template v-else>
        <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Préparation">
          <h2 class="text-heading font-bold">Préparation</h2>
          <ul class="list-disc space-y-1 pl-6" data-testid="preparation">
            <li :data-ok="category !== null">Catégorie institutionnelle : <strong>{{ category ? category.code + ' · ' + category.label : 'à choisir dans la fiche du titulaire' }}</strong></li>
            <li :data-ok="permanentContext">Contexte permanent : <strong>{{ permanentContext ? 'créé' : 'à créer depuis l’écran Titulaires' }}</strong></li>
            <li>Portrait : <strong>{{ holder.has_photo ? (holder.photo_print_allowed ? 'présent, impression autorisée' : 'présent, non imprimé') : 'aucun (facultatif)' }}</strong></li>
          </ul>
          <form class="space-y-3 border-t pt-3" @submit.prevent="sendPortrait">
            <h3 class="font-bold">Portrait (facultatif)</h3>
            <p class="text-sm">Image JPEG ou PNG, 5 Mo au plus, recadrée en 3:4. Elle est stockée chiffrée et n’est imprimée que si vous l’autorisez ici.</p>
            <label class="block font-semibold">Fichier<input type="file" accept="image/png,image/jpeg" class="block" data-testid="fichier-portrait" @change="pick"></label>
            <label class="flex items-center gap-2 font-semibold"><input v-model="portrait.print_allowed" type="checkbox" class="size-6" data-testid="autoriser-impression-portrait"> Autoriser l’impression du portrait sur la carte</label>
            <p v-if="portrait.errors.photo" class="font-semibold text-red-800" role="alert">{{ portrait.errors.photo }}</p>
            <div class="flex flex-wrap gap-3"><button type="submit" class="btn-soft" :disabled="!portrait.photo || portrait.processing" data-testid="envoyer-portrait">Enregistrer le portrait</button><button v-if="holder.has_photo" type="button" class="btn-soft" data-testid="retirer-portrait" @click="removePortrait">Retirer le portrait</button></div>
          </form>
        </section>

        <section v-if="card === null && cards.length === 0" class="space-y-3 rounded-xl border bg-white p-5" aria-label="Création de la carte">
          <h2 class="text-heading font-bold">Préparer la carte</h2>
          <p>Crée la carte de ce titulaire : un badge technique est fabriqué, non associé à aucun événement. L’identifiant <strong>{{ holder.identifier }}</strong> n’est pas modifié.</p>
          <p v-if="$page.props.errors?.holder" class="font-semibold text-red-800" role="alert" data-testid="erreur-carte">{{ $page.props.errors.holder }}</p>
          <button type="button" class="admin-primary" data-testid="preparer-carte" :disabled="category === null || !permanentContext" @click="prepare">Préparer la carte</button>
        </section>

        <section v-else-if="card === null && cards.length > 0" class="space-y-3 rounded-xl border-2 border-red-700 bg-white p-5" aria-label="Aucune carte active" data-testid="aucune-carte-active">
          <h2 class="text-heading font-bold">Aucune carte active</h2>
          <p>La dernière carte de ce titulaire est refusée partout. Remplacez-la pour émettre une nouvelle carte : le titulaire, son identifiant imprimé et ses habilitations ne changent pas.</p>
          <button type="button" class="admin-primary" data-testid="remplacer-carte" @click="openLifecycle('remplacer')">Remplacer la carte</button>
        </section>

        <template v-else-if="card">
          <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Aperçu">
            <h2 class="text-heading font-bold">Aperçu recto et verso</h2>
            <p class="text-sm">Taille réelle CR80 (53,98 × 85,60 mm). Badge technique <strong data-testid="code-badge">{{ card.badge_code }}</strong> · statut <strong>{{ card.badge_status }}</strong> · {{ card.associated ? 'associé à un événement' : 'non associé : aucun accès' }}.</p>
            <div v-if="preview" class="flex flex-wrap gap-8" data-testid="apercu-carte">
              <!-- eslint-disable vue/no-v-html -->
              <figure class="space-y-2"><figcaption class="font-semibold">Recto</figcaption><div class="hc-preview" data-testid="apercu-recto" v-html="preview.recto"></div></figure>
              <figure class="space-y-2"><figcaption class="font-semibold">Verso</figcaption><div class="hc-preview" data-testid="apercu-verso" v-html="preview.verso"></div></figure>
              <!-- eslint-enable vue/no-v-html -->
            </div>
          </section>

          <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Impression">
            <h2 class="text-heading font-bold">{{ reprinting ? 'Réimprimer la carte' : 'Générer et imprimer la carte' }}</h2>
            <p v-if="reprinting">Réimprimer conserve le même badge, le même QR et le même identifiant. Un motif est obligatoire et reste dans l’historique.</p>
            <p v-else>Le premier tirage est l’émission de la carte. Faites d’abord un essai sur la <a href="/administration/titulaires/alignement" target="_blank" rel="noopener" class="font-semibold underline" data-testid="lien-alignement">page d’alignement</a>.</p>
            <form method="post" :action="base + '/carte/imprimer'" target="_blank" class="space-y-3" data-testid="formulaire-impression">
              <input type="hidden" name="_token" :value="csrf">
              <label v-if="reprinting" class="block font-semibold">Motif de la réimpression<input v-model="reprint.reason" name="reason" class="field mt-1" required maxlength="200" data-testid="motif-reimpression"></label>
              <button type="submit" class="admin-primary" data-testid="generer-impression">{{ reprinting ? 'Générer la réimpression' : 'Émettre et imprimer' }}</button>
            </form>
          </section>

          <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Historique">
            <h2 class="text-heading font-bold">Historique des tirages</h2>
            <p v-if="card.prints.length === 0" data-testid="aucun-tirage">Aucun tirage pour le moment. Carte préparée le {{ when(card.issued_at) }}.</p>
            <ol v-else class="space-y-2" data-testid="historique-tirages">
              <li v-for="p in card.prints" :key="p.id" class="rounded-lg border p-3"><strong>{{ p.kind === 'emission' ? 'Émission' : 'Réimpression' }}</strong> · {{ when(p.created_at) }}<span v-if="p.by"> · {{ p.by }}</span><span v-if="p.reason" class="block text-sm">Motif : {{ p.reason }}</span></li>
            </ol>
            <p class="text-sm">Le navigateur ne confirme pas le tirage physique : l’historique enregistre la génération du document.</p>
          </section>
        </template>
      </template>

      <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Cycle de vie des cartes" data-testid="cycle-vie">
        <h2 class="text-heading font-bold">Cartes du titulaire</h2>
        <p class="text-sm">Titulaire, carte physique, identifiant imprimé, jeton QR et habilitation sont cinq choses distinctes. Une carte perdue, volée ou révoquée est refusée tout de suite et ne se réactive jamais.</p>
        <ol class="space-y-2" data-testid="liste-cartes">
          <li v-for="c in cards" :key="c.id" class="rounded-lg border p-3" :data-testid="'carte-' + c.rank">
            <p class="flex flex-wrap items-center gap-2"><strong>Carte n° {{ c.rank }}</strong> · badge {{ c.badge_code }}
              <span class="rounded px-2 py-1 text-sm font-bold" :class="c.status === 'active' ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-900'" :data-testid="'statut-carte-' + c.rank">{{ statusLabel[c.status] ?? c.status }}</span></p>
            <p class="text-sm">Émise le {{ when(c.issued_at) }}<span v-if="c.revoked_at"> · {{ statusLabel[c.status] }} le {{ when(c.revoked_at) }}<span v-if="c.revoked_by"> par {{ c.revoked_by }}</span><span v-if="c.revocation_note"> : {{ c.revocation_note }}</span></span><span v-if="c.replaced_by_card_id"> · remplacée</span></p>
            <p v-if="c.prints.length" class="text-sm">{{ c.prints.length }} tirage(s) : {{ c.prints.map(p => (p.kind === 'emission' ? 'émission' : 'réimpression') + ' ' + when(p.created_at)).join(' ; ') }}</p>
          </li>
        </ol>
        <p v-if="cards.length === 0">Aucune carte émise.</p>
        <div v-if="holder.status === 'actif' && card" class="flex flex-wrap gap-3">
          <button type="button" class="btn-soft" data-testid="declarer-perte" @click="openLifecycle('revoquer'); lifecycleForm.reason = 'perdue'">Déclarer perdue ou volée / révoquer</button>
          <button type="button" class="btn-soft" data-testid="remplacer-carte-active" @click="openLifecycle('remplacer'); lifecycleForm.reason = 'perdue'">Remplacer la carte</button>
        </div>
        <form v-if="lifecycle" class="space-y-3 rounded-lg border-2 border-red-700 p-4" data-testid="formulaire-cycle" @submit.prevent="submitLifecycle">
          <p class="font-semibold">{{ lifecycle === 'remplacer' ? 'Remplacement : l’ancienne carte est révoquée définitivement, une nouvelle carte est préparée.' : 'Révocation : la carte est refusée partout, immédiatement et définitivement.' }}</p>
          <label class="block font-semibold">Motif
            <select v-model="lifecycleForm.reason" class="field mt-1" data-testid="cycle-motif"><option v-for="(label, key) in reasons" :key="key" :value="key">{{ label }}</option></select>
          </label>
          <label class="block font-semibold">Précision (facultative, obligatoire pour « Autre motif »)<input v-model="lifecycleForm.note" class="field mt-1" maxlength="200" data-testid="cycle-note"></label>
          <label class="flex items-center gap-2 font-semibold"><input v-model="lifecycleForm.confirmation" type="checkbox" class="size-6" data-testid="cycle-confirmation"> Je confirme : cette décision est irréversible</label>
          <p v-for="(message, key) in lifecycleForm.errors" :key="key" class="font-semibold text-red-800" role="alert">{{ message }}</p>
          <div class="flex gap-3"><button type="submit" class="admin-primary" :disabled="lifecycleForm.processing || !lifecycleForm.confirmation" data-testid="cycle-valider">{{ lifecycle === 'remplacer' ? 'Révoquer et remplacer' : 'Révoquer la carte' }}</button><button type="button" class="btn-soft" @click="lifecycle = null">Annuler</button></div>
        </form>
      </section>

      <section class="space-y-3 rounded-xl border bg-white p-5" aria-label="Habilitations du titulaire">
        <h2 class="text-heading font-bold">Habilitations</h2>
        <p class="text-sm">Seules les habilitations ci-dessous ouvrent des zones. <Link href="/administration/habilitations" class="font-semibold underline" data-testid="lien-habilitations">Gérer les habilitations</Link></p>
        <p v-if="entitlements.length === 0" data-testid="aucune-habilitation-titulaire">Aucune habilitation : ce titulaire n’entre nulle part.</p>
        <ul class="space-y-1"><li v-for="e in entitlements" :key="e.id">{{ e.event }} · {{ e.zone }} · {{ when(e.valid_from) }} → {{ when(e.valid_until) }}<span v-if="e.revoked_at"> (retirée)</span></li></ul>
      </section>
    </div>
  </AdminLayout>
</template>
