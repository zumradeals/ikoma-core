<script setup lang="ts">
import AdminIcon from '../admin/AdminIcon.vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { captureJpeg, closeCamera, DOCUMENT_MAX_SIDE, openCamera } from '../../poste/media';
import { documentLabels, type IdentityDocument } from '../../poste/types';
const props = defineProps<{ name: string; busy: boolean; documentRequired?: boolean }>();
const emit = defineEmits<{ done: [photo: Blob, doc: IdentityDocument | undefined]; back: [] }>();
const docType = ref(''); const docNumber = ref(''); const docPhoto = ref<Blob | null>(null); const docPreview = ref('');
const numberOk = computed(() => { const n = docNumber.value.replace(/[\s.]+/g, ''); return n === '' || /^[A-Za-z0-9/-]{4,30}$/.test(n); });
const docOk = computed(() => !props.documentRequired || (docType.value !== '' && !!docPhoto.value && numberOk.value));
const video = ref<HTMLVideoElement | null>(null);
const checked = ref(false); const photo = ref<Blob | null>(null); const preview = ref(''); const cameraError = ref('');
const cameraFacing = ref<'environment' | 'user'>('environment');
let stream: MediaStream | null = null;
let cameraRequest = 0;
const reason = computed(() => (!checked.value && !photo.value ? 'Cochez la pièce d’identité et prenez la photo.' : !checked.value ? 'Cochez « pièce d’identité contrôlée ».' : !photo.value ? 'Prenez la photo du visiteur.' : !docOk.value ? (!numberOk.value ? 'Le numéro de la pièce n’est pas valable : 4 à 30 lettres ou chiffres, ou laissez-le vide.' : 'Choisissez le type de la pièce et photographiez-la.') : ''));
async function start() {
  cameraError.value = '';
  const request = ++cameraRequest;
  closeCamera(video.value, stream);
  stream = null;
  if (!video.value) return;
  try {
    const opened = await openCamera(video.value, cameraFacing.value);
    if (request !== cameraRequest) { closeCamera(video.value, opened); return; }
    stream = opened;
  } catch (e) {
    if (request === cameraRequest) cameraError.value = e instanceof Error && e.message.includes('HTTPS') ? e.message : 'Caméra indisponible : autorisez-la dans Chrome ou utilisez « Changer de caméra ».';
  }
}
async function toggleCamera() {
  cameraFacing.value = cameraFacing.value === 'environment' ? 'user' : 'environment';
  await start();
}
async function take() {
  if (!video.value) return;
  try { photo.value = await captureJpeg(video.value); if (preview.value) URL.revokeObjectURL(preview.value); preview.value = URL.createObjectURL(photo.value); }
  catch (e) { cameraError.value = e instanceof Error ? e.message : 'Photo impossible.'; }
}
async function takeDocument() {
  if (!video.value) return;
  try { docPhoto.value = await captureJpeg(video.value, 0.85, DOCUMENT_MAX_SIDE); if (docPreview.value) URL.revokeObjectURL(docPreview.value); docPreview.value = URL.createObjectURL(docPhoto.value); }
  catch (e) { cameraError.value = e instanceof Error ? e.message : 'Photo impossible.'; }
}
function retakeDocument() { docPhoto.value = null; if (docPreview.value) URL.revokeObjectURL(docPreview.value); docPreview.value = ''; }
function retake() { photo.value = null; if (preview.value) URL.revokeObjectURL(preview.value); preview.value = ''; }
onMounted(start);
onBeforeUnmount(() => { cameraRequest++; closeCamera(video.value, stream); if (preview.value) URL.revokeObjectURL(preview.value); if (docPreview.value) URL.revokeObjectURL(docPreview.value); });
</script>
<template>
  <section aria-labelledby="identite-titre" class="space-y-5">
    <h1 id="identite-titre" class="text-display font-bold">Pièce d’identité et photo de {{ name }}</h1>
    <p class="agent-identity-config" data-testid="configuration-piece"><span aria-hidden="true">{{ documentRequired ? 'ⓘ' : '✓' }}</span> <strong>Photographie de la pièce d’identité : {{ documentRequired ? 'activée par l’administration' : 'désactivée' }}.</strong><br>{{ documentRequired ? 'Contrôlez la pièce, photographiez-la et prenez la photo du visiteur. Le numéro reste facultatif.' : 'Cochez le contrôle de la pièce et prenez uniquement la photo du visiteur.' }}</p>
    <label class="flex min-h-16 items-center gap-4 rounded-xl border border-slate-300 bg-white p-4 text-xl font-semibold"><input v-model="checked" type="checkbox" class="h-8 w-8">Pièce d’identité contrôlée</label>
    <div class="agent-identity-grid">
      <div class="agent-photo-panel">
