import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
import jsQR from 'jsqr';
test('un administrateur génère un lot, réserve une plage à un poste et ouvre la page d’impression', async ({ page, baseURL }) => {
  test.setTimeout(180_000);

  const user = JSON.parse(readFileSync('.secrets/screen-badges.json', 'utf8')) as { email: string; password: string; secret: string };
  const origin = new URL(baseURL || 'http://localhost:8080').origin;
  await page.setViewportSize({ width: 1024, height: 1400 });
  await loginWithSecondFactor(page, user.email, user.password, user.secret);
  await page.getByRole('link', { name: 'Badges', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Administration · Badges' })).toBeVisible();

  // 1. Génération d'un lot de 20 badges « Visiteur » : 20 attendus + 10 % de marge = 22.
  const lotForm = page.getByRole('form', { name: 'Générer un lot de badges' });
  await lotForm.getByLabel('Événement').selectOption({ label: 'Exercice fictif IKOMA — démonstration' });
  await lotForm.getByLabel('Catégorie des badges').selectOption({ label: 'Visiteur' });
  await lotForm.getByLabel('Nombre de personnes attendues').fill('20');
  await expect(lotForm).toContainText('Badges à fabriquer : 22');
  await lotForm.getByRole('button', { name: 'Générer le lot', exact: true }).click();
  const lots = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Lots et étiquettes' }) });
  const lotLine = lots.locator('li').filter({ hasText: '22 badge(s) dont 2 de marge' }).filter({ hasText: 'Exercice fictif IKOMA' }).last();
  await expect(lotLine).toBeVisible();
  const lotName = (await lotLine.locator('strong').innerText()).trim();
  const lotLabel = `Exercice fictif IKOMA — démonstration · ${lotName}`; // le même nom de lot peut exister dans un autre événement
  const range = (await lotLine.innerText()).match(/n° (\d+) à (\d+)/);
  expect(range).not.toBeNull();
  const first = Number(range![1]);
  await page.screenshot({ path: 'test-results/l4-lot-genere.png', fullPage: true });

  // 2. Réservation des 5 premiers badges du lot au poste fictif.
  const reserve = page.getByRole('form', { name: 'Réserver des badges' });
  await reserve.getByLabel('Lot', { exact: true }).selectOption({ label: lotLabel });
  await reserve.getByLabel('Du badge n°').fill(String(first)); await reserve.getByLabel('Au badge n°').fill(String(first + 4));
  await reserve.locator('select').nth(1).selectOption({ label: 'Poste fictif A' });
  await reserve.getByRole('button', { name: 'Réserver la plage', exact: true }).click();
  const filter = page.getByRole('form', { name: 'Filtrer les badges' });
  await filter.getByLabel('Lot', { exact: true }).selectOption({ label: lotLabel });
  await filter.getByLabel('Réservation').selectOption('oui');
  await filter.getByRole('button', { name: 'Filtrer les badges', exact: true }).click();
  await expect(page.getByText('Poste réservé : Poste fictif A').first()).toBeVisible();
  await expect(page.locator('ol li').first()).toContainText(lotName);
  await expect(page.getByText('5 badge(s) correspondant aux filtres')).toBeVisible();
  await page.screenshot({ path: 'test-results/l4-reservation.png', fullPage: true });

  // 3. Page d'impression : aucune requête hors de notre origine, 22 étiquettes, QR décodables.
  const external: string[] = [];
  page.on('request', request => { const url = request.url(); if (!url.startsWith('data:') && new URL(url).origin !== origin) external.push(url); });
  await page.goto('/administration/badges');
  await page.locator('li').filter({ hasText: lotName }).filter({ hasText: 'Exercice fictif IKOMA' }).first().getByRole('link', { name: 'Imprimer les étiquettes' }).click();
  await expect(page.locator('article.label')).toHaveCount(22); // 3 colonnes × 5 lignes = 15 par feuille A4 : deux feuilles
  await expect(page.locator('.sheet')).toHaveCount(2);
  await page.getByLabel('Colonnes').selectOption('4'); await page.getByLabel('Lignes').selectOption('6');
  await page.getByRole('button', { name: 'Mettre à jour la page' }).click();
  await expect(page.locator('article.label')).toHaveCount(22);
  await expect(page.locator('.sheet')).toHaveCount(1);
  await expect(page.locator('article.label').first()).toContainText('Visiteur');
  await expect(page.locator('article.label').first()).toContainText(`B-${String(first).padStart(3, '0')}`);
  await page.screenshot({ path: 'test-results/l4-etiquettes.png', fullPage: true });
  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('.toolbar')).toBeHidden();
  await page.pdf({ path: 'test-results/l4-etiquettes.pdf', format: 'A4', printBackground: true });
  await page.emulateMedia({ media: 'screen' });
  expect(external).toEqual([]);

  // 4. Décodage automatique (jsQR, côté test) du QR rendu par le navigateur : « IKOMA1: » + jeton de 64 caractères hexadécimaux.
  // La page garde sa politique de sécurité stricte : aucun script n'y est injecté, seuls les pixels en sortent.
  const total = await page.locator('article.label .qr svg').count();
  const payloads: (string | null)[] = [];
  for (let index = 0; index < total; index++) {
    const encoded = await page.evaluate(async (position) => {
      const svg = document.querySelectorAll('article.label .qr svg')[position];
      const markup = new XMLSerializer().serializeToString(svg).replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"');
      const image = new Image(); image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup); await image.decode();
      const canvas = document.createElement('canvas'); canvas.width = 400; canvas.height = 400;
      const context = canvas.getContext('2d')!; context.fillStyle = '#fff'; context.fillRect(0, 0, 400, 400); context.drawImage(image, 0, 0, 400, 400);
      const pixels = context.getImageData(0, 0, 400, 400).data;
      let binary = '';
      for (let from = 0; from < pixels.length; from += 8192) binary += String.fromCharCode(...pixels.subarray(from, from + 8192));
      return btoa(binary);
    }, index);
    payloads.push(jsQR(new Uint8ClampedArray(Buffer.from(encoded, 'base64')), 400, 400)?.data ?? null);
  }
  expect(payloads).toHaveLength(22);
  for (const payload of payloads) expect(payload).toMatch(/^IKOMA1:[0-9a-f]{64}$/);
  expect(new Set(payloads).size).toBe(22);
});
