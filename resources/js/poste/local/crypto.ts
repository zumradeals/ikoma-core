/**
 * Chiffrement des données conservées sur la tablette (docs/AUTH_HORS_LIGNE.md, section 5).
 * Clé de données (DEK) aléatoire AES-256-GCM, enveloppée par une clé dérivée du PIN (PBKDF2-SHA256) et du secret d'appareil remis par le serveur
 * (HKDF), puis par une clé d'appareil non exportable conservée dans IndexedDB. Aucune dépendance : WebCrypto uniquement.
 */

export const PBKDF2_ITERATIONS = 600_000;
const subtle = (): SubtleCrypto => globalThis.crypto.subtle;
const encoder = new TextEncoder();
const decoder = new TextDecoder();

export function toB64(bytes: Uint8Array): string {
  let binary = '';
  for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  return btoa(binary);
}
export function fromB64(text: string): Uint8Array {
  const binary = atob(text);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes;
}
export const randomBytes = (length: number): Uint8Array => globalThis.crypto.getRandomValues(new Uint8Array(length));

/** Clé d'appareil : non exportable, utilisable seulement sur cette tablette. */
export function createDeviceKey(): Promise<CryptoKey> {
  return subtle().generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}

/** Clé issue du PIN et du secret d'appareil : PBKDF2 puis HKDF. */
export async function deriveKek(pin: string, salt: Uint8Array, serverSecret: Uint8Array, iterations = PBKDF2_ITERATIONS): Promise<CryptoKey> {
  const material = await subtle().importKey('raw', encoder.encode(pin), 'PBKDF2', false, ['deriveBits']);
  const bits = await subtle().deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: salt as BufferSource, iterations }, material, 256);
  const hkdfKey = await subtle().importKey('raw', bits, 'HKDF', false, ['deriveKey']);
  return subtle().deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: serverSecret as BufferSource, info: encoder.encode('ikoma-poste-kek') }, hkdfKey, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}

async function seal(key: CryptoKey, plain: Uint8Array, aad: string): Promise<Uint8Array> {
  const iv = randomBytes(12);
  const encrypted = new Uint8Array(await subtle().encrypt({ name: 'AES-GCM', iv: iv as BufferSource, additionalData: encoder.encode(aad) }, key, plain as BufferSource));
  const out = new Uint8Array(iv.length + encrypted.length);
  out.set(iv); out.set(encrypted, iv.length);
  return out;
}
async function unseal(key: CryptoKey, blob: Uint8Array, aad: string): Promise<Uint8Array> {
  return new Uint8Array(await subtle().decrypt({ name: 'AES-GCM', iv: blob.slice(0, 12) as BufferSource, additionalData: encoder.encode(aad) }, key, blob.slice(12) as BufferSource));
}

/** `duress` : le même coffre, enveloppé une seconde fois par le code de contrainte (audit global C2). */
export type VaultRecord = { salt: string; iterations: number; wrapped: string; duress?: { salt: string; wrapped: string } };

/** Crée le coffre : DEK aléatoire, enveloppée par la clé PIN puis par la clé d'appareil. */
export async function createVault(pin: string, serverSecret: Uint8Array, deviceKey: CryptoKey, iterations = PBKDF2_ITERATIONS, duressPin?: string): Promise<{ record: VaultRecord; dek: CryptoKey }> {
  const salt = randomBytes(16);
  const raw = randomBytes(32);
  const kek = await deriveKek(pin, salt, serverSecret, iterations);
  const inner = await seal(kek, raw, 'ikoma-dek');
  const wrapped = await seal(deviceKey, inner, 'ikoma-dek-device');
  const record: VaultRecord = { salt: toB64(salt), iterations, wrapped: toB64(wrapped) };
  if (duressPin) {
    const duressSalt = randomBytes(16);
    const duressInner = await seal(await deriveKek(duressPin, duressSalt, serverSecret, iterations), raw, 'ikoma-dek-duress');
    record.duress = { salt: toB64(duressSalt), wrapped: toB64(await seal(deviceKey, duressInner, 'ikoma-dek-device')) };
  }
  return { record, dek: await importDek(raw) };
}

/** Ouvre le coffre. Un mauvais PIN, un autre secret ou une autre tablette font échouer le déchiffrement. */
export async function openVault(pin: string, record: VaultRecord, serverSecret: Uint8Array, deviceKey: CryptoKey): Promise<CryptoKey> {
  const kek = await deriveKek(pin, fromB64(record.salt), serverSecret, record.iterations);
  const inner = await unseal(deviceKey, fromB64(record.wrapped), 'ikoma-dek-device');
  return importDek(await unseal(kek, inner, 'ikoma-dek'));
}

/**
 * Ouvre le coffre avec le PIN ou avec le code de contrainte. Les deux essais sont toujours faits, même quand il n'existe pas de code de contrainte :
 * la durée ne dit pas lequel des deux codes a été saisi.
 */
export async function openVaultMode(pin: string, record: VaultRecord, serverSecret: Uint8Array, deviceKey: CryptoKey): Promise<{ dek: CryptoKey; duress: boolean }> {
  const attempt = async (salt: string, wrapped: string, aad: string): Promise<CryptoKey | null> => {
    try {
      const kek = await deriveKek(pin, fromB64(salt), serverSecret, record.iterations);
      return await importDek(await unseal(kek, await unseal(deviceKey, fromB64(wrapped), 'ikoma-dek-device'), aad));
    } catch { return null; }
  };
  const main = await attempt(record.salt, record.wrapped, 'ikoma-dek');
  const duress = record.duress ? await attempt(record.duress.salt, record.duress.wrapped, 'ikoma-dek-duress') : await attempt(record.salt, record.wrapped, 'ikoma-dek-duress');
  if (main) return { dek: main, duress: false };
  if (duress && record.duress) return { dek: duress, duress: true };
  throw new Error('pin_incorrect');
}

function importDek(raw: Uint8Array): Promise<CryptoKey> {
  return subtle().importKey('raw', raw as BufferSource, 'AES-GCM', false, ['encrypt', 'decrypt']);
}

/** Chiffre un objet JSON ; `aad` lie le contenu à son emplacement (un enregistrement recopié ailleurs ne se déchiffre pas). */
export async function sealJson(key: CryptoKey, value: unknown, aad: string): Promise<Uint8Array> {
  return seal(key, encoder.encode(JSON.stringify(value)), aad);
}
export async function openJson<T>(key: CryptoKey, blob: Uint8Array, aad: string): Promise<T> {
  return JSON.parse(decoder.decode(await unseal(key, blob, aad))) as T;
}
export const sealBytes = seal;
export const openBytes = unseal;

export async function sha256Hex(text: string): Promise<string> {
  const digest = new Uint8Array(await subtle().digest('SHA-256', encoder.encode(text)));
  return Array.from(digest, b => b.toString(16).padStart(2, '0')).join('');
}