<h2 class="mb-3">Photo du visiteur</h2>
        <div class="agent-camera"><video v-show="!photo" ref="video" playsinline muted aria-label="Aperçu de la caméra"></video><img v-if="photo" :src="preview" alt="Photo prise du visiteur"></div>
        <p v-if="cameraError" role="alert" class="mt-3 font-semibold text-red-800"><span aria-hidden="true">⚠</span> {{ cameraError }}</p>
        <div class="agent-photo-actions"><button v-if="!photo" type="button" class="btn-main" @click="take"><AdminIcon name="camera" /> Prendre la photo</button><button v-else type="button" class="btn-soft" @click="retake">Reprendre la photo</button><button type="button" class="btn-soft" data-testid="changer-camera" @click="toggleCamera">Changer de caméra ({{ cameraFacing === 'environment' ? 'arrière demandée' : 'avant demandée' }})</button><button v-if="cameraError" type="button" class="btn-soft" @click="start">Réessayer la caméra</button></div>
      </div>
      <div v-if="documentRequired" class="agent-photo-panel space-y-4" data-testid="bloc-piece">
<h2>Pièce d’identité</h2><p>Type et photo obligatoires selon le réglage administratif.</p>
        <label class="block text-lg font-semibold" for="piece-type">Type de pièce<select id="piece-type" v-model="docType" class="field" data-testid="piece-type"><option value="" disabled>Choisir…</option><option v-for="(label, key) in documentLabels" :key="key" :value="key">{{ label }}</option></select></label>
        <label class="block text-lg font-semibold" for="piece-numero">Numéro (facultatif)<input id="piece-numero" v-model="docNumber" class="field uppercase" maxlength="40" autocomplete="off" autocapitalize="characters" data-testid="piece-numero"></label>
        <button v-if="!docPhoto" type="button" class="btn-soft w-full" data-testid="photo-piece" @click="takeDocument"><AdminIcon name="book" /> Photographier la pièce</button>
        <template v-else><img :src="docPreview" alt="Photo de la pièce d’identité" class="max-h-32 rounded-lg border"><button type="button" class="btn-soft" @click="retakeDocument">Reprendre la pièce</button></template>
      </div>
      <div v-else class="agent-badge-help"><h2>Contrôle de l’identité</h2><p class="mt-3">Vérifiez que le visiteur correspond à la fiche. La photographie du document n’est pas demandée et aucun document n’est enregistré.</p><p class="agent-help">Les vérifications restent obligatoires avant de passer à la décision.</p></div>
    </div>
    <div class="agent-step-actions"><button type="button" class="btn-soft" @click="$emit('back')">Retour</button><div><button type="button" class="btn-main" :disabled="!!reason || busy" :aria-describedby="reason ? 'identite-raison' : undefined" @click="photo && emit('done', photo, documentRequired && docPhoto ? { type: docType, number: docNumber.trim() || undefined, photo: docPhoto } : undefined)">Enregistrer et passer à la décision</button><p v-if="reason" id="identite-raison" class="mt-2">{{ reason }}</p></div></div>
  </section>
</template>
