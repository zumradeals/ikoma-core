import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

test('fiche manuelle, portrait recadré et trois badges nominatifs avec et sans photo', async ({ page }) => {
  test.setTimeout(180_000);
  const user = JSON.parse(readFileSync('.secrets/screen-badges.json', 'utf8')) as { email: string; password: string; secret: string };
  await page.setViewportSize({ width: 1440, height: 1100 });
  await loginWithSecondFactor(page, user.email, user.password, user.secret);
  await page.goto('/administration/invites');
  await page.getByLabel('Événement', { exact: true }).selectOption({ label: 'Exercice fictif IKOMA — démonstration' });
  const name = 'Portrait fictif atelier ' + Date.now();
  await page.getByLabel('Nom complet', { exact: true }).fill(name);
  await page.getByLabel('Catégorie', { exact: true }).selectOption({ label: 'Visiteur' });
  await page.getByLabel('Organisation (facultatif)', { exact: true }).fill('Organisation de recette');
  const png = await page.evaluate(() => {
    const canvas = document.createElement('canvas'); canvas.width = 360; canvas.height = 480; const c = canvas.getContext('2d')!;
    c.fillStyle = '#e5eff6'; c.fillRect(0, 0, 360, 480); c.fillStyle = '#0B3C6B'; c.beginPath(); c.arc(180, 140, 70, 0, 2 * Math.PI); c.fill(); c.beginPath(); c.ellipse(180, 380, 140, 150, 0, 0, 2 * Math.PI); c.fill();
    return canvas.toDataURL('image/png').split(',')[1];
  });
  await page.getByLabel('Importer une photo', { exact: true }).setInputFiles({ name: 'portrait-fictif.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') });
  await expect(page.getByAltText('Aperçu du portrait')).toBeVisible();
  await page.getByLabel('Zoom du portrait').focus(); await page.getByLabel('Zoom du portrait').press('ArrowRight');
  await page.getByLabel('Autoriser l’utilisation de ce portrait', { exact: false }).check();
  await page.getByRole('button', { name: 'Enregistrer la fiche', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Fiche de la personne', exact: true })).toBeVisible();
  const person = new URL(page.url()).searchParams.get('visitor')!;
  await expect(page.getByAltText('Aperçu du portrait')).toBeVisible();
  await page.screenshot({ path: 'test-results/fiche-personne-portrait.png', fullPage: true });
  await page.goto('/administration/badges');
  const lots = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Lots et étiquettes' }) });
  const before = await lots.getByRole('link', { name: 'Imprimer les étiquettes' }).evaluateAll(links => links.map(a => a.getAttribute('href')));
  const generate = page.getByRole('form', { name: 'Générer un lot de badges' });
  await generate.getByLabel('Événement').selectOption({ label: 'Exercice fictif IKOMA — démonstration' }); await generate.getByLabel('Catégorie des badges').selectOption({ label: 'Visiteur' }); await generate.getByLabel('Nombre de personnes attendues').fill('1'); await generate.getByRole('button', { name: 'Générer le lot', exact: true }).click();
  await expect(lots.getByRole('link', { name: 'Imprimer les étiquettes' })).toHaveCount(before.length + 1);
  const link = (await lots.getByRole('link', { name: 'Imprimer les étiquettes' }).evaluateAll(links => links.map(a => a.getAttribute('href')!))).find(href => !before.includes(href))!;
  const batch = new URL(link, 'http://localhost').searchParams.get('batch')!;
  await page.goto('/administration/badges/modeles?person=' + person);
  await page.getByLabel('Lot', { exact: true }).selectOption(batch);
  await page.getByLabel('Au badge n°').fill(await page.getByLabel('Du badge n°').inputValue());
  await page.getByLabel('Mode de badge').selectOption('nominatif');
  await page.getByLabel('La politique du client autorise').check();
  await page.getByRole('button', { name: 'Charger les personnes de cette catégorie (200 max.)', exact: true }).click();
  await page.locator(`input[type="checkbox"][value="${person}"]`).check();
  await page.getByRole('button', { name: 'Réserver 1 destination(s) nominative(s)', exact: true }).click();
  await expect(page.getByText('1 destination(s) réservée(s).', { exact: false })).toBeVisible();
  for (const design of ['visiteur', 'vip', 'prestataire']) {
    await page.locator(`input[name="design"][value="${design}"]`).check();
    for (const orientation of ['paysage', 'portrait']) {
    await page.getByLabel('Orientation', { exact: true }).selectOption(orientation);
    for (const photo of [false, true]) {
      await page.getByLabel('Afficher le portrait de profil autorisé, si disponible').setChecked(photo);
      await page.getByRole('button', { name: 'Actualiser l’aperçu', exact: true }).click();
      await expect(page.locator('.access-card')).toContainText(name);
      await expect(page.locator('.access-card__portrait')).toHaveCount(photo ? 1 : 0);
      const qr = await page.locator('.access-card__qr').boundingBox(); const footer = await page.locator('.access-card__footer').boundingBox();
      expect(qr!.y + qr!.height).toBeLessThanOrEqual(footer!.y + .5);
      await page.locator('.access-card').screenshot({ path: `test-results/badge-nominatif-${design}-${orientation}-${photo ? 'photo' : 'sans-photo'}.png` });
    }
    }
  }
  await page.screenshot({ path: 'test-results/atelier-nominatif.png', fullPage: true });
  await page.getByLabel('Mode de badge').selectOption('reutilisable');
  await page.getByRole('button', { name: 'Actualiser l’aperçu', exact: true }).click();
  await expect(page.locator('.access-card')).not.toContainText(name); await expect(page.locator('.access-card__portrait')).toHaveCount(0);
});
