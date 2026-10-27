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
test('connexion, double authentification, comptes et journal', async ({ page }) => {
  const user = JSON.parse(readFileSync('.secrets/screen-account.json', 'utf8')) as { email: string; password: string; secret: string };
  await page.setViewportSize({ width: 820, height: 1180 });
  await page.goto('/login'); await page.screenshot({ path: 'test-results/connexion.png', fullPage: true });
  await page.getByLabel('Adresse électronique').fill(user.email); await page.getByLabel('Mot de passe', { exact: true }).fill(user.password); await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: 'Vérifier votre connexion' })).toBeVisible();
  await page.screenshot({ path: 'test-results/double-authentification.png', fullPage: true });
  await page.getByLabel('Code à six chiffres').fill(otp(user.secret)); await page.getByRole('button', { name: 'Vérifier', exact: true }).click();
  await page.getByRole('link', { name: 'Comptes', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Administration · Comptes' })).toBeVisible();
  await page.screenshot({ path: 'test-results/comptes.png', fullPage: true });
  await page.goto('/administration/journal'); await expect(page.getByRole('heading', { name: 'Administration · Journal' })).toBeVisible();
  await page.screenshot({ path: 'test-results/journal.png', fullPage: true });
});
