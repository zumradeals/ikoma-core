<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../layouts/AdminLayout.vue';
import BaseCard from '../../components/BaseCard.vue';

/**
 * Parcours de l'atelier : deux domaines distincts, jamais mélangés. Un badge visiteur est réutilisable et appartient à une activité ;
 * une carte permanente est liée à un titulaire. Chaque entrée mène à un écran existant ; les étapes qui se font à l'accueil sont indiquées sans bouton.
 */
const props = defineProps<{
  visitors: { batches: number; badges: Record<'Disponible' | 'Associé' | 'Restitué' | 'Annulé' | 'Clos', number>; print_runs: number; to_return: number };
  cards: { holders: number; active: number; inactive: number; prints: number };
}>();
const page = usePage<{ auth?: { permissions: string[] } | null }>();
const can = (permission: string) => page.props.auth?.permissions?.includes(permission) ?? false;
type Step = { key: string; label: string; text: string; href?: string; allowed?: boolean };
const visitorSteps = computed<Step[]>(() => [
  { key: 'preparer', label: 'Préparer et imprimer', text: `Modèles, préparation et tirages de badges. ${props.visitors.print_runs} tirage(s) enregistré(s).`, href: '/administration/atelier', allowed: can('badges.gerer') },
  { key: 'stock', label: 'Lots, réservations et stock', text: `${props.visitors.batches} lot(s) · ${props.visitors.badges.Disponible} badge(s) disponible(s).`, href: '/administration/badges/lots', allowed: can('badges.gerer') },
  { key: 'attribuer', label: 'Attribuer à une personne', text: `À l’accueil, par l’agent, au poste d’accueil. ${props.visitors.badges.Associé} badge(s) attribué(s) en ce moment.` },
  { key: 'restituer', label: 'Restituer', text: `À l’accueil, par l’agent (« Restituer un badge »), acte distinct de la sortie. ${props.visitors.to_return} badge(s) encore attribué(s) à une personne sortie ou clôturée.` },
  { key: 'historique', label: 'Historique d’un badge', text: 'Attributions, restitutions, transferts et annulations d’un badge.', href: '/administration/badges/registre', allowed: can('badges.gerer') },
]);
const cardSteps = computed<Step[]>(() => [
  { key: 'titulaires', label: 'Titulaires, émission, remplacement et révocation', text: `${props.cards.holders} titulaire(s) actif(s) · ${props.cards.active} carte(s) active(s) · ${props.cards.inactive} carte(s) retirée(s), perdue(s), volée(s) ou révoquée(s).`, href: '/administration/titulaires', allowed: can('listes.importer') },
  { key: 'modeles', label: 'Modèles de cartes', text: 'Personnalisation visuelle des cartes permanentes.', href: '/administration/titulaires/modeles', allowed: can('badges.gerer') },
  { key: 'habilitations', label: 'Habilitations des titulaires', text: 'Zones et horaires autorisés aux titulaires.', href: '/administration/habilitations', allowed: can('listes.importer') },
  { key: 'imprimer', label: 'Imprimer les cartes', text: `Planche de cartes à imprimer. ${props.cards.prints} impression(s) enregistrée(s).`, href: '/administration/atelier?mode=cartes', allowed: can('badges.gerer') && can('listes.importer') },
  { key: 'historique-carte', label: 'Historique d’une carte', text: 'Visible dans la fiche de chaque titulaire (émission, réimpressions, retrait).' },
]);
</script>

<template>
  <AdminLayout active="atelier-parcours">
    <h1 class="mb-2 text-display font-bold">Parcours badges et cartes</h1>
    <p class="mb-6 max-w-3xl text-slate-700">Deux domaines distincts : un <strong>badge visiteur</strong> est réutilisable et appartient à une activité ; une <strong>carte permanente</strong> est liée à un titulaire. Aucun registre commun : chaque domaine garde ses règles.</p>
    <div class="grid gap-6 lg:grid-cols-2">
      <BaseCard data-testid="domaine-visiteurs">
        <h2 class="mb-1 text-heading font-bold">Badges visiteurs</h2>
        <p class="mb-4 text-sm text-slate-600" data-testid="stats-visiteurs">{{ visitors.badges.Disponible }} disponible(s) · {{ visitors.badges.Associé }} attribué(s) · {{ visitors.badges.Restitué + visitors.badges.Clos }} restitué(s) ou clos · {{ visitors.badges.Annulé }} annulé(s)</p>
        <ol class="space-y-3">
          <li v-for="(s, i) in visitorSteps" :key="s.key" class="rounded-xl border border-slate-200 p-3" :data-testid="'etape-visiteur-' + s.key">
            <p class="font-bold">{{ i + 1 }}. {{ s.label }}</p><p class="text-sm text-slate-700">{{ s.text }}</p>
            <Link v-if="s.href && s.allowed" :href="s.href" class="action mt-2 inline-flex" :data-testid="'lien-visiteur-' + s.key">Ouvrir</Link>
          </li>
        </ol>
      </BaseCard>
      <BaseCard data-testid="domaine-cartes">
        <h2 class="mb-1 text-heading font-bold">Cartes permanentes</h2>
        <p class="mb-4 text-sm text-slate-600" data-testid="stats-cartes">{{ cards.holders }} titulaire(s) · {{ cards.active }} carte(s) active(s)</p>
        <ol class="space-y-3">
          <li v-for="(s, i) in cardSteps" :key="s.key" class="rounded-xl border border-slate-200 p-3" :data-testid="'etape-carte-' + s.key">
            <p class="font-bold">{{ i + 1 }}. {{ s.label }}</p><p class="text-sm text-slate-700">{{ s.text }}</p>
            <Link v-if="s.href && s.allowed" :href="s.href" class="action mt-2 inline-flex" :data-testid="'lien-carte-' + s.key">Ouvrir</Link>
          </li>
        </ol>
      </BaseCard>
    </div>
  </AdminLayout>
</template>
