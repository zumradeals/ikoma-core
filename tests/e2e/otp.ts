import { expect, type Page } from '@playwright/test';

/**
 * Legacy public helper name retained for existing E2E imports.
 * 2FA is disabled: authentication now completes after the valid password.
 * Rate limiting and account/role checks remain in effect.
 */
export async function loginWithSecondFactor(
  page: Page,
  email: string,
  password: string,
  _legacySecret: string,
  _onChallenge?: () => Promise<void>,
  onStage?: (stage: string) => void,
): Promise<void> {
  for (let attempt = 0; attempt < 2; attempt++) {
    onStage?.('login.open');
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Se connecter', exact: true })).toBeVisible({ timeout: 12_000 });
    const field = page.getByLabel('Adresse électronique');
    await expect(field).toBeVisible();
    await field.fill(email);
    await page.getByLabel('Mot de passe', { exact: true }).fill(password);
    onStage?.('login.submit');
    const [response] = await Promise.all([
      page.waitForResponse(r => new URL(r.url()).pathname === '/login' && r.request().method() === 'POST'),
      page.getByRole('button', { name: 'Se connecter' }).click(),
    ]);
    if (response.status() !== 429) {
      await expect(page).toHaveURL(/\/(administration|espace|pilotage)$/, { timeout: 15_000 });
      await expect(page.getByLabel('Code à six chiffres')).toHaveCount(0);
      onStage?.('authenticated');
      return;
    }
    if (attempt === 1) throw new Error('La limitation de connexion demeure active : deux tentatives épuisées.');
    const retry = Number(response.headers()['retry-after']);
    await page.waitForTimeout(Math.min(65, Math.max(2, Number.isFinite(retry) && retry > 0 ? retry + 2 : 62)) * 1000);
  }
}
