import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
test('un administrateur crée une zone puis une catégorie qui l’utilise', async ({ page }) => {
 test.setTimeout(180_000); // inclut toute attente temporaire imposée par la connexion

 const user = JSON.parse(readFileSync('.secrets/screen-business.json', 'utf8')) as { email: string; password: string; secret: string };
 await page.setViewportSize({ width: 820, height: 1180 });
 await loginWithSecondFactor(page, user.email, user.password, user.secret);
 await page.getByTestId('ouvrir-menu').click();
 await page.getByRole('link', { name: 'Zones', exact: true }).click();
 const zone = `Zone fictive recette ${Date.now()}`; const zoneForm = page.getByRole('form', { name: 'Créer une zone' });
 await zoneForm.getByLabel('Nom de la zone').fill(zone); await zoneForm.getByRole('button', { name: 'Créer la zone', exact: true }).click();
 await expect(page.getByRole('heading', { name: zone, exact: true })).toBeVisible(); await page.screenshot({ path: 'test-results/l2-zones.png', fullPage: true });
 await page.goto('/administration/categories'); const category = `Catégorie fictive recette ${Date.now()}`; const form = page.getByRole('form', { name: 'Créer une catégorie' });
 await form.getByLabel('Nom de la catégorie').fill(category); await form.getByLabel(zone, { exact: true }).check(); await form.getByRole('button', { name: 'Créer la catégorie', exact: true }).click();
 await expect(page.getByText(category, { exact: true })).toBeVisible(); await page.reload();
 const card = page.locator('section').filter({ has: page.getByText(category, { exact: true }) }); await expect(card).toContainText(zone);
 await page.screenshot({ path: 'test-results/l2-categories.png', fullPage: true });
 await page.goto('/administration/badges'); await expect(page.getByRole('heading', { name: 'Administration · Badges' })).toBeVisible(); await expect(page.getByText('B-001', { exact: true }).first()).toBeVisible(); await page.screenshot({ path: 'test-results/l2-badges.png', fullPage: true });
});
