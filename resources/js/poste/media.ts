import { BrowserQRCodeReader } from '@zxing/browser';
import jsQR from 'jsqr';
/** Caméra de la tablette : photo réduite (640 px maximum, JPEG) et lecture de QR (BarcodeDetector sinon @zxing/browser, sans aucun appel réseau). zxing est importé avec la page, pas à la demande : un chargement différé pouvait rester en attente pendant l'activation du service worker et le QR n'était jamais lu. */

export const PHOTO_MAX_SIDE = 640;
/** Photo d'une pièce d'identité (option de l'administrateur) : assez grande pour lire le texte. */
export const DOCUMENT_MAX_SIDE = 1280;

export async function openCamera(video: HTMLVideoElement, facing: 'user' | 'environment'): Promise<MediaStream> {
  if (!navigator.mediaDevices?.getUserMedia) throw new Error('Caméra indisponible sur cet appareil (connexion HTTPS requise).');
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing }, audio: false });
  video.srcObject = stream;
  video.muted = true;
  await video.play().catch(() => undefined);
  return stream;
}

export function closeCamera(video: HTMLVideoElement | null, stream: MediaStream | null): void {
  stream?.getTracks().forEach(track => track.stop());
  if (video) video.srcObject = null;
}

/** Capture l'image courante de la vidéo, réduite pour que le plus grand côté ne dépasse pas 640 px. */
export function captureJpeg(video: HTMLVideoElement, quality = 0.8, maxSide = PHOTO_MAX_SIDE): Promise<Blob> {
  const width = video.videoWidth || 640; const height = video.videoHeight || 480;
  const scale = Math.min(1, maxSide / Math.max(width, height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(width * scale)); canvas.height = Math.max(1, Math.round(height * scale));
  const context = canvas.getContext('2d');
  if (!context) return Promise.reject(new Error('Photo impossible sur cet appareil.'));
  context.drawImage(video, 0, 0, canvas.width, canvas.height);
  return new Promise((resolve, reject) => canvas.toBlob(blob => (blob ? resolve(blob) : reject(new Error('Photo impossible sur cet appareil.'))), 'image/jpeg', quality));
}

type DetectorLike = { detect(source: CanvasImageSource): Promise<{ rawValue: string }[]> };
type DetectorCtor = { new (options: { formats: string[] }): DetectorLike; getSupportedFormats?: () => Promise<string[]> };

export type ScanHandle = { stop(): void };

/** Lit les QR devant la caméra et appelle `onCode` pour chaque nouvelle valeur lue. */
export async function startScan(video: HTMLVideoElement, onCode: (text: string) => boolean | void): Promise<ScanHandle> {
  const stream = await openCamera(video, 'environment');
  let stopped = false;
  let last = ''; let lastAt = 0;
  // Un même QR n'est pas renvoyé en rafale, mais il l'est de nouveau après 3 s : une lecture ignorée (opération déjà en cours) ou refusée peut être retentée en gardant le badge devant la caméra.
  // Si le receveur répond `false` (il n'était pas prêt), la lecture n'est pas comptée : elle sera renvoyée à la prochaine image.
  const emit = (text: string) => { const now = Date.now(); if (!stopped && text && (text !== last || now - lastAt > 3000) && onCode(text) !== false) { last = text; lastAt = now; } };
  const Detector = (window as unknown as { BarcodeDetector?: DetectorCtor }).BarcodeDetector;
  const supported = Detector ? await (Detector.getSupportedFormats?.() ?? Promise.resolve(['qr_code'])).catch(() => []) : [];
  if (Detector && supported.includes('qr_code')) {
    const detector = new Detector({ formats: ['qr_code'] });
    const timer = window.setInterval(async () => {
      if (stopped || video.readyState < 2) return;
      try { const found = await detector.detect(video); if (found[0]) emit(found[0].rawValue); } catch { /* image non prête : on réessaie */ }
    }, 200);
    return { stop() { stopped = true; window.clearInterval(timer); closeCamera(video, stream); } };
  }
  // Lecture image par image : on ne confie pas le flux à la boucle interne de zxing (decodeFromStream), qui attend un événement « loadedmetadata »
  // déjà passé quand la caméra est ouverte avant et pouvait ne jamais lire le QR (blocage intermittent constaté en recette).
  const reader = new BrowserQRCodeReader();
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d', { willReadFrequently: true });
  const timer = window.setInterval(() => {
    if (stopped || !context || video.readyState < 2 || !video.videoWidth) return;
    if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) { canvas.width = video.videoWidth; canvas.height = video.videoHeight; }
    context.drawImage(video, 0, 0);
    try { emit(reader.decodeFromCanvas(canvas).getText()); return; } catch { /* zxing ne lit pas tous les QR valides (environ 1 sur 6 des badges d'essai) : on essaie jsQR */ }
    try { const image = context.getImageData(0, 0, canvas.width, canvas.height); const found = jsQR(image.data, image.width, image.height); if (found) emit(found.data); } catch { /* aucun QR lisible dans cette image */ }
  }, 150);
  return { stop() { stopped = true; window.clearInterval(timer); closeCamera(video, stream); } };
}
