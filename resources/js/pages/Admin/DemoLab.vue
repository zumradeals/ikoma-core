<script setup lang="ts">
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';

type DemoEvent = { id: string; name: string; demo_scenario: string; created_at: string };
const props = defineProps<{
  scenarios: Record<string, string>;
  totals: { events: number; visitors: number; badges: number; groups: number; devices: number; demo_events: number };
  demoEvents: DemoEvent[];
}>();

const creator = useForm({ scenario: 'entreprise', quantity: 100 });
const selectedDemo = ref<DemoEvent | null>(null);
const resetOpen = ref(false);
const demoDeletion = useForm({ confirmation: '', password: '' });
const reset = useForm({ confirmation: '', password: '', acknowledge: false, clear_users: false });

function generate() {
  creator.post('/administration/demonstration/generer');
}
function selectForRemoval(event: DemoEvent) {
  selectedDemo.value = event;
  demoDeletion.reset();
}
function deleteDemo() {
  if (!selectedDemo.value) return;
  demoDeletion.post('/administration/demonstration/' + selectedDemo.value.id + '/nettoyer', {
    onSuccess: () => { selectedDemo.value = null; demoDeletion.reset(); },
    onFinish: () => demoDeletion.reset('password'),
  });
}
function resetAll() {
  reset.post('/administration/demonstration/remettre-a-zero', {
    onSuccess: () => { resetOpen.value = false; reset.reset(); },
    onFinish: () => reset.reset('password'),
  });
}
</script>

