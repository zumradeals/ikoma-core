import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';
function otp(secret: string): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of secret) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes = []; for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hash = createHmac('sha1', Buffer.from(bytes)).update(counter).digest(); const offset = hash[19] & 15;
  return ((hash.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
test('un administrateur crée une zone puis une catégorie qui l’utilise', async ({ page }) => {
 const user = JSON.parse(readFileSync('.secrets/screen-account.json', 'utf8')) as { email: string; password: string; secret: string };
 await page.setViewportSize({ width: 820, height: 1180 });
 await page.goto('/login'); await page.getByLabel('Adresse électronique').fill(user.email); await page.getByLabel('Mot de passe', { exact: true }).fill(user.password); await page.getByRole('button', { name: 'Se connecter' }).click();
 await page.getByLabel('Code à six chiffres').fill(otp(user.secret)); await page.getByRole('button', { name: 'Vérifier', exact: true }).click();
 await page.getByRole('link', { name: 'Zones', exact: true }).click();
 const zone = `Zone fictive recette ${Date.now()}`; const zoneForm = page.getByRole('form', { name: 'Créer une zone' });
 await zoneForm.getByLabel('Nom de la zone').fill(zone); await zoneForm.getByRole('button', { name: 'Créer la zone', exact: true }).click();
 await expect(page.getByRole('heading', { name: zone, exact: true })).toBeVisible(); await page.screenshot({ path: 'test-results/l2-zones.png', fullPage: true });
 await page.goto('/administration/categories'); const category = `Catégorie fictive recette ${Date.now()}`; const form = page.getByRole('form', { name: 'Créer une catégorie' });
 await form.getByLabel('Nom de la catégorie').fill(category); await form.getByLabel(zone, { exact: true }).check(); await form.getByRole('button', { name: 'Créer la catégorie', exact: true }).click();
 await expect(page.getByText(category, { exact: true })).toBeVisible(); await page.reload();
 const card = page.locator('section').filter({ has: page.getByText(category, { exact: true }) }); await expect(card).toContainText(zone);
 await page.screenshot({ path: 'test-results/l2-categories.png', fullPage: true });
 await page.goto('/administration/badges'); await expect(page.getByRole('heading', { name: 'Administration · Badges' })).toBeVisible(); await expect(page.getByText('B-001', { exact: true })).toBeVisible(); await page.screenshot({ path: 'test-results/l2-badges.png', fullPage: true });
});
