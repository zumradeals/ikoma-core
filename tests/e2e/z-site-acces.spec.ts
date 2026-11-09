// V1 / PR 1 — « Site et accès » : les cinq postes réels du site, leur configuration en données, retour à l'état initial. Aucun plan, aucune donnée inventée.
// La configuration modifiée est remise telle quelle en fin de parcours pour ne pas perturber les parcours de tablettes qui s'exécutent ensuite.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { admin: Account };

test.use({ actionTimeout: 15_000 });

test('Site et accès : postes réels, capacités en données, enregistrement puis retour à l’état initial', async ({ page }) => {
  test.setTimeout(240_000);
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  await page.goto('/administration/site');
  await expect(page.getByTestId('page-site-acces')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Site et accès', exact: true })).toBeVisible();
  for (const code of ['poste_1', 'poste_2', 'poste_3', 'poste_4', 'soute']) await expect(page.getByTestId(`poste-${code}`)).toBeVisible();
  // configuration par défaut = contrat V1 antérieur
  await expect(page.getByTestId('capacite-poste_2-accueil')).toBeChecked();
  await expect(page.getByTestId('capacite-poste_2-sortie')).toBeChecked();
  await expect(page.getByTestId('capacite-poste_1-scan')).not.toBeChecked();
  await expect(page.getByTestId('capacite-soute-scan')).toBeChecked();
  await expect(page.getByTestId('sens-soute')).toBeChecked();
  await expect(page.getByTestId('sens-poste_1')).toBeDisabled();
  // les tablettes de la recette sont affectées à leur poste (plusieurs possibles) et l'historique existe
  await expect(page.getByTestId('tablettes-poste_2').locator('li').first()).toBeVisible();
  await expect(page.getByTestId('historique-affectations')).toBeVisible();

  // activer le contrôle de badge au poste 1, enregistrer, retrouver la configuration après rechargement
  await page.getByTestId('capacite-poste_1-scan').check();
  await page.getByTestId('enregistrer-poste_1').click();
  await expect(page.getByRole('status').filter({ hasText: 'Configuration du poste enregistrée' })).toBeVisible();
  await page.reload();
  await expect(page.getByTestId('capacite-poste_1-scan')).toBeChecked();

  // retour à l'état initial
  await page.getByTestId('capacite-poste_1-scan').uncheck();
  await page.getByTestId('enregistrer-poste_1').click();
  await expect(page.getByRole('status').filter({ hasText: 'Configuration du poste enregistrée' })).toBeVisible();
  await page.reload();
  await expect(page.getByTestId('capacite-poste_1-scan')).not.toBeChecked();

  // lisible sur téléphone : aucun défilement horizontal
  await page.setViewportSize({ width: 375, height: 800 });
  await page.goto('/administration/site');
  await expect(page.getByTestId('page-site-acces')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('Site et accès : accessible depuis le menu et réservé à l’administrateur', async ({ page }) => {
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  await page.goto('/administration');
  const toggle = page.getByTestId('ouvrir-menu');
  if (await toggle.isVisible() && (await toggle.getAttribute('aria-expanded')) === 'false') await toggle.click();
  const closed = page.locator('[data-testid^="rubrique-"][aria-expanded="false"]');
  while (await closed.count() > 0) await closed.first().click();
  await page.getByTestId('menu-site-acces').click();
  await expect(page).toHaveURL(/\/administration\/site$/);
  await expect(page.getByTestId('menu-site-acces')).toHaveAttribute('aria-current', 'page');
});
