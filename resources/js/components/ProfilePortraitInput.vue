<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
const emit = defineEmits<{ selected: [file: File | null]; remove: [] }>();
const props = defineProps<{ existing?: string | null }>();
const preview = ref(''); const raw = ref<HTMLImageElement | null>(null); const zoom = ref(1); const x = ref(50); const y = ref(50); const error = ref(''); const video = ref<HTMLVideoElement | null>(null); const camera = ref(false);
let stream: MediaStream | null = null;
function stop() { stream?.getTracks().forEach(track => track.stop()); stream = null; camera.value = false; }
function crop() {
  if (!raw.value) return;
  const image = raw.value; const width = Math.min(image.naturalWidth, image.naturalHeight * .75) / zoom.value; const height = width / .75;
  const canvas = document.createElement('canvas'); canvas.width = 360; canvas.height = 480;
  const ctx = canvas.getContext('2d')!; ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 360, 480); ctx.drawImage(image, (image.naturalWidth - width) * x.value / 100, (image.naturalHeight - height) * y.value / 100, width, height, 0, 0, 360, 480);
  preview.value = canvas.toDataURL('image/jpeg', .85);
  canvas.toBlob(blob => { if (blob) emit('selected', new File([blob], 'portrait.jpg', { type: 'image/jpeg' })); }, 'image/jpeg', .85);
}
async function load(file?: File) {
  error.value = '';
  if (!file) return;
  if (!['image/jpeg', 'image/png'].includes(file.type) || file.size > 5 * 1024 * 1024) { error.value = 'Choisissez un JPEG ou PNG de 5 Mo au maximum.'; return; }
  const url = URL.createObjectURL(file);
  try {
    const image = new Image(); image.src = url; await image.decode();
    if (image.naturalWidth * image.naturalHeight > 12000000) { error.value = 'Photo trop grande : 12 millions de pixels au maximum.'; return; }
    raw.value = image; zoom.value = 1; x.value = 50; y.value = 50; crop();
  } catch { error.value = 'Impossible de lire la photo.'; } finally { URL.revokeObjectURL(url); }
}
async function start() {
  try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false }); camera.value = true; await nextTick(); if (video.value) { video.value.srcObject = stream; await video.value.play(); } }
  catch { stop(); error.value = 'Caméra indisponible ou refusée. Vous pouvez importer une photo ou l’ajouter plus tard.'; }
}
function capture() {
  if (!video.value) return;
  const canvas = document.createElement('canvas'); canvas.width = video.value.videoWidth; canvas.height = video.value.videoHeight;
  canvas.getContext('2d')!.drawImage(video.value, 0, 0);
  canvas.toBlob(blob => { if (blob) void load(new File([blob], 'camera.jpg', { type: 'image/jpeg' })); }, 'image/jpeg', .9); stop();
}
function remove() { raw.value = null; preview.value = ''; emit('selected', null); emit('remove'); stop(); }
watch([zoom, x, y], crop); watch(() => props.existing, () => { raw.value = null; preview.value = ''; }); onBeforeUnmount(stop);
</script>
<template>
  <fieldset class="space-y-3 rounded-xl border p-4">
<legend class="font-bold">Photo de la personne (facultatif)</legend>
    <p class="text-base">Portrait volontaire. Il ne remplace jamais la photo du contrôle d’identité.</p>
    <img v-if="preview || existing" :src="preview || existing || ''" alt="Aperçu du portrait" class="h-48 w-36 rounded-lg object-cover">
    <label class="block">Importer une photo<input type="file" accept="image/jpeg,image/png" class="field" @change="load(($event.target as HTMLInputElement).files?.[0])"></label>
    <div class="flex flex-wrap gap-3"><button type="button" class="btn-soft" @click="start">Prendre une photo</button><button type="button" class="btn-soft" @click="remove">{{ preview || existing ? 'Retirer la photo' : 'Ajouter plus tard' }}</button></div>
    <template v-if="raw"><label class="block">Zoom du portrait<input v-model.number="zoom" class="field" type="range" min="1" max="3" step="0.05"></label><label class="block">Cadrage horizontal<input v-model.number="x" class="field" type="range" min="0" max="100"></label><label class="block">Cadrage vertical<input v-model.number="y" class="field" type="range" min="0" max="100"></label></template>
    <div v-if="camera"><video ref="video" autoplay muted playsinline class="w-full rounded-lg"></video><button type="button" class="btn-soft" @click="capture">Utiliser cette photo</button><button type="button" class="btn-soft" @click="stop">Fermer la caméra</button></div>
    <p v-if="error" role="alert">{{ error }}</p>
  </fieldset>
</template>
