<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { captureJpeg, closeCamera, openCamera } from '../../poste/media';
import { documentLabels, type IdentityDocument } from '../../poste/types';
defineProps<{ name: string; busy: boolean }>();
const emit = defineEmits<{ done: [photo: Blob, doc: IdentityDocument]; back: [] }>();
const docType = ref(''); const docNumber = ref('');
const docOk = computed(() => docType.value !== '' && /^[A-Za-z0-9/-]{4,30}$/.test(docNumber.value.replace(/[\s.]+/g, '')));
const video = ref<HTMLVideoElement | null>(null);
const checked = ref(false); const photo = ref<Blob | null>(null); const preview = ref(''); const cameraError = ref('');
let stream: MediaStream | null = null;
const reason = computed(() => (!checked.value && !photo.value ? 'Cochez la pièce d’identité et prenez la photo.' : !checked.value ? 'Cochez « pièce d’identité contrôlée ».' : !docOk.value ? 'Indiquez le type et le numéro de la pièce (4 à 30 lettres ou chiffres).' : !photo.value ? 'Prenez la photo du visiteur.' : ''));
async function start() {
  cameraError.value = '';
  try { if (video.value) stream = await openCamera(video.value, 'user'); }
  catch (e) { cameraError.value = e instanceof Error && e.message.includes('HTTPS') ? e.message : 'La caméra n’est pas accessible : autorisez-la dans le navigateur puis touchez « Réessayer ».'; }
}
async function take() {
  if (!video.value) return;
  try { photo.value = await captureJpeg(video.value); if (preview.value) URL.revokeObjectURL(preview.value); preview.value = URL.createObjectURL(photo.value); }
  catch (e) { cameraError.value = e instanceof Error ? e.message : 'Photo impossible.'; }
}
function retake() { photo.value = null; if (preview.value) URL.revokeObjectURL(preview.value); preview.value = ''; }
onMounted(start);
onBeforeUnmount(() => { closeCamera(video.value, stream); if (preview.value) URL.revokeObjectURL(preview.value); });
</script>
<template>
  <section aria-labelledby="identite-titre" class="space-y-5">
    <h1 id="identite-titre" class="text-display font-bold">Pièce d’identité et photo de {{ name }}</h1>
    <label class="flex min-h-16 items-center gap-4 rounded-xl border-2 border-slate-300 bg-white p-4 text-xl font-semibold"><input v-model="checked" type="checkbox" class="h-8 w-8">Pièce d’identité contrôlée</label>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="block text-lg font-semibold" for="piece-type">Type de pièce
        <select id="piece-type" v-model="docType" class="mt-1 min-h-14 w-full rounded-xl border-2 border-slate-300 bg-white px-3 text-xl" data-testid="piece-type"><option value="" disabled>Choisir…</option><option v-for="(label, key) in documentLabels" :key="key" :value="key">{{ label }}</option></select>
      </label>
      <label class="block text-lg font-semibold" for="piece-numero">Numéro de la pièce
        <input id="piece-numero" v-model="docNumber" class="mt-1 min-h-14 w-full rounded-xl border-2 border-slate-300 bg-white px-3 text-xl uppercase" maxlength="40" autocomplete="off" autocapitalize="characters" data-testid="piece-numero">
      </label>
    </div>
    <div class="overflow-hidden rounded-2xl bg-slate-900">
      <video v-show="!photo" ref="video" class="mx-auto max-h-[45vh] w-full object-contain" playsinline muted aria-label="Aperçu de la caméra"></video>
      <img v-if="photo" :src="preview" alt="Photo prise du visiteur" class="mx-auto max-h-[45vh] w-full object-contain">
    </div>
    <p v-if="cameraError" role="alert" class="font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ cameraError }}</p>
    <div class="grid gap-3 sm:grid-cols-2">
      <button v-if="!photo" type="button" class="btn-main" @click="take"><span aria-hidden="true">📷</span> Prendre la photo</button>
      <button v-else type="button" class="btn-soft" @click="retake">Reprendre la photo</button>
      <button v-if="cameraError" type="button" class="btn-soft" @click="start">Réessayer la caméra</button>
    </div>
    <div class="grid gap-3 sm:grid-cols-[1fr_2fr]">
      <button type="button" class="btn-soft" @click="$emit('back')">Retour</button>
      <div><button type="button" class="btn-main" :disabled="!!reason || busy" :aria-describedby="reason ? 'identite-raison' : undefined" @click="photo && emit('done', photo, { type: docType, number: docNumber })">Enregistrer et passer à la décision</button><p v-if="reason" id="identite-raison" class="mt-2">{{ reason }}</p></div>
    </div>
  </section>
</template>
