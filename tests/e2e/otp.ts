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
export async function loginWithSecondFactor(page: Page, email: string, password: string, secret: string, onChallenge?: () => Promise<void>, onStage?: (stage: string) => void): Promise<void> {
  for (let attempt = 0; attempt < 2; attempt++) {
    onStage?.('login.open');
    const loginResponse = await page.goto('/login');
    onStage?.('login.navigated');
    const currentPath = new URL(page.url()).pathname;
    onStage?.(currentPath === '/login' ? 'login.path.login' : 'login.path.other');
    if (loginResponse?.status() === 200) onStage?.('login.http.ok');
    else if ((loginResponse?.status() ?? 0) >= 500) onStage?.('login.http.servererror');
    else onStage?.('login.http.unexpected');
    const mount = page.locator('#app');
    onStage?.(await mount.count() ? 'login.mount.present' : 'login.mount.absent');
    const emailInput = page.getByLabel('Adresse électronique');
    const loginHeading = page.getByRole('heading', { name: 'Se connecter', exact: true });
    try {
      await expect(loginHeading).toBeVisible({ timeout: 12_000 });
      onStage?.('login.heading.visible');
      await expect(emailInput).toBeVisible({ timeout: 8_000 });
      onStage?.('login.email.visible');
    } catch {
      onStage?.(await page.locator('input[type="email"]').count() ? 'login.email.exists' : 'login.email.absent');
      onStage?.(await page.locator('script[type="module"]').count() ? 'login.scripts.present' : 'login.scripts.absent');
      onStage?.(await page.locator('h1').count() ? 'login.headings.present' : 'login.headings.absent');
      throw new Error('Le formulaire de connexion ne s’affiche pas dans le navigateur E2E (voir les jalons non sensibles).');
    }
    await emailInput.fill(email);
    onStage?.('login.email.filled');
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    onStage?.('login.password.filled');
    onStage?.('login.submit');
    const [response] = await Promise.all([
      page.waitForResponse(r => new URL(r.url()).pathname === '/login' && r.request().method() === 'POST'),
      page.getByRole('button', { name: 'Se connecter' }).click(),
    ]);
    onStage?.('login.responded');
    const challenge = page.getByLabel('Code à six chiffres');
    const throttled = page.getByRole('alert').filter({ hasText: /Trop de tentatives/i }).first();
    if (response.status() !== 429) {
      // Un POST valide redirige vers /two-factor-challenge. Sur certaines
      // recettes Inertia le navigateur perd ponctuellement cette transition.
      // Réessayer UNE fois le parcours normal, jamais le contourner.
      try {
        await expect(challenge.or(throttled)).toBeVisible({ timeout: 8_000 });
      } catch {
        const target = response.headers()['location'] ?? '';
        const redirectPath = target ? new URL(target, page.url()).pathname : '(aucune)';
        if (attempt === 0 && response.status() === 302 && redirectPath === '/two-factor-challenge') {
          // Pas d'identifiants ni de TOTP dans les journaux de recette.
          continue;
        }
        const currentPath = new URL(page.url()).pathname;
        const errorVisible = await page.getByRole('alert').count() > 0;
        let challengeStatus: string;
        try {
          const probe = await page.request.get('/two-factor-challenge', { maxRedirects: 0, timeout: 5_000, failOnStatusCode: false });
          const probeLocation = probe.headers()['location'] ?? '';
          const probePath = probeLocation ? new URL(probeLocation, page.url()).pathname : '(aucune)';
          challengeStatus = `HTTP ${probe.status()}, redirection ${probePath}`;
        } catch {
          challengeStatus = 'requête impossible';
        }
        throw new Error(`Écran 2FA absent après deux tentatives normales au maximum (POST HTTP ${response.status()}, redirection ${redirectPath}, page ${currentPath}, erreur de formulaire ${errorVisible ? 'présente' : 'absente'}, contrôle direct du challenge : ${challengeStatus}). Aucun identifiant ou secret journalisé.`);
      }
      if (await challenge.isVisible()) {
        onStage?.('challenge.visible');
        await onChallenge?.();
        await challenge.fill(await freshOtp(secret));
        onStage?.('otp.submit');
        await page.getByRole('button', { name: 'Vérifier', exact: true }).click();
        await expect(page).toHaveURL(/\/(administration|espace|pilotage)$/, { timeout: 15_000 });
        onStage?.('authenticated');
        return;
      }
    }
    onStage?.('login.throttled');
    if (attempt === 1) throw new Error('La connexion de recette reste limitée après le délai serveur.');
    const message = await throttled.isVisible() ? await throttled.innerText() : '';
    const secondsInMessage = message.match(/(\d+)\s*secondes?/i);
    const secondsInHeader = Number(response.headers()['retry-after']);
    const seconds = secondsInMessage ? Number(secondsInMessage[1]) : Number.isFinite(secondsInHeader) && secondsInHeader > 0 ? secondsInHeader : 60;
    await page.waitForTimeout(Math.min(65, Math.max(2, seconds + 2)) * 1_000);
  }
}
