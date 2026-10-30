<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { startScan, type ScanHandle } from '../../poste/media';
defineProps<{ name: string; busy: boolean }>();
const emit = defineEmits<{ submit: [code: string] }>();
const video = ref<HTMLVideoElement | null>(null); const code = ref(''); const scanError = ref(''); const scanning = ref(false);
let handle: ScanHandle | null = null;
async function start() {
  scanError.value = '';
  try { if (video.value) { handle = await startScan(video.value, text => emit('submit', text)); scanning.value = true; } }
  catch (e) { scanning.value = false; scanError.value = e instanceof Error && e.message.includes('HTTPS') ? e.message : 'La caméra n’est pas accessible : saisissez le code imprimé sur le badge.'; }
}
onMounted(start);
onBeforeUnmount(() => handle?.stop());
</script>
<template>
  <section aria-labelledby="badge-titre" class="space-y-5">
    <h1 id="badge-titre" class="text-display font-bold">Scannez le badge de {{ name }}</h1>
    <div class="agent-badge-grid">
<div>
    <div class="agent-camera"><video ref="video" class="mx-auto max-h-[40vh] w-full object-contain" playsinline muted aria-label="Caméra de lecture du badge"></video></div>
    <p v-if="scanning" role="status">Présentez le QR du badge devant la caméra arrière.</p>
    <p v-if="scanError" role="alert" class="font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ scanError }}</p>
    </div><div class="agent-badge-help">
<h2>Choisir et associer le badge</h2><p class="mt-3">Prenez un badge disponible affecté à ce poste. Si aucun badge n'a été réservé, demandez à l'administrateur de choisir le lot et le poste dans Administration → Badges, puis synchronisez la tablette. Le scan et la saisie manuelle appliquent les mêmes règles.</p>
    <form class="mt-4 space-y-3" aria-label="Saisir le code du badge" @submit.prevent="code.trim() && emit('submit', code.trim())">
      <label class="block text-xl font-semibold">QR illisible ? Saisissez le code imprimé (exemple : B-014)<input v-model="code" class="field !min-h-16 !text-2xl uppercase" autocomplete="off" maxlength="100"></label>
      <button type="submit" class="btn-main" :disabled="!code.trim() || busy">Associer ce badge</button>
    </form>
    </div>
</div>
    <button v-if="scanError" type="button" class="btn-soft" @click="start">Réessayer la caméra</button>
  </section>
</template>
