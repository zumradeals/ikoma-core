import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
import jsQR from 'jsqr';
import { fakePhoto } from './photo';

type Account = { email: string; password: string; secret: string };
const admin = JSON.parse(readFileSync('.secrets/screen-cartes.json', 'utf8')) as Account;

async function noHorizontalScroll(page: Page, label: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), label).toBe(true);
}

// Rasterise le QR (à 300 dpi, taille physique) puis le décode comme le ferait un lecteur.
async function decode(page: Page, scope: string): Promise<string | null> {
  const raster = await page.locator(`${scope} .hc__qr svg`).first().evaluate(async svg => {
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

const MM = 96 / 25.4;


test('cartes institutionnelles : préparation, aperçu recto/verso CR80, QR lisible, impression, réimpression et historique (ordinateur et tablette)', async ({ page, context }) => {
  test.setTimeout(300_000);
  const stamp = Date.now();
  const name = `Personne Cartes ${stamp}`;
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginWithSecondFactor(page, admin.email, admin.password, admin.secret);

  await page.goto('/administration/categories-institutionnelles');
  if (await page.getByTestId('initialiser-categories').isVisible()) await page.getByTestId('initialiser-categories').click();
  await expect(page.getByTestId('categorie-AILCT-08')).toBeVisible();

  // Huit modèles (un par catégorie), exemples fictifs.
  await page.goto('/administration/titulaires/modeles');
  await expect(page.getByTestId('page-modeles-cartes')).toBeVisible();
  await expect(page.getByTestId('choix-categorie').locator('option')).toHaveCount(8);
  await page.getByTestId('choix-categorie').selectOption('AILCT-04');
  await expect(page).toHaveURL(/categorie=AILCT-04/);
  await expect(page.getByTestId('modele-mention')).not.toHaveValue('');
  await expect(page.getByTestId('apercu-recto')).toBeVisible();
  // L'aperçu suit les réglages en direct.
  await page.getByTestId('modele-mention').fill('DÉLÉGATION OFFICIELLE');
  await page.getByTestId('modele-couleur').fill('#' + (0xc00000 + (stamp % 0x3fffff)).toString(16).padStart(6, '0'));
  await expect(page.getByTestId('apercu-recto')).toContainText('DÉLÉGATION OFFICIELLE');
  await page.getByTestId('enregistrer-modele').click();
  await expect(page.getByTestId('modele-etat')).toContainText('À valider');
  await page.getByTestId('valider-modele').click();
  await expect(page.getByTestId('modele-etat')).toContainText('Validé');
  await noHorizontalScroll(page, 'modèles 1440');
  await page.screenshot({ path: 'test-results/a05-modeles-1440.png', fullPage: true });

  // Titulaire fictif de catégorie AILCT-04.
  await page.goto('/administration/titulaires');
  if (await page.getByTestId('creer-contexte').isVisible()) { await page.getByTestId('creer-contexte').click(); await expect(page.getByTestId('contexte-permanent')).toContainText('en place'); }
  await page.getByText('Ajouter une personne').click();
  await page.getByTestId('creer-prenom').fill(name.split(' ')[0]);
  await page.getByTestId('creer-nom').fill(name.split(' ').slice(1).join(' '));
  await page.getByTestId('creer-categorie').selectOption('AILCT-04');
  await page.getByTestId('enregistrer-titulaire').click();
  await expect(page.getByText('Titulaire enregistré.')).toBeVisible();
  await page.getByTestId('recherche-titulaires').fill(name);
  await page.getByTestId('lancer-recherche').click();
  const row = page.locator('[data-testid^="titulaire-"]').filter({ hasText: name });
  await expect(row).toBeVisible();
  const identifier = (await row.getByTestId('identifiant-titulaire').innerText()).trim();

  // Parcours : titulaire → préparer → aperçu.
  await row.getByRole('link', { name: 'Carte' }).click();
  await expect(page.getByTestId('page-carte-titulaire')).toBeVisible();
  await expect(page.getByTestId('avis-acces')).toContainText('aucun droit d’accès');
  // La carte ne se prépare pas sans photo.
  await expect(page.getByTestId('preparer-carte')).toBeDisabled();
  await page.getByTestId('fichier-portrait').setInputFiles({ name: 'photo-fictive.png', mimeType: 'image/png', buffer: fakePhoto() });
  await page.getByTestId('envoyer-portrait').click();
  await expect(page.getByTestId('preparer-carte')).toBeEnabled();
  await page.getByTestId('preparer-carte').click();
  await expect(page.getByTestId('apercu-carte')).toBeVisible();
  await expect(page.getByTestId('aucun-tirage')).toBeVisible();

  // Aperçu agrandi pour la lecture ; dimensions physiques inchangées à l'impression.
  for (const side of ['recto', 'verso']) {
    const box = await page.locator(`[data-testid="apercu-${side}"] .hc`).boundingBox();
    expect(Math.abs(box!.width - 53.98 * MM * 1.6), `largeur agrandie ${side}`).toBeLessThan(1.5);
    expect(Math.abs(box!.height - 85.6 * MM * 1.6), `hauteur agrandie ${side}`).toBeLessThan(1.5);
  }
  await expect(page.getByTestId('apercu-recto')).toContainText(name);
  await expect(page.getByTestId('apercu-recto')).toContainText(identifier);
  await expect(page.getByTestId('apercu-recto')).toContainText('DÉLÉGATION OFFICIELLE');
  await expect(page.getByTestId('apercu-verso')).toContainText(identifier);
  const previewPayload = await decode(page, '[data-testid="apercu-verso"]');
  expect(previewPayload).toMatch(/^IKOMA1:[0-9a-f]{64}$/);
  await page.screenshot({ path: 'test-results/a05-carte-apercu-1440.png', fullPage: true });

  // Émission : le document s'ouvre dans un nouvel onglet ; recto puis verso, mêmes QR et identifiant.
  const [popup] = await Promise.all([context.waitForEvent('page'), page.getByTestId('generer-impression').click()]);
  await popup.waitForLoadState('domcontentloaded');
  await expect(popup.locator('.sheet')).toHaveCount(2);
  expect((await popup.locator('.sheet').first().boundingBox())!.width).toBeCloseTo(53.98 * MM * 1.6, 0);
  for (const selector of ['.hc', '.hc__institution', '.hc__name', '.hc__role', '.hc__mention', '.hc__line', '.hc__notice']) {
    for (const node of await popup.locator(selector).all()) {
      expect(await node.evaluate(t => t.scrollHeight <= t.clientHeight + 1 && t.scrollWidth <= t.clientWidth + 1), `contenu lisible ${selector}`).toBe(true);
    }
  }
  const nameTop = await popup.locator('.hc__name').evaluate(t => t.getBoundingClientRect().top);
  const photoBottom = await popup.locator('.hc__photo').evaluate(t => t.getBoundingClientRect().bottom);
  expect(nameTop - photoBottom).toBeGreaterThan(0);
  expect(await decode(popup, '.sheet:nth-of-type(2)')).toBe(previewPayload);
  await popup.emulateMedia({ media: 'print' });
  const printBox = await popup.locator('.sheet').first().boundingBox();
  expect(Math.abs(printBox!.width - 53.98 * MM)).toBeLessThan(1);
  expect(Math.abs(printBox!.height - 85.6 * MM)).toBeLessThan(1);
  const pdf = await popup.pdf({ preferCSSPageSize: true, printBackground: true });
  const text = pdf.toString('latin1');
  expect((text.match(/\/Type\s*\/Page[^s]/g) ?? []).length, 'deux pages : recto puis verso').toBe(2);
  const media = text.match(/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/);
  expect(Math.abs(Number(media![1]) - 53.98 * 72 / 25.4)).toBeLessThan(1);
  expect(Math.abs(Number(media![2]) - 85.6 * 72 / 25.4)).toBeLessThan(1);
  await popup.emulateMedia({ media: 'screen' });
  await popup.screenshot({ path: 'test-results/a05-carte-impression.png', fullPage: true });
  // Un texte trop long reste bloquant, même avec l'aperçu agrandi.
  await popup.locator('.hc__name').evaluate(t => { t.textContent = 'Nom fictif très long '.repeat(30); });
  await popup.getByRole('button', { name: 'Imprimer', exact: true }).click();
  await expect(popup.locator('#layout-error')).toBeVisible();
  await popup.close();

  // Réimpression : motif obligatoire, même QR, historique.
  await page.reload();
  await expect(page.getByTestId('historique-tirages')).toContainText('Émission');
  await page.getByTestId('motif-reimpression').fill('Carte abîmée (essai)');
  const [again] = await Promise.all([context.waitForEvent('page'), page.getByTestId('generer-impression').click()]);
  await again.waitForLoadState('domcontentloaded');
  expect(await decode(again, '.sheet:nth-of-type(2)')).toBe(previewPayload);
  await again.close();
  await page.reload();
  await expect(page.getByTestId('historique-tirages').locator('li')).toHaveCount(2);
  await expect(page.getByTestId('historique-tirages')).toContainText('Réimpression');
  expect(await decode(page, '[data-testid="apercu-verso"]')).toBe(previewPayload);

  // Atelier, mode « Cartes permanentes » : choix de la personne, motif obligatoire (déjà imprimée), document tracé.
  await page.goto('/administration/atelier?mode=cartes');
  await expect(page.getByTestId('cartes-permanentes')).toBeVisible();
  await page.getByTestId(`carte-${identifier}`).check();
  await expect(page.getByTestId('motif-cartes')).toBeVisible();
  await page.getByTestId('motif-cartes').fill('Essai depuis l’Atelier');
  const [atelierDoc] = await Promise.all([context.waitForEvent('page'), page.getByTestId('imprimer-cartes').click()]);
  await atelierDoc.waitForLoadState('domcontentloaded');
  await expect(atelierDoc.locator('.sheet')).toHaveCount(2);
  await atelierDoc.close();
  await page.screenshot({ path: 'test-results/r5b-atelier-cartes-1440.png', fullPage: true });
  await page.goto('/administration/titulaires');
  await page.getByTestId('recherche-titulaires').fill(name);
  await page.getByTestId('lancer-recherche').click();
  await page.locator('[data-testid^="titulaire-"]').filter({ hasText: name }).getByRole('link', { name: 'Carte' }).click();

  // Remise : tant que la carte n'est pas remise et activée, elle n'ouvre rien.
  await expect(page.getByTestId('activer-carte')).toBeDisabled();
  await page.getByTestId('confirmer-remise').check();
  await page.getByTestId('activer-carte').click();
  await expect(page.getByTestId('carte-activee')).toBeVisible();

  // Page d'essai d'alignement : deux pages CR80, aucun QR.
  const align = await context.newPage();
  await align.goto('/administration/titulaires/alignement');
  await expect(align.locator('.align')).toHaveCount(2);
  expect(await align.locator('svg').count()).toBe(0);
  const alignPdf = (await align.pdf({ preferCSSPageSize: true, printBackground: true })).toString('latin1');
  expect((alignPdf.match(/\/Type\s*\/Page[^s]/g) ?? []).length).toBe(2);
  await align.close();

  // Tablette.
  await page.setViewportSize({ width: 1024, height: 768 });
  await page.reload();
  await expect(page.getByTestId('apercu-recto')).toBeVisible();
  await noHorizontalScroll(page, 'carte 1024');
  await page.screenshot({ path: 'test-results/a05-carte-apercu-1024.png', fullPage: true });
  await page.goto('/administration/titulaires/modeles');
  await noHorizontalScroll(page, 'modèles 1024');
  await page.screenshot({ path: 'test-results/a05-modeles-1024.png', fullPage: true });
});
