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
    <div class="overflow-hidden rounded-2xl bg-slate-900"><video ref="video" class="mx-auto max-h-[40vh] w-full object-contain" playsinline muted aria-label="Caméra de lecture du badge"></video></div>
    <p v-if="scanning" role="status">Présentez le QR du badge devant la caméra arrière.</p>
    <p v-if="scanError" role="alert" class="font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ scanError }}</p>
    <form class="space-y-3" aria-label="Saisir le code du badge" @submit.prevent="code.trim() && emit('submit', code.trim())">
      <label class="block text-xl font-semibold">QR illisible ? Saisissez le code imprimé (exemple : B-014)<input v-model="code" class="field !min-h-16 !text-2xl uppercase" autocomplete="off" maxlength="100"></label>
      <button type="submit" class="btn-main" :disabled="!code.trim() || busy">Associer ce badge</button>
    </form>
    <button v-if="scanError" type="button" class="btn-soft" @click="start">Réessayer la caméra</button>
  </section>
</template>
