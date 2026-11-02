import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
const admin = JSON.parse(readFileSync('.secrets/screen-titulaires.json', 'utf8')) as Account;

async function noHorizontalScroll(page: Page, label: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), label).toBe(true);
}

test('titulaires permanents : catégories, enregistrement, doublon, recherche, modification, clôture (ordinateur et tablette)', async ({ page }) => {
  test.setTimeout(240_000);
  const stamp = Date.now();
  const name = `Personne Fictive ${stamp}`;
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginWithSecondFactor(page, admin.email, admin.password, admin.secret);

  // Catégories institutionnelles : initialisation (valeurs neutres) puis modification d'un repère.
  await page.goto('/administration/categories-institutionnelles');
  if (await page.getByTestId('initialiser-categories').isVisible()) await page.getByTestId('initialiser-categories').click();
  await expect(page.getByTestId('categorie-AILCT-08')).toBeVisible();
  await page.getByTestId('repere-AILCT-03').fill('▲');
  await page.getByTestId('enregistrer-AILCT-03').click();
  await expect(page.getByTestId('repere-AILCT-03')).toHaveValue('▲');
  await page.screenshot({ path: 'test-results/a04-categories-institutionnelles-1440.png', fullPage: true });

  // Registre : enregistrement d'un titulaire fictif.
  await page.goto('/administration/titulaires');
  await expect(page.getByTestId('page-titulaires')).toBeVisible();
  await page.getByTestId('bloc-creation').locator('summary').click();
  await page.getByTestId('creer-prenom').fill(name.split(' ')[0]);
  await page.getByTestId('creer-nom').fill(name.split(' ').slice(1).join(' '));
  await page.getByTestId('creer-organisation').fill('Organisation fictive');
  await page.getByTestId('creer-fonction').fill('Fonction fictive');
  await page.getByTestId('creer-categorie').selectOption('AILCT-01');
  await page.getByTestId('enregistrer-titulaire').click();
  const card = page.locator('[data-testid^="titulaire-"]').filter({ hasText: name });
  await expect(card).toBeVisible();
  const identifier = (await card.getByTestId('identifiant-titulaire').innerText()).trim();
  expect(identifier).toMatch(/^ID-\d{6}-\d$/);
  await page.screenshot({ path: 'test-results/a04-titulaires-1440.png', fullPage: true });

  // Doublon refusé (le formulaire reste ouvert après l'enregistrement).
  await page.getByTestId('creer-prenom').fill(name.split(' ')[0].toUpperCase());
  await page.getByTestId('creer-nom').fill(name.split(' ').slice(1).join(' ').toUpperCase());
  await page.getByTestId('creer-organisation').fill('organisation fictive');
  await page.getByTestId('enregistrer-titulaire').click();
  await expect(page.getByText('aucun doublon n’est créé')).toBeVisible();

  // Recherche par identifiant, puis modification : l'identifiant ne change pas.
  await page.getByTestId('recherche-titulaires').fill(identifier);
  await page.getByTestId('lancer-recherche').click();
  await expect(page.getByTestId('total-titulaires')).toContainText('1 personne');
  await page.getByRole('button', { name: 'Modifier' }).first().click();
  await page.getByTestId('edit-nom-famille').fill(name.split(' ').slice(1).join(' '));
  await page.locator('form').filter({ hasText: 'Début d’engagement' }).last().getByLabel('Fonction').fill('Fonction fictive modifiée');
  await page.getByTestId('enregistrer-modification').click();
  await expect(page.getByText('Fonction fictive modifiée')).toBeVisible();
  await expect(card.getByTestId('identifiant-titulaire')).toHaveText(identifier);

  // Tablette : lecture, pas de défilement horizontal, clôture.
  await page.setViewportSize({ width: 1024, height: 768 });
  await page.goto('/administration/titulaires?q=' + encodeURIComponent(identifier));
  await noHorizontalScroll(page, 'registre tablette');
  await page.screenshot({ path: 'test-results/a04-titulaires-1024x768.png', fullPage: true });
  await page.getByRole('button', { name: 'Clore' }).first().click();
  await page.getByTestId('motif-cloture').fill('Fin d’engagement fictive');
  await page.getByTestId('confirmer-cloture').check();
  await page.getByTestId('valider-cloture').click();
  await expect(page.getByTestId('aucun-titulaire')).toBeVisible();
  await page.goto('/administration/titulaires?statut=clos&q=' + encodeURIComponent(identifier));
  await expect(page.getByTestId('etat-titulaire')).toHaveText('Clôturée');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/administration/categories-institutionnelles');
  await noHorizontalScroll(page, 'catégories mobile');
  await page.setViewportSize({ width: 1024, height: 768 });
  await page.goto('/administration/categories-institutionnelles');
  await noHorizontalScroll(page, 'catégories tablette');
  await page.screenshot({ path: 'test-results/a04-categories-institutionnelles-1024x768.png', fullPage: true });
});
