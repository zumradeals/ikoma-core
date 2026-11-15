/**
 * Chiffrement des données conservées sur la tablette (docs/AUTH_HORS_LIGNE.md, section 5).
 * Clé de données (DEK) aléatoire AES-256-GCM, jamais conservée en clair. Version 2 (sans PIN) : la DEK est enveloppée par une clé dérivée du BAIL de poste
 * (secret propre à cet appareil et à ce compte, calculé par le serveur et remis à une session authentifiée), puis par une clé d'appareil non exportable
 * conservée dans IndexedDB. Version 1 (ancienne, par PIN) : lue seulement pour migrer sans rien perdre (PBKDF2-SHA256 du PIN + secret d'appareil, HKDF).
 * Aucune dépendance : WebCrypto uniquement.
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

export type VaultRecordV1 = { salt: string; iterations: number; wrapped: string };
export type VaultRecordV2 = { v: 2; wrapped: string };
export type VaultRecord = VaultRecordV1 | VaultRecordV2;
export const isV2 = (record: VaultRecord): record is VaultRecordV2 => (record as VaultRecordV2).v === 2;

/** Clé d'enveloppe de la version 2 : dérivée du seul bail de poste (32 octets issus du serveur). */
async function deriveLeaseKek(lease: Uint8Array): Promise<CryptoKey> {
  const material = await subtle().importKey('raw', lease as BufferSource, 'HKDF', false, ['deriveKey']);
  return subtle().deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: encoder.encode('ikoma-poste-v2') as BufferSource, info: encoder.encode('ikoma-poste-kek-v2') }, material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}

/** Enveloppe une clé de données déjà connue (octets) dans le format sans PIN : bail puis clé d'appareil. */
export async function wrapRawV2(raw: Uint8Array, lease: Uint8Array, deviceKey: CryptoKey): Promise<VaultRecordV2> {
  const inner = await seal(await deriveLeaseKek(lease), raw, 'ikoma-dek');
  return { v: 2, wrapped: toB64(await seal(deviceKey, inner, 'ikoma-dek-device')) };
}

/** Crée le coffre sans PIN : DEK aléatoire, enveloppée par le bail puis par la clé d'appareil. */
export async function createVaultV2(lease: Uint8Array, deviceKey: CryptoKey): Promise<{ record: VaultRecordV2; dek: CryptoKey }> {
  const raw = randomBytes(32);
  return { record: await wrapRawV2(raw, lease, deviceKey), dek: await importDek(raw) };
}

/** Ouvre le coffre sans PIN. Un autre bail (autre appareil, autre compte), une autre clé d'appareil ou un enregistrement altéré font échouer le déchiffrement. */
export async function openVaultV2(record: VaultRecordV2, lease: Uint8Array, deviceKey: CryptoKey): Promise<CryptoKey> {
  const inner = await unseal(deviceKey, fromB64(record.wrapped), 'ikoma-dek-device');
  return importDek(await unseal(await deriveLeaseKek(lease), inner, 'ikoma-dek'));
}

/** Ancien coffre par PIN (version 1) : création conservée pour les tests de migration. */
export async function createVault(pin: string, serverSecret: Uint8Array, deviceKey: CryptoKey, iterations = PBKDF2_ITERATIONS): Promise<{ record: VaultRecordV1; dek: CryptoKey }> {
  const salt = randomBytes(16);
  const raw = randomBytes(32);
  const kek = await deriveKek(pin, salt, serverSecret, iterations);
  const inner = await seal(kek, raw, 'ikoma-dek');
  const wrapped = await seal(deviceKey, inner, 'ikoma-dek-device');
  return { record: { salt: toB64(salt), iterations, wrapped: toB64(wrapped) }, dek: await importDek(raw) };
}

/** Ancien coffre (version 1) : les octets de la clé de données, pour la migration seulement. Un mauvais PIN, un autre secret ou une autre tablette font échouer. */
export async function unwrapV1Raw(pin: string, record: VaultRecordV1, serverSecret: Uint8Array, deviceKey: CryptoKey): Promise<Uint8Array> {
  const kek = await deriveKek(pin, fromB64(record.salt), serverSecret, record.iterations);
  const inner = await unseal(deviceKey, fromB64(record.wrapped), 'ikoma-dek-device');
  return unseal(kek, inner, 'ikoma-dek');
}

/** Ouvre l'ancien coffre (version 1). */
export async function openVault(pin: string, record: VaultRecordV1, serverSecret: Uint8Array, deviceKey: CryptoKey): Promise<CryptoKey> {
  return importDek(await unwrapV1Raw(pin, record, serverSecret, deviceKey));
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
