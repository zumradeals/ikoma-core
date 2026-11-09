<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AdminIcon from './AdminIcon.vue';
import { svgSource, type Model, type SupportView } from './badge-api';

/**
 * Colonne « Aperçu » : le rendu d'un support. Deux états, jamais confondus :
 *  - INDICATIF (avant génération) : modèle institutionnel de la personne, sans numéro ni QR ; aucun support n'est créé pour l'afficher ;
 *  - RÉEL (support émis) : données composées par le serveur avec le même composeur que l'impression, numéro et QR réels.
 */
const props = defineProps<{ indicative: { name: string; subtitle: string | null; model: Model | null } | null; view: SupportView | null; loading?: boolean; error?: string | null }>();
const page = usePage<{ institution?: { name: string; fullName: string } | null }>();
const institution = computed(() => props.view?.institution ?? page.props.institution ?? { name: '', fullName: '' });
const color = computed(() => props.view?.tone.color ?? props.indicative?.model?.color ?? '#64748b');
const ink = computed(() => props.view?.tone.ink ?? '#ffffff');
const mention = computed(() => props.view?.mention ?? props.indicative?.model?.mention ?? 'MODÈLE NEUTRE');
const name = computed(() => props.view?.name ?? props.indicative?.name ?? '');
const sub = computed(() => props.view?.role || props.view?.label || props.indicative?.subtitle || '');
const initials = computed(() => name.value.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]?.toUpperCase()).join(''));
const mode = computed<'reel' | 'indicatif' | 'vide'>(() => (props.view ? 'reel' : props.indicative ? 'indicatif' : 'vide'));
</script>

<template>
  <div class="space-y-3" data-testid="apercu">
    <p v-if="error" role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900" data-testid="apercu-erreur">{{ error }}</p>
    <p v-if="loading" role="status" class="text-sm text-slate-600">Chargement de l’aperçu…</p>
    <div v-if="mode === 'vide'" class="rounded-xl border-2 border-dashed border-slate-300 p-6 text-center text-slate-600" data-testid="apercu-vide">
      <AdminIcon name="qr" :size="40" />
      <p class="mt-2 font-semibold">Aucun support sélectionné</p>
      <p class="text-sm">Choisissez un support dans le registre, ou une personne à préparer : son rendu s’affiche ici.</p>
    </div>
    <template v-else>
      <p class="inline-block rounded-full px-3 py-1 text-xs font-bold" :class="mode === 'reel' ? 'bg-green-100 text-green-900' : 'bg-amber-100 text-amber-900'" :data-testid="'apercu-' + mode">{{ mode === 'reel' ? 'Support émis : numéro et QR réels' : 'Aperçu indicatif : rien n’est encore généré' }}</p>
      <article class="mx-auto w-full max-w-[17rem] overflow-hidden rounded-2xl border-2 border-slate-300 bg-white shadow-md" :aria-label="'Aperçu du support de ' + name">
        <header class="px-3 py-2 text-center" style="background:#f1f5f9">
          <img v-if="view?.institution.logo" :src="view.institution.logo" alt="" class="mx-auto h-8">
          <strong class="block text-sm">{{ institution.name }}</strong><span class="block text-[11px] leading-tight text-slate-600">{{ institution.fullName }}</span>
        </header>
        <div class="px-3 py-1.5 text-center text-sm font-extrabold tracking-wide" :style="{ background: color, color: ink }" data-testid="apercu-mention">{{ mention }}</div>
        <div class="flex items-center gap-3 p-3">
          <img v-if="view?.portrait" :src="view.portrait" alt="Photo de la personne" class="size-20 rounded-lg object-cover" data-testid="apercu-photo">
          <span v-else class="grid size-20 shrink-0 place-items-center rounded-lg bg-slate-200 text-xl font-bold text-slate-600" aria-hidden="true">{{ initials }}</span>
          <div class="min-w-0"><strong class="block break-words text-lg leading-tight" data-testid="apercu-nom">{{ name }}</strong><span class="block break-words text-sm text-slate-600">{{ sub }}</span><span v-if="view" class="block text-sm font-semibold" data-testid="apercu-numero">{{ view.badge_code }}</span></div>
        </div>
        <div class="flex justify-center px-3 pb-3">
          <img v-if="view" :src="svgSource(view.qr)" alt="QR du support" class="size-24 rounded border" data-testid="apercu-qr">
          <div v-else class="grid size-24 place-items-center rounded border-2 border-dashed border-slate-300 p-2 text-center text-[11px] text-slate-500" data-testid="apercu-qr-absent">QR généré à l’émission</div>
        </div>
        <div class="h-3" :style="{ background: color }" />
      </article>
      <section class="rounded-xl border bg-white p-3 text-sm" data-testid="infos-generation">
        <h3 class="mb-2 flex items-center gap-2 font-bold"><AdminIcon name="info" :size="18" />Informations de génération</h3>
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
          <dt class="text-slate-600">N° de support</dt><dd class="font-semibold" data-testid="info-numero">{{ view ? view.badge_code : 'Attribué à la génération' }}</dd>
          <dt class="text-slate-600">Jeton QR</dt><dd class="font-semibold">{{ view ? 'Généré (non affiché)' : 'Généré à la génération' }}</dd>
          <dt class="text-slate-600">Validité</dt><dd class="font-semibold">{{ view ? (view.validity ?? 'Sans limite affichée') : 'Selon la personne et son contexte' }}</dd>
          <dt class="text-slate-600">Modèle</dt><dd class="font-semibold">{{ view ? (view.provisional ? 'Provisoire (à valider)' : 'Validé') : indicative?.model ? indicative.model.label + ' · v' + indicative.model.version + (indicative.model.validated ? '' : ' (à valider)') : 'Neutre (catégorie non rattachée)' }}</dd>
        </dl>
        <p class="mt-2 text-xs text-slate-600">Un support n’est ni remis, ni activé, ni habilité par sa génération ou son impression.</p>
      </section>
    </template>
  </div>
</template>
