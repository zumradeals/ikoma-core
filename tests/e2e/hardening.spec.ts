import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { freshOtp } from './otp';

test('CSP : aucun script injecté, caméra autorisée au poste, coquille réouverte hors ligne', async ({ page, context }) => {
  const violations: { directive: string; blocked: string }[] = [];
  await page.exposeFunction('recordCsp', (value: { directive: string; blocked: string }) => { violations.push(value); });
  await page.addInitScript(() => document.addEventListener('securitypolicyviolation', event => {
    void (window as unknown as { recordCsp: (value: object) => Promise<void> }).recordCsp({ directive: event.violatedDirective, blocked: event.blockedURI });
  }));
  const response = await page.goto('/poste/shell');
  expect(response!.headers()['content-security-policy']).toContain("script-src 'self'");
  expect(response!.headers()['permissions-policy']).toContain('camera=(self)');
  await page.evaluate(async () => {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    stream.getTracks().forEach(track => track.stop());
    await navigator.serviceWorker.ready;
    if (!navigator.serviceWorker.controller) await new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true }));
  });
  expect(violations).toEqual([]);
  await page.evaluate(() => { const script = document.createElement('script'); script.textContent = 'window.injectionInterdite = true'; document.head.append(script); });
  expect(await page.evaluate(() => (window as unknown as { injectionInterdite?: boolean }).injectionInterdite)).toBeUndefined();
  await expect.poll(() => violations.some(v => v.directive.startsWith('script-src'))).toBe(true);
  violations.length = 0;
  await context.setOffline(true);
  await page.reload();
  await expect(page.getByTestId('ecran-enrolement')).toBeVisible();
  await page.evaluate(async () => { const stream = await navigator.mediaDevices.getUserMedia({ video: true }); stream.getTracks().forEach(track => track.stop()); });
  expect(violations).toEqual([]);
  await context.setOffline(false);
  const login = await page.goto('/login');
  expect(login!.headers()['permissions-policy']).toContain('camera=()');
  expect(await page.evaluate(async () => { try { await navigator.mediaDevices.getUserMedia({ video: true }); return true; } catch { return false; } })).toBe(false);
});

test('administrateur : politique de conservation proposée et export daté du journal sans nom', async ({ page }) => {
  const fx = JSON.parse(readFileSync('.secrets/screen-account.json', 'utf8')) as { email: string; password: string; secret: string };
  await page.goto('/login');
  await page.getByLabel('Adresse électronique').fill(fx.email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(fx.password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.getByLabel('Code à six chiffres').fill(await freshOtp(fx.secret));
  await page.getByRole('button', { name: 'Vérifier', exact: true }).click();
  await page.getByRole('link', { name: 'Conservation des données' }).click();
  await expect(page.getByRole('heading', { name: 'Conservation des données' })).toBeVisible();
  await expect(page.getByLabel(/Le client a approuvé/)).not.toBeChecked();
  await page.screenshot({ path: 'test-results/l11-conservation.png', fullPage: true });
  await page.goto('/administration/journal');
  const day = new Date().toISOString().slice(0, 10);
  await page.getByLabel('Du', { exact: true }).fill(day);
  await page.getByLabel('Au', { exact: true }).fill(day);
  const download = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Télécharger le CSV' }).click();
  const file = await download;
  expect(file.suggestedFilename()).toBe('journal-ikoma.csv');
  const stream = await file.createReadStream();
  let csv = ''; for await (const chunk of stream) csv += chunk.toString();
  expect(csv).toContain('Acteur (identifiant)');
  expect(csv).not.toContain(fx.email);
  expect(csv).not.toContain(fx.password);
});
