import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
import jsQR from 'jsqr';

async function decodeBadge(page: Page): Promise<string | null> {
  // Rasterisation à la taille physique du QR à 300 dpi (pas d’agrandissement arbitraire).
  const raster = await page.locator('.access-card__qr svg').first().evaluate(async svg => {
    const size = Math.round(svg.getBoundingClientRect().width / 96 * 300);
    const markup = new XMLSerializer().serializeToString(svg).replace('<svg', `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}"`);
    const image = new Image(); image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup); await image.decode();
    const canvas = document.createElement('canvas'); canvas.width = size; canvas.height = size;
    const context = canvas.getContext('2d')!; context.drawImage(image, 0, 0, size, size);
    const pixels = context.getImageData(0, 0, size, size).data;
    let binary = ''; for (let offset = 0; offset < pixels.length; offset += 8192) binary += String.fromCharCode(...pixels.subarray(offset, offset + 8192));
    return { size, encoded: btoa(binary) };
  });
  return jsQR(new Uint8ClampedArray(Buffer.from(raster.encoded, 'base64')), raster.size, raster.size)?.data ?? null;
}

test('atelier : modèles, lot de 200, QR inchangé, CR80, A4 et tablette', async ({ page, context }) => {
  test.setTimeout(180_000);
  const user = JSON.parse(readFileSync('.secrets/screen-badges.json', 'utf8')) as { email: string; password: string; secret: string };
  await page.setViewportSize({ width: 1440, height: 1100 });
  await loginWithSecondFactor(page, user.email, user.password, user.secret);
  await page.goto('/administration/badges');
  const lots = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Lots et étiquettes' }) });
  const before = await lots.getByRole('link', { name: 'Imprimer les étiquettes' }).evaluateAll(links => links.map(a => a.getAttribute('href')));
  const generate = page.getByRole('form', { name: 'Générer un lot de badges' });
  await generate.getByLabel('Événement').selectOption({ label: 'Exercice fictif IKOMA — démonstration' });
  await generate.getByLabel('Catégorie des badges').selectOption({ label: 'Visiteur' });
  await generate.getByLabel('Nombre de personnes attendues').fill('200');
  await generate.getByRole('button', { name: 'Générer le lot', exact: true }).click();
  await expect(lots.getByRole('link', { name: 'Imprimer les étiquettes' })).toHaveCount(before.length + 1);
  const links = await lots.getByRole('link', { name: 'Imprimer les étiquettes' }).evaluateAll(anchors => anchors.map(a => a.getAttribute('href')!));
  const link = links.find(href => !before.includes(href))!;
  const batch = new URL(link, 'http://localhost').searchParams.get('batch')!;
  // Le QR de référence vient de l’ancien moteur d’impression, sans réémission du jeton.
  await page.goto(link);
  const referenceSvg = await page.locator('.label .qr svg').first().getAttribute('viewBox');
  const referencePath = await page.locator('.label .qr svg path').first().getAttribute('d');
  const firstCode = await page.locator('.label .code').first().innerText();
  const first = Number(firstCode.slice(2));
  await page.goto('/administration/badges/modeles');
  await page.getByLabel('Événement', { exact: true }).selectOption({ label: 'Exercice fictif IKOMA — démonstration' });
  await page.getByLabel('Lot', { exact: true }).selectOption(batch);
  await page.getByLabel('Du badge n°').fill(String(first)); await page.getByLabel('Au badge n°').fill(String(first + 199));
  await page.getByRole('button', { name: 'Actualiser l’aperçu' }).click();
  await expect(page.locator('.access-card')).toContainText(firstCode);
  await expect(page.locator('.workshop-preview')).toContainText('200 badge(s)');
  await page.screenshot({ path: 'test-results/atelier-badges.png', fullPage: true });
  let referencePayload: string | null = null;
  for (const model of ['visiteur', 'vip', 'prestataire']) {
    await page.locator(`input[name="design"][value="${model}"]`).check();
    await page.getByRole('button', { name: 'Actualiser l’aperçu' }).click();
    await expect(page.locator(`.access-card--${model}`)).toBeVisible();
    expect(await page.locator('.access-card__qr svg').getAttribute('viewBox')).toBe(referenceSvg);
    expect(await page.locator('.access-card__qr svg path').getAttribute('d')).toBe(referencePath);
    const payload = await decodeBadge(page);
    expect(payload).toMatch(/^IKOMA1:[0-9a-f]{64}$/);
    referencePayload ??= payload; expect(payload).toBe(referencePayload);
    await page.locator('.access-card').screenshot({ path: `test-results/badge-${model}.png` });
  }
  // Un seul badge PVC en portrait : dimensions du DOM et du PDF contrôlables.
  await page.getByLabel('Au badge n°').fill(String(first));
  await page.getByLabel('Orientation', { exact: true }).selectOption('portrait');
  await page.getByRole('button', { name: 'Actualiser l’aperçu' }).click();
  await expect(page.locator('.access-card--portrait')).toBeVisible();
  expect(await decodeBadge(page)).toBe(referencePayload);
  const box = await page.locator('.access-card').boundingBox();
  expect(box!.width / 96 * 25.4).toBeCloseTo(53.98, 1); expect(box!.height / 96 * 25.4).toBeCloseTo(85.60, 1);
  const pvcUrl = await page.getByTestId('preparer-impression').getAttribute('href');
  const print = await context.newPage(); await print.goto(pvcUrl!);
  await expect(print.locator('.access-card')).toHaveCount(1);
  const pvcPdf = await print.pdf({ path: 'test-results/badge-cr80-portrait.pdf', preferCSSPageSize: true, printBackground: true });
  expect(pvcPdf.toString('latin1').match(/\/Type\s*\/Page\b/g)).toHaveLength(1);
  // Les 200 badges A4 : 2 colonnes × 5 lignes, 20 pages, sans changement d’échelle.
  await page.getByLabel('Au badge n°').fill(String(first + 199));
  await page.getByLabel('Orientation', { exact: true }).selectOption('paysage');
  await page.getByLabel('Format', { exact: true }).selectOption('a4');
  await page.getByRole('button', { name: 'Actualiser l’aperçu' }).click();
  await expect(page.getByTestId('preparer-impression')).toBeVisible();
  await print.goto((await page.getByTestId('preparer-impression').getAttribute('href'))!);
  await expect(print.locator('.access-card')).toHaveCount(200); await expect(print.locator('.sheet')).toHaveCount(20);
  expect(await decodeBadge(print)).toBe(referencePayload);
  await print.emulateMedia({ media: 'print' }); await expect(print.locator('.toolbar')).toBeHidden();
  const a4Pdf = await print.pdf({ path: 'test-results/badges-200-a4.pdf', preferCSSPageSize: true, printBackground: true });
  expect(a4Pdf.toString('latin1').match(/\/Type\s*\/Page\b/g)).toHaveLength(20);
  await print.emulateMedia({ media: 'screen' });
  await print.evaluate(() => { window.print = () => { document.documentElement.dataset.printCalled = 'yes'; }; });
  await print.getByRole('button', { name: 'Imprimer les badges', exact: true }).click();
  await expect(print.locator('html')).toHaveAttribute('data-print-called', 'yes');
  await print.locator('.access-card__category').first().evaluate(node => { node.textContent = 'W'.repeat(400); document.documentElement.removeAttribute('data-print-called'); });
  await print.getByRole('button', { name: 'Imprimer les badges', exact: true }).click();
  await expect(print.locator('#layout-error')).toBeVisible();
  await expect(print.locator('html')).not.toHaveAttribute('data-print-called');
  await print.close();
  // Aucun débordement horizontal sur tablette, et les paramètres restent accessibles.
  await page.setViewportSize({ width: 820, height: 1180 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/atelier-badges-tablette.png', fullPage: true });
  // Le verso ne contient aucun QR ni fiche titulaire.
  await page.getByLabel('Face à imprimer').selectOption('verso'); await page.getByRole('button', { name: 'Actualiser l’aperçu' }).click();
  await expect(page.locator('.access-card')).toContainText('Consignes de visite'); await expect(page.locator('.access-card__qr')).toHaveCount(0);
});
