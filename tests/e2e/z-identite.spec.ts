// V1 / lot P2 — une personne existante devient titulaire permanent sans nouvelle identité ; correction d'identité depuis la fiche.
// Données fictives de la recette uniquement ; la personne créée ici est propre à ce parcours.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
import { openMenu } from './menu';

type Account = { email: string; password: string; secret: string };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { admin: Account };

test.use({ actionTimeout: 15_000 });

test('Identité unique : profil permanent ajouté à une personne existante, puis correction du nom depuis la fiche', async ({ page }) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  const stamp = String(Date.now()).slice(-8);
  const first = 'Zzidentite';
  const last = `Fictive${stamp}`;

  // 1. Recherche puis création explicite d'une personne (aucun profil permanent)
  await page.goto('/administration');
  await openMenu(page, 'personnes');
  await page.getByTestId('ajouter-personne').click();
  const form = page.getByTestId('form-personne');
  await form.getByLabel('Prénom').fill(first);
  await form.getByLabel('Nom', { exact: true }).fill(last);
  await form.getByRole('button', { name: 'Enregistrer la personne' }).click();
  await expect(page.getByTestId('nom-personne')).toContainText(`${first} ${last}`);

  // 2. La personne existe : on la retrouve dans le registre et on ouvre sa fiche
  await page.goto(`/administration/personnes?q=${encodeURIComponent(last)}`);
  const row = page.locator('[data-testid^="selectionner-personne-"]').first();
  await row.click();
  await expect(page.getByTestId('fiche-personne')).toBeVisible();
  await expect(page.getByTestId('sans-profil-permanent')).toBeVisible();
  const before = (await page.getByTestId('kpi-personnes').innerText()).trim();

  // 3. Profil permanent rattaché à CETTE identité : aucune nouvelle personne
  await page.getByTestId('ajouter-profil-permanent').click();
  await expect(page.getByTestId('form-profil-permanent')).toContainText('aucune nouvelle personne');
  await page.getByTestId('form-profil-permanent').getByLabel('Fonction').fill('Formateur fictif');
  await page.screenshot({ path: 'test-results/identite-profil-formulaire.png', fullPage: true });
  await page.getByTestId('enregistrer-profil-permanent').click();
  await expect(page.getByTestId('fiche-identifiant')).toBeVisible();
  await expect(page.getByTestId('sans-profil-permanent')).toHaveCount(0);
  expect((await page.getByTestId('kpi-personnes').innerText()).trim()).toBe(before);
  await expect(page.locator('[data-testid^="selectionner-personne-"]')).toHaveCount(1);
  await page.screenshot({ path: 'test-results/identite-profil-ajoute.png', fullPage: true });

  // 3 bis. Habilitation permanente accordée puis retirée depuis la fiche (moteur permanent existant, motif obligatoire au retrait)
  await page.getByTestId('ptab-habilitations').click();
  await expect(page.getByTestId('accorder-permanente')).toBeVisible();
  await page.getByTestId('accorder-permanente').click();
  await page.getByTestId('perm-enregistrer').click();
  await expect(page.getByTestId('habilitations-permanentes')).toContainText('Origine : permanente');
  await expect(page.getByTestId('habilitations-permanentes')).toContainText('Active');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/identite-habilitation-accordee.png', fullPage: true });
  await page.locator('[data-testid^="action-"][data-testid$="-retirer"]').first().click();
  await page.locator('[data-testid^="motif-"]').first().fill('Fin de mission fictive');
  await page.locator('[data-testid^="confirmer-"]').first().click();
  await expect(page.getByTestId('habilitations-permanentes')).toContainText('Retirée');
  await expect(page.locator('[data-testid^="action-"]')).toHaveCount(0);
  expect((await page.getByTestId('kpi-personnes').innerText()).trim()).toBe(before);

  // 4. Correction d'identité : l'identité canonique est mise à jour et affichée
  await page.getByTestId('ptab-identite').click();
  await page.getByTestId('corriger-identite').click();
  const correction = page.getByTestId('form-correction');
  await correction.getByLabel('Nom', { exact: true }).fill(`${last}Corrigee`);
  await page.getByTestId('enregistrer-correction').click();
  await expect(page.getByTestId('nom-personne')).toContainText(`${last}Corrigee`);
  await expect(page.getByTestId('profil-permanent')).toBeVisible();
  expect((await page.getByTestId('kpi-personnes').innerText()).trim()).toBe(before);
  await page.screenshot({ path: 'test-results/identite-correction.png', fullPage: true });
});
