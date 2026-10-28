import { createHmac } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';

const LAST = '.secrets/otp-last.json';

function code(secret: string, at: number): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of secret) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes = []; for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(at / 30000)));
  const hash = createHmac('sha1', Buffer.from(bytes)).update(counter).digest(); const offset = hash[19] & 15;
  return ((hash.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}

/**
 * Code à six chiffres valable maintenant. Le serveur refuse un code déjà utilisé dans la même période de 30 s : si ce compte vient de servir
 * (par un autre parcours, même fichier ou non), on attend la période suivante au lieu de rejouer un code refusé.
 */
export async function freshOtp(secret: string): Promise<string> {
  const used: Record<string, number> = existsSync(LAST) ? JSON.parse(readFileSync(LAST, 'utf8')) : {};
  let window = Math.floor(Date.now() / 30000);
  if (used[secret] !== undefined && window <= used[secret]) {
    await new Promise(resolve => setTimeout(resolve, (used[secret] + 1) * 30000 - Date.now() + 500));
    window = Math.floor(Date.now() / 30000);
  }
  used[secret] = window;
  writeFileSync(LAST, JSON.stringify(used));

  return code(secret, window * 30000);
}
