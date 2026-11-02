import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
import jsQR from 'jsqr';

async function decodeBadge(page: Page, scope = ''): Promise<string | null> {
  // Rasterisation à la taille physique du QR à 300 dpi (pas d’agrandissement arbitraire).
  const raster = await page.locator(`${scope}.access-card__qr svg`).first().evaluate(async svg => {
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

test('Atelier des badges : exemple fictif, badge réel, PVC, A4, étiquettes, trace en trois états, QR inchangé et tablette', async ({ page, context }) => {
  test.setTimeout(240_000);
  const user = JSON.parse(readFileSync('.secrets/screen-badges.json', 'utf8')) as { email: string; password: string; secret: string };
  await page.setViewportSize({ width: 1440, height: 1100 });
  await loginWithSecondFactor(page, user.email, user.password, user.secret);

  // Un lot de 200 badges : le stock se gère toujours dans la page Badges.
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
  expect(link).toContain('/administration/atelier?format=etiquettes&batch=');
  const batch = new URL(link, 'http://localhost').searchParams.get('batch')!;

  // L'ancien lien des étiquettes redirige vers l'Atelier ; l'ancienne page de l'atelier aussi.
  await page.goto('/administration/badges/etiquettes?batch=' + batch + '&cols=3&rows=5');
  await expect(page).toHaveURL(/\/administration\/atelier\?.*format=etiquettes/);
  await page.goto('/administration/badges/modeles');
  await expect(page).toHaveURL(/\/administration\/atelier$/);
  await expect(page.getByTestId('page-atelier')).toBeVisible();

  // 1. Aperçu d'un modèle : exemple fictif, jamais de vrai QR ni de marquage du lot.
  await page.goto(link);
  await expect(page.getByTestId('exemple-recto')).toContainText('EXEMPLE · SANS VALEUR');
  await expect(page.getByTestId('exemple-verso')).toContainText('Consignes de visite');
  await expect(page.getByTestId('badge-reel')).toHaveCount(0);
  // Le QR de l'exemple est un QR d'exemple (jeton nul) : il ne correspond à aucun badge.
  expect(await decodeBadge(page, '[data-testid="exemple-recto"] ')).toBe('IKOMA1:' + '0'.repeat(64));

  // 2. Étiquettes A4 : document généré (aucune impression), puis demande et confirmation par l'opérateur.
  await page.getByTestId('choix-evenement').selectOption({ label: 'Exercice fictif IKOMA — démonstration' });
  await page.getByTestId('choix-lot').selectOption(batch);
  await page.getByTestId('choix-format').selectOption('etiquettes');
  await page.getByTestId('generer-document').click();
  await expect(page.getByTestId('document-pret')).toContainText('Rien n’est encore imprimé');
  const labelsUrl = (await page.getByTestId('ouvrir-document').getAttribute('href'))!;
  const labels = await context.newPage(); await labels.goto(labelsUrl);
  const referenceSvg = await labels.locator('.label .qr svg').first().getAttribute('viewBox');
  const referencePath = await labels.locator('.label .qr svg path').first().getAttribute('d');
  const firstCode = await labels.locator('.label .code').first().innerText();
  const first = Number(firstCode.slice(2));
  await expect(labels.locator('#trace-state')).toContainText('Document généré');
  await labels.evaluate(() => { window.print = () => { document.documentElement.dataset.printCalled = 'yes'; }; });
  await labels.locator('[data-print]').click();
  await expect(labels.locator('html')).toHaveAttribute('data-print-called', 'yes');
  await expect(labels.locator('#trace-state')).toContainText('Impression demandée');
  await expect(labels.locator('#trace')).toBeVisible();
  await labels.locator('#trace-printed').fill('198'); await labels.locator('#trace-spoiled').fill('2');
  await labels.locator('#trace-confirm').click();
  await expect(labels.locator('#trace-message')).toContainText('Impression confirmée');
  await labels.close();
  await page.reload();
  const runLine = page.locator('[data-testid^="tirage-"]').first();
  await expect(runLine.getByTestId('etat-tirage')).toContainText('Impression confirmée par l’opérateur : 198 imprimé(s), 2 gâché(s)');

  // 3. Aperçu d'un badge réel (vrai QR) : même QR que les étiquettes, pour les trois modèles.
  await page.getByTestId('choix-format').selectOption('pvc');
  await page.getByTestId('plage-debut').fill(String(first)); await page.getByTestId('plage-fin').fill(String(first + 199));
  await page.getByTestId('afficher-reel').click();
  await expect(page.getByTestId('badge-reel')).toContainText(firstCode);
  await expect(page.getByTestId('apercu-reel')).toContainText('200 badge(s)');
  await page.screenshot({ path: 'test-results/atelier-badges.png', fullPage: true });
  let referencePayload: string | null = null;
  for (const model of ['visiteur', 'vip', 'prestataire']) {
    await page.locator(`input[name="design"][value="${model}"]`).check();
    await expect(page.getByTestId('exemple-recto').locator(`.access-card--${model}`)).toBeVisible();
    await page.getByTestId('afficher-reel').click();
    await expect(page.getByTestId('badge-reel').locator(`.access-card--${model}`)).toBeVisible();
    const real = page.getByTestId('badge-reel');
    expect(await real.locator('.access-card__qr svg').getAttribute('viewBox')).toBe(referenceSvg);
    expect(await real.locator('.access-card__qr svg path').getAttribute('d')).toBe(referencePath);
    const payload = await decodeBadge(page, '[data-testid="badge-reel"] ');
    expect(payload).toMatch(/^IKOMA1:[0-9a-f]{64}$/);
    referencePayload ??= payload; expect(payload).toBe(referencePayload);
  }

  // 4. PVC CR80 portrait : un badge, dimensions physiques, PDF d'une page, jamais présenté comme une impression.
  await page.getByTestId('plage-fin').fill(String(first));
  await page.getByRole('combobox', { name: 'Orientation', exact: true }).selectOption('portrait');
  await page.getByTestId('afficher-reel').click();
  await expect(page.getByTestId('badge-reel').locator('.access-card--portrait')).toBeVisible();
  const box = await page.getByTestId('badge-reel').locator('.access-card').boundingBox();
  // L'aperçu est agrandi à l'écran (zoom) : le tirage, lui, garde la taille réelle (vérifiée sur la page d'impression).
  expect(box!.width).toBeGreaterThan(0);
  await page.getByTestId('generer-document').click();
  const pvc = await context.newPage(); await pvc.goto((await page.getByTestId('ouvrir-document').getAttribute('href'))!);
  await expect(pvc.locator('.access-card')).toHaveCount(1);
  const real = await pvc.locator('.access-card').boundingBox();
  expect(real!.width / 96 * 25.4).toBeCloseTo(53.98, 1); expect(real!.height / 96 * 25.4).toBeCloseTo(85.60, 1);
  expect(await decodeBadge(pvc)).toBe(referencePayload);
  const pvcPdf = await pvc.pdf({ path: 'test-results/badge-cr80-portrait.pdf', preferCSSPageSize: true, printBackground: true });
  expect(pvcPdf.toString('latin1').match(/\/Type\s*\/Page\b/g)).toHaveLength(1);
  await pvc.close();

  // 5. Les 200 badges A4 : 2 colonnes × 5 lignes, 20 pages, sans changement d'échelle ; contrôle de débordement conservé.
  await page.getByTestId('plage-fin').fill(String(first + 199));
  await page.getByRole('combobox', { name: 'Orientation', exact: true }).selectOption('paysage');
  await page.getByTestId('choix-format').selectOption('a4');
  await page.getByTestId('generer-document').click();
  const a4 = await context.newPage(); await a4.goto((await page.getByTestId('ouvrir-document').getAttribute('href'))!);
  await expect(a4.locator('.access-card')).toHaveCount(200); await expect(a4.locator('.sheet')).toHaveCount(20);
  expect(await decodeBadge(a4)).toBe(referencePayload);
  await a4.emulateMedia({ media: 'print' }); await expect(a4.locator('.toolbar')).toBeHidden(); await expect(a4.locator('#trace-state')).toBeHidden();
  const a4Pdf = await a4.pdf({ path: 'test-results/badges-200-a4.pdf', preferCSSPageSize: true, printBackground: true });
  expect(a4Pdf.toString('latin1').match(/\/Type\s*\/Page\b/g)).toHaveLength(20);
  await a4.emulateMedia({ media: 'screen' });
  await a4.evaluate(() => { window.print = () => { document.documentElement.dataset.printCalled = 'yes'; }; });
  await a4.locator('[data-print]').click();
  await expect(a4.locator('html')).toHaveAttribute('data-print-called', 'yes');
  await a4.locator('.access-card__category').first().evaluate(node => { node.textContent = 'W'.repeat(400); document.documentElement.removeAttribute('data-print-called'); });
  await a4.locator('[data-print]').click();
  await expect(a4.locator('#layout-error')).toBeVisible();
  await expect(a4.locator('html')).not.toHaveAttribute('data-print-called');
  await a4.close();

  // 6. Essai marqué, page d'alignement commune et trace complète dans l'historique.
  await page.getByTestId('generer-essai').click();
  await expect(page.getByTestId('document-pret')).toContainText('Essai généré');
  const trial = await context.newPage(); await trial.goto((await page.getByTestId('ouvrir-document').getAttribute('href'))!);
  await expect(trial.locator('.trace-essai')).toContainText('ESSAI');
  await expect(trial.locator('.access-card__specimen').first()).toContainText('EXEMPLE · SANS VALEUR');
  await trial.close();
  const alignment = await context.newPage(); await alignment.goto((await page.getByTestId('lien-alignement-atelier').getAttribute('href'))!);
  await expect(alignment.locator('.toolbar')).toContainText('aucun QR'); await expect(alignment.locator('svg')).toHaveCount(0);
  await alignment.close();
  await page.reload();
  await expect(page.getByTestId('suivi-tirages')).toContainText('ESSAI');
  await expect(page.getByTestId('suivi-tirages')).toContainText('Document généré (pas encore imprimé)');

  // 7. Aucun débordement horizontal sur tablette.
  await page.setViewportSize({ width: 820, height: 1180 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/atelier-badges-tablette.png', fullPage: true });
});
