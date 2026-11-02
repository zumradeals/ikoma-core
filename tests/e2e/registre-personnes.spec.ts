import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
const admin = JSON.parse(readFileSync('.secrets/screen-titulaires.json', 'utf8')) as Account;
// Image fictive de 1 pixel : valide pour le contrôle serveur (JPEG/PNG), jamais une photo réelle.
const PIXEL = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

async function noHorizontalScroll(page: Page, label: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), label).toBe(true);
}

test('registre des personnes : prénom et nom, photo, import avec aperçu, sélection multiple, rien n’émet de carte (ordinateur et tablette)', async ({ page }) => {
  test.setTimeout(240_000);
  const stamp = Date.now();
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginWithSecondFactor(page, admin.email, admin.password, admin.secret);
  await page.goto('/administration/categories-institutionnelles');
  if (await page.getByTestId('initialiser-categories').isVisible()) await page.getByTestId('initialiser-categories').click();

  // Ajout individuel : prénom, nom, fonction et qualité distincts.
  await page.goto('/administration/titulaires');
  await page.getByTestId('bloc-creation').locator('summary').click();
  await page.getByTestId('creer-prenom').fill('Awa');
  await page.getByTestId('creer-nom').fill(`Registre${stamp}`);
  await page.getByTestId('creer-fonction').fill('Comptable fictive');
  await page.getByTestId('creer-qualite').fill('Stagiaire fictive');
  await page.getByTestId('enregistrer-titulaire').click();
  const card = page.locator('[data-testid^="titulaire-"]').filter({ hasText: `Awa Registre${stamp}` });
  await expect(card).toBeVisible();
  await expect(card).toContainText('Comptable fictive · Stagiaire fictive');
  await expect(card.getByTestId('etat-photo')).toHaveText('Pas de photo');

  // Photographie dans la fiche.
  await card.getByRole('button', { name: 'Modifier' }).click();
  await card.getByTestId('photo-fichier').setInputFiles({ name: 'portrait-fictif.png', mimeType: 'image/png', buffer: PIXEL });
  await card.getByTestId('photo-imprimable').check();
  await card.getByTestId('photo-enregistrer').click();
  await expect(card.getByTestId('etat-photo')).toHaveText('Photo prête pour la carte');
  await expect(card.locator('img')).toBeVisible();
  await page.screenshot({ path: 'test-results/r1-registre-1440.png', fullPage: true });

  // Import : une ligne valide, un doublon du registre, une ligne en erreur, un homonyme à confirmer.
  const csv = [
    'prenom;nom;organisation;fonction;qualite;categorie',
    `Bintou;Import${stamp};Organisation fictive;Archiviste;Détachée;`,
    `Awa;Registre${stamp};;;;`,
    `Erreur;Categorie${stamp};;;;Inconnue`,
    `Awa;Registre${stamp};Autre organisation fictive;;;`,
  ].join('\n');
  await page.goto('/administration/titulaires');
  await page.getByTestId('bloc-import').locator('summary').click();
  await page.getByTestId('import-fichier').setInputFiles({ name: 'personnes-fictives.csv', mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf8') });
  await page.getByTestId('import-apercu').click();
  await expect(page.getByTestId('import-resume')).toBeVisible();
  await expect(page.getByTestId('resume-ok')).toContainText('1 à enregistrer');
  await expect(page.getByTestId('resume-homonymes')).toContainText('1 à confirmer');
  await expect(page.getByTestId('resume-doublons')).toContainText('1 doublon');
  await expect(page.getByTestId('resume-erreurs')).toContainText('1 erreur');
  await expect(page.getByTestId('import-confirmer')).toContainText('Enregistrer 1 personne');
  await page.screenshot({ path: 'test-results/r1-import-apercu-1440.png', fullPage: true });
  await page.getByTestId('confirmer-ligne-5').check();
  await expect(page.getByTestId('import-confirmer')).toContainText('Enregistrer 2 personne');
  await page.getByTestId('import-confirmer').click();
  await expect(page.getByText('2 personne(s) enregistrée(s), 2 ligne(s) ignorée(s). Aucune carte n’a été créée.')).toBeVisible();

  // Sélection multiple : catégorie pour les personnes de cette série.
  await page.getByTestId('recherche-titulaires').fill(String(stamp));
  await page.getByTestId('lancer-recherche').click();
  await expect(page.getByTestId('total-titulaires')).toContainText('3 personne');
  await page.getByTestId('selectionner-tout').check();
  await expect(page.getByTestId('compteur-selection')).toContainText('3 sélectionnée');
  await page.getByTestId('groupe-categorie').selectOption('AILCT-02');
  await page.getByTestId('groupe-appliquer').click();
  await expect(page.getByText('3 personne(s) modifiée(s)')).toBeVisible();
  await expect(page.locator('[data-testid^="titulaire-"]').filter({ hasText: 'AILCT-02' })).toHaveCount(3);

  // Enregistrer n'a émis aucune carte : la fiche de carte propose encore de la préparer.
  await page.locator('[data-testid^="titulaire-"]').filter({ hasText: 'Stagiaire fictive' }).getByRole('link', { name: 'Carte' }).click();
  await expect(page.getByTestId('preparer-carte')).toBeVisible();
  await expect(page.getByTestId('aucun-tirage')).toHaveCount(0);

  // Tablette : pas de défilement horizontal.
  await page.setViewportSize({ width: 1024, height: 768 });
  await page.goto('/administration/titulaires?q=' + stamp);
  await noHorizontalScroll(page, 'registre tablette');
  await page.getByTestId('bloc-creation').locator('summary').click();
  await page.screenshot({ path: 'test-results/r1-registre-1024x768.png', fullPage: true });

  // Nettoyage : clôture de la série fictive (irréversible mais fictive).
  await page.getByTestId('selectionner-tout').check();
  await page.getByTestId('groupe-clore').click();
  await page.getByTestId('groupe-motif').fill('Fin fictive de la série de test');
  await page.getByTestId('groupe-confirmer').check();
  await page.getByTestId('groupe-valider').click();
  await expect(page.getByText('3 personne(s) close(s) définitivement')).toBeVisible();
});
