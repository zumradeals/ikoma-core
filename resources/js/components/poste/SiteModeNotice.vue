<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { PosteRepository } from '../../poste/repository';
import type { ActivityChoice } from '../../poste/types';

/**
 * Tablette SANS activité en cours (mode site) : elle contrôle le personnel permanent du site. L'accueil des visiteurs et les groupes n'existent qu'avec
 * une activité : cet écran l'explique et permet de choisir une activité (en ligne, parmi celles que l'administrateur a autorisées).
 */
const props = defineProps<{ repository: PosteRepository; online: boolean }>();
const choices = ref<ActivityChoice[]>([]);
const chosen = ref('');
const busy = ref(false);
const message = ref<string | null>(null);

onMounted(async () => {
  if (!props.repository.activities || !props.online) return;
  try { choices.value = await props.repository.activities(); } catch { /* hors ligne ou indisponible : le mode site reste utilisable */ }
});

async function choose() {
  if (!chosen.value || !props.repository.switchActivity) return;
  busy.value = true; message.value = null;
  try {
    await props.repository.switchActivity(chosen.value);
    window.location.reload();
  } catch (e) {
    message.value = e instanceof Error ? e.message : 'Impossible de changer d’activité.';
  } finally { busy.value = false; }
}
</script>

<template>
  <main class="mx-auto max-w-3xl space-y-6 px-4 py-10" data-testid="mode-site">
    <section class="space-y-3 rounded-2xl border-2 border-ikoma-navy bg-white p-6">
      <h1 class="text-2xl font-bold">Mode site : aucune activité en cours</h1>
      <p class="text-lg">Cette tablette contrôle le personnel permanent du site. L’accueil des visiteurs et les groupes apparaissent lorsqu’une activité est choisie.</p>
      <p><a href="/controle" class="btn-main inline-block" data-testid="ouvrir-controle">Ouvrir le contrôle des cartes</a></p>
    </section>
    <section v-if="choices.length > 0" class="space-y-3 rounded-2xl border bg-white p-6" aria-labelledby="choisir-activite" data-testid="choix-activite-site">
      <h2 id="choisir-activite" class="text-xl font-bold">Accueillir des visiteurs</h2>
      <p>Choisissez l’activité du jour : la liste de cette tablette sera remplacée par celle de l’activité.</p>
      <label class="block font-semibold">Activité
        <select v-model="chosen" class="field mt-1" data-testid="activite-site"><option value="" disabled>Choisir…</option><option v-for="c in choices" :key="c.id" :value="c.id">{{ c.name }}</option></select>
      </label>
      <button type="button" class="btn-main" :disabled="!chosen || busy" data-testid="valider-activite-site" @click="choose">Passer à cette activité</button>
      <p v-if="message" class="font-semibold text-red-800" role="alert">{{ message }}</p>
    </section>
  </main>
</template>