<template>
  <AdminLayout active="demonstration">
    <div class="max-w-5xl space-y-6">
      <div>
        <h1 class="text-display font-bold">Laboratoire de démonstration</h1>
        <p class="admin-muted mt-2">Générez des personnes et des badges fictifs, faites vos essais, puis effacez-les sans terminal.</p>
      </div>

      <section class="admin-card space-y-5" data-testid="demo-generation">
        <div>
          <h2 class="text-heading font-bold">1. Créer une démonstration</h2>
          <p class="mt-1">Chaque scénario est identifié « DÉMO » et possède ses propres zones, catégories, invités et badges.</p>
        </div>
        <form class="space-y-4" @submit.prevent="generate">
          <label class="block font-semibold">Quel exemple veux-tu tester ?
            <select v-model="creator.scenario" class="field mt-2" data-testid="demo-scenario">
              <option v-for="(name, key) in props.scenarios" :key="key" :value="key">{{ name }}</option>
            </select>
          </label>
          <label class="block font-semibold">Nombre de personnes fictives
            <select v-model.number="creator.quantity" class="field mt-2" data-testid="demo-quantity">
              <option :value="20">20 personnes</option>
              <option :value="100">100 personnes</option>
              <option :value="300">300 personnes</option>
              <option :value="1000">1 000 personnes</option>
            </select>
          </label>
          <p class="admin-muted">Les badges sont créés avec une marge de 10 %. Aucun compte ni accès réel n'est inventé.</p>
          <p v-for="(error, key) in creator.errors" :key="key" class="text-red-800" role="alert">{{ error }}</p>
          <button class="btn-main" type="submit" :disabled="creator.processing" data-testid="demo-create">
            {{ creator.processing ? 'Création en cours…' : 'Générer ma démonstration' }}
          </button>
        </form>
      </section>

      <section class="admin-card space-y-4" data-testid="demo-scenarios">
        <h2 class="text-heading font-bold">2. Mes démonstrations</h2>
        <p v-if="!demoEvents.length">Aucune démonstration enregistrée. Créez votre premier scénario ci-dessus.</p>
        <ul v-else class="space-y-3">
          <li v-for="event in demoEvents" :key="event.id" class="rounded-lg border p-4">
            <p class="font-semibold">{{ event.name }}</p>
            <p class="admin-muted text-sm">Données fictives — vous pouvez supprimer toute cette démonstration après les essais.</p><p class="text-sm">Pour tester sur une tablette : ouvrez Postes, identifiez le poste associé à cet événement, puis générez son code d’enrôlement. Un poste d’un autre événement ne recevra pas cette liste.</p>
            <div class="mt-3 flex flex-wrap gap-2">
              <Link class="btn-soft" href="/administration/evenements">Voir l'événement</Link>
              <Link class="btn-soft" :href="'/administration/invites?event_id=' + event.id">Voir les invités</Link>
              <Link class="btn-soft" href="/administration/postes">Préparer le poste d’accueil</Link>
              <button type="button" class="btn-soft border-red-600 text-red-800" @click="selectForRemoval(event)">Effacer cette démonstration</button>
            </div>
          </li>
        </ul>
        <form v-if="selectedDemo" class="space-y-3 rounded-lg border border-red-600 p-4" @submit.prevent="deleteDemo" data-testid="demo-delete-confirmation">
          <h3 class="font-bold">Effacer : {{ selectedDemo.name }}</h3>
          <p>Cette action supprime définitivement ce scénario et ses données, même si vous l'avez utilisé pour des essais. Les autres événements ne seront pas supprimés.</p>
          <label class="block">Recopiez SUPPRIMER DEMO
            <input v-model="demoDeletion.confirmation" class="field mt-2" required autocomplete="off">
          </label>
          <label class="block">Mot de passe administrateur
            <input v-model="demoDeletion.password" class="field mt-2" type="password" required autocomplete="current-password">
          </label>
          <p v-for="(error, key) in demoDeletion.errors" :key="key" class="text-red-800" role="alert">{{ error }}</p>
          <div class="flex flex-wrap gap-3">
            <button class="btn-soft border-red-700 text-red-800" :disabled="demoDeletion.processing" type="submit">Supprimer définitivement ce scénario</button>
            <button type="button" class="btn-soft" @click="selectedDemo = null">Annuler</button>
          </div>
        </form>
      </section>

      <section class="admin-card space-y-4" data-testid="business-reset">
        <div>
          <h2 class="text-heading font-bold">3. Remettre IKOMA ACCESS à zéro</h2>
          <p class="mt-2">Vous voulez repartir de zéro pour vos tests ? Cette fonction efface <strong>toutes les données métier</strong> de cette installation, y compris celles qui ne portent pas l'étiquette DÉMO.</p>
        </div>
        <dl class="grid grid-cols-2 gap-3 md:grid-cols-3">
          <div class="rounded-lg bg-slate-50 p-3"><dt>Événements</dt><dd class="text-2xl font-bold">{{ totals.events }}</dd></div>
          <div class="rounded-lg bg-slate-50 p-3"><dt>Personnes</dt><dd class="text-2xl font-bold">{{ totals.visitors }}</dd></div>
          <div class="rounded-lg bg-slate-50 p-3"><dt>Badges</dt><dd class="text-2xl font-bold">{{ totals.badges }}</dd></div>
          <div class="rounded-lg bg-slate-50 p-3"><dt>Groupes</dt><dd class="text-2xl font-bold">{{ totals.groups }}</dd></div>
          <div class="rounded-lg bg-slate-50 p-3"><dt>Postes et tablettes</dt><dd class="text-2xl font-bold">{{ totals.devices }}</dd></div>
          <div class="rounded-lg bg-slate-50 p-3"><dt>Scénarios DÉMO</dt><dd class="text-2xl font-bold">{{ totals.demo_events }}</dd></div>
        </dl>
        <p class="admin-muted">Ton compte administrateur, son mot de passe, la configuration technique, les migrations et la trace de sécurité du reset sont conservés. Les tablettes ayant des copies hors ligne doivent aussi être vidées ou réenrôlées avant de les réutiliser.</p>
        <button v-if="!resetOpen" type="button" class="btn-soft border-red-600 text-red-800" @click="resetOpen = true" data-testid="business-reset-open">
          Préparer la remise à zéro totale
        </button>
        <form v-else class="space-y-4 rounded-lg border border-red-600 p-4" @submit.prevent="resetAll" data-testid="business-reset-confirmation">
          <h3 class="font-bold text-red-800">Attention : cette opération est irréversible</h3>
          <p>Elle supprimera {{ totals.events }} événement(s), {{ totals.visitors }} personne(s) et {{ totals.badges }} badge(s), y compris vos éléments « seminaire » s'ils existent encore.</p>
          <label class="flex items-start gap-2">
            <input v-model="reset.acknowledge" type="checkbox" required class="mt-1">
            <span>Je confirme qu'il s'agit d'une installation de test et que je veux supprimer toutes les données métier, même non fictives.</span>
          </label>
          <label class="flex items-start gap-2"><input v-model="reset.clear_users" type="checkbox" class="mt-1"><span>Supprimer aussi les autres comptes (agents, superviseurs et autres administrateurs). Seul mon compte administrateur actuel restera actif.</span></label>
          <label class="block">Recopiez exactement REMETTRE A ZERO IKOMA
            <input v-model="reset.confirmation" class="field mt-2" required autocomplete="off">
          </label>
          <label class="block">Mot de passe administrateur
            <input v-model="reset.password" class="field mt-2" type="password" required autocomplete="current-password">
          </label>
          <p v-for="(error, key) in reset.errors" :key="key" class="text-red-800" role="alert">{{ error }}</p>
          <div class="flex flex-wrap gap-3">
            <button class="btn-soft border-red-700 text-red-800" type="submit" :disabled="reset.processing">Confirmer : tout remettre à zéro</button>
            <button type="button" class="btn-soft" @click="resetOpen = false">Annuler</button>
          </div>
        </form>
      </section>

      <p class="admin-muted text-sm">Ce laboratoire n'est disponible que sur une installation identifiée comme non productive. Pour les suppressions ordinaires, utilisez les actions des fiches ou <Link href="/administration/donnees" class="underline">Gestion des données</Link>.</p>
    </div>
  </AdminLayout>
</template>
