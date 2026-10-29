import { expect, type Page } from '@playwright/test';
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

/**
 * Le serveur impose une limite aux connexions. Les recettes E2E ne désactivent
 * jamais cette limite : elles retentent une seule fois si elle est signalée.
 */
export async function loginWithSecondFactor(page: Page, email: string, password: string, secret: string): Promise<void> {
  for (let attempt = 0; attempt < 2; attempt++) {
    await page.goto('/login');
    await page.getByLabel('Adresse électronique').fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    const [response] = await Promise.all([
      page.waitForResponse(r => new URL(r.url()).pathname === '/login' && r.request().method() === 'POST'),
      page.getByRole('button', { name: 'Se connecter' }).click(),
    ]);
    const challenge = page.getByLabel('Code à six chiffres');
    const throttled = page.getByRole('alert').filter({ hasText: /Trop de tentatives/i }).first();
    if (response.status() !== 429) {
      try {
        await expect(challenge.or(throttled)).toBeVisible({ timeout: 15_000 });
      } catch {
        // Indications techniques sans journaliser les identifiants ni les secrets TOTP.
        const currentPath = new URL(page.url()).pathname;
        const target = response.headers()['location'] ?? '';
        const redirectPath = target ? new URL(target, page.url()).pathname : '(aucune)';
        const errorVisible = await page.getByRole('alert').count() > 0;
        throw new Error(`Écran 2FA absent après POST /login (HTTP ${response.status()}, redirection ${redirectPath}, page ${currentPath}, erreur de formulaire ${errorVisible ? 'présente' : 'absente'}). Aucune donnée de connexion n'est enregistrée.`);
      }
      if (await challenge.isVisible()) {
        await challenge.fill(await freshOtp(secret));
        await page.getByRole('button', { name: 'Vérifier', exact: true }).click();
        await expect(page).toHaveURL(/\/(administration|espace)$/, { timeout: 15_000 });
        return;
      }
    }
    if (attempt === 1) throw new Error('La connexion de recette reste limitée après le délai serveur.');
    const message = await throttled.isVisible() ? await throttled.innerText() : '';
    const secondsInMessage = message.match(/(\d+)\s*secondes?/i);
    const secondsInHeader = Number(response.headers()['retry-after']);
    const seconds = secondsInMessage ? Number(secondsInMessage[1]) : Number.isFinite(secondsInHeader) && secondsInHeader > 0 ? secondsInHeader : 60;
    await page.waitForTimeout(Math.min(65, Math.max(2, seconds + 2)) * 1_000);
  }
}
