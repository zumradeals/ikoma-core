import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

test('centre de gestion, analyse refusée et archives sur données fictives', async ({ page }) => {
  test.setTimeout(120_000);
  const user = JSON.parse(readFileSync('.secrets/screen-badges.json', 'utf8')) as { email: string; password: string; secret: string };
  await page.setViewportSize({ width: 1440, height: 1000 });
  await loginWithSecondFactor(page, user.email, user.password, user.secret);
  await page.goto('/administration/donnees');
  await expect(page.getByRole('heading', { name: 'Gestion des données', exact: true })).toBeVisible();
  await expect(page.getByText('Une demande en attente n’est pas une opération terminée.', { exact: false })).toBeVisible();
  await page.screenshot({ path: 'test-results/l33-gestion-donnees.png', fullPage: true });
  await page.goto('/administration/evenements');
  const analyse = page.getByRole('link', { name: 'Analyser une suppression' }).first();
  await page.locator('details').first().getByText('Actions', { exact: true }).click();
  await analyse.click();
  await expect(page.getByRole('heading', { name: 'Analyse avant suppression', exact: false })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Confirmer la suppression définitive' })).toHaveCount(0);
  await page.screenshot({ path: 'test-results/l33-suppression-refusee.png', fullPage: true });
  await page.goto('/administration/evenements?archives=1');
  await expect(page.getByRole('link', { name: 'Voir les événements opérationnels' })).toBeVisible();
  await page.screenshot({ path: 'test-results/l33-archives.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/administration/donnees');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/l33-gestion-mobile.png', fullPage: true });
});
