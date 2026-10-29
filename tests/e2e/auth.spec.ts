import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
test('connexion, double authentification, comptes et journal', async ({ page }) => {
  test.setTimeout(120_000);
  const user = JSON.parse(readFileSync('.secrets/screen-account.json', 'utf8')) as { email: string; password: string; secret: string };
  await page.setViewportSize({ width: 820, height: 1180 });
  await page.goto('/login'); await page.screenshot({ path: 'test-results/connexion.png', fullPage: true });
  await loginWithSecondFactor(page, user.email, user.password, user.secret, async challengePage => {
    await expect(challengePage.getByRole('heading', { name: 'Vérifier votre connexion' })).toBeVisible();
    await challengePage.screenshot({ path: 'test-results/double-authentification.png', fullPage: true });
  });
  await page.getByTestId('ouvrir-menu').click();
 await page.getByRole('link', { name: 'Comptes', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Administration · Comptes' })).toBeVisible();
  await page.screenshot({ path: 'test-results/comptes.png', fullPage: true });
  await page.goto('/administration/journal'); await expect(page.getByRole('heading', { name: 'Administration · Journal' })).toBeVisible();
  await page.screenshot({ path: 'test-results/journal.png', fullPage: true });
});
