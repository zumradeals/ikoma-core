// V1 / PR 1 — « Site et accès » : les cinq postes réels du site, leur configuration en données, retour à l'état initial. Aucun plan, aucune donnée inventée.
// La configuration modifiée est remise telle quelle en fin de parcours pour ne pas perturber les parcours de tablettes qui s'exécutent ensuite.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { admin: Account };

test.use({ actionTimeout: 15_000 });

test('Site et accès : registre des postes, configuration à la sélection, enregistrement, retour à l’état initial, puis usage sur téléphone', async ({ page, browser }) => {
  test.setTimeout(240_000);
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  await page.goto('/administration/site');
  await expect(page.getByTestId('page-site-acces')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Site et accès', exact: true })).toBeVisible();
  for (const code of ['poste_1', 'poste_2', 'poste_3', 'poste_4', 'soute']) await expect(page.getByTestId(`poste-${code}`)).toBeVisible();
  // vue synthétique : aucune configuration détaillée tant qu'aucun poste n'est sélectionné
  await expect(page.getByTestId('detail-poste')).toHaveCount(0);
  await expect(page.getByTestId('operations-poste_2')).toContainText('Accueil');
  await expect(page.getByTestId('operations-poste_1')).toContainText('Aucune opération');
  await expect(page.getByTestId('kpi-postes')).toHaveText('5');

  // configuration par défaut = contrat V1 antérieur, lue à la sélection
  await page.getByTestId('selectionner-poste_2').click();
  await expect(page.getByTestId('capacite-poste_2-accueil')).toBeChecked();
  await expect(page.getByTestId('capacite-poste_2-sortie')).toBeChecked();
  // les tablettes de la recette sont affectées à leur poste (plusieurs possibles) et l'historique existe
  await expect(page.getByTestId('tablettes-poste_2').locator('li').first()).toBeVisible();
  await page.getByTestId('selectionner-poste_1').click();
  await expect(page.getByTestId('capacite-poste_1-scan')).not.toBeChecked();
  await expect(page.getByTestId('sens-poste_1')).toBeDisabled();
  await page.getByTestId('selectionner-soute').click();
  await expect(page.getByTestId('capacite-soute-scan')).toBeChecked();
  await expect(page.getByTestId('sens-soute')).toBeChecked();
  await page.getByTestId('tab-parametres').click();
  await expect(page.getByTestId('historique-affectations')).toBeVisible();
  await page.getByTestId('tab-tablettes').click();
  await expect(page.getByTestId('panneau-tablettes')).toBeVisible();
  await page.getByTestId('tab-zones').click();
  await expect(page.getByTestId('panneau-zones')).toBeVisible();
  await page.getByTestId('tab-regles').click();
  await expect(page.getByTestId('regle-poste_2')).toBeVisible();
  await page.getByTestId('tab-postes').click();

  // activer le contrôle de badge au poste 1, enregistrer, retrouver la configuration après rechargement
  await page.getByTestId('selectionner-poste_1').click();
  await page.getByTestId('capacite-poste_1-scan').check();
  await page.getByTestId('enregistrer-poste_1').click();
  await expect(page.getByRole('status').filter({ hasText: 'Configuration du poste enregistrée' })).toBeVisible();
  await page.reload();
  await page.getByTestId('selectionner-poste_1').click();
  await expect(page.getByTestId('capacite-poste_1-scan')).toBeChecked();

  // retour à l'état initial
  await page.getByTestId('capacite-poste_1-scan').uncheck();
  await page.getByTestId('enregistrer-poste_1').click();
  await expect(page.getByRole('status').filter({ hasText: 'Configuration du poste enregistrée' })).toBeVisible();
  await page.reload();
  await page.getByTestId('selectionner-poste_1').click();
  await expect(page.getByTestId('capacite-poste_1-scan')).not.toBeChecked();

  // ── Téléphone : mêmes capacités, ergonomie mesurée ──
  const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, storageState: await page.context().storageState(), viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const phone = await context.newPage();
  const noScroll = async () => expect(await phone.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  const touchable = async (testId: string) => { const box = await phone.getByTestId(testId).first().boundingBox(); expect(box, testId).not.toBeNull(); expect(box!.height, testId).toBeGreaterThanOrEqual(44); };

  // navigation : le menu se replie, les sept entrées sont atteignables
  await phone.goto('/administration');
  await expect(phone.getByTestId('ouvrir-menu')).toBeVisible();
  await phone.getByTestId('ouvrir-menu').click();
  await expect(phone.locator('[data-testid^="menu-"]')).toHaveCount(7);
  await phone.getByTestId('menu-site').click();
  await expect(phone).toHaveURL(/\/administration\/site$/);
  await expect(phone.getByTestId('page-site-acces')).toBeVisible();
  await noScroll();
  await phone.screenshot({ path: 'test-results/site-acces-mobile-registre.png', fullPage: true });

  // registre : chaque poste lisible, actions de la taille d'un doigt, onglets atteignables
  await expect(phone.getByTestId('poste-poste_2')).toBeVisible();
  for (const id of ['selectionner-poste_2', 'ajouter-poste', 'tab-postes', 'tab-zones', 'tab-tablettes', 'tab-regles', 'tab-parametres']) await touchable(id);
  const size = await phone.getByTestId('poste-poste_2').evaluate(el => parseFloat(getComputedStyle(el).fontSize));
  expect(size).toBeGreaterThanOrEqual(14);

  // configuration : s'ouvre à la sélection, formulaire et actions utilisables
  await phone.getByTestId('selectionner-poste_2').tap();
  await expect(phone.getByTestId('detail-poste')).toBeVisible();
  await noScroll();
  for (const id of ['nom-poste_2', 'capacite-poste_2-scan', 'enregistrer-poste_2', 'activation-poste_2', 'fermer-detail']) await touchable(id);
  await phone.getByTestId('detail-poste').scrollIntoViewIfNeeded();
  await phone.screenshot({ path: 'test-results/site-acces-mobile-configuration.png', fullPage: true });
  await phone.getByTestId('fermer-detail').tap();
  await expect(phone.getByTestId('detail-poste')).toHaveCount(0);

  // autres sections
  for (const tab of ['zones', 'tablettes', 'regles', 'parametres']) {
    await phone.getByTestId(`tab-${tab}`).tap();
    await expect(phone.getByTestId(`panneau-${tab}`)).toBeVisible();
    await noScroll();
  }
  await phone.getByTestId('tab-tablettes').tap();
  await phone.screenshot({ path: 'test-results/site-acces-mobile-tablettes.png', fullPage: true });
  await context.close();
});
