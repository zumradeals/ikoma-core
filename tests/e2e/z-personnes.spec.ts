// V1 / lot P1 — « Personnes et habilitations » : registre unifié en consultation et fiche en panneau (ordinateur puis téléphone).
// Lecture seule : ce parcours ne crée ni ne modifie aucune donnée. Données fictives de la recette uniquement.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
import { openMenu } from './menu';

type Account = { email: string; password: string; secret: string };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { admin: Account };

test.use({ actionTimeout: 15_000 });

test('Personnes et habilitations : registre, fiche en panneau, onglets, puis usage sur téléphone', async ({ page, browser }) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  await page.goto('/administration');
  await openMenu(page, 'personnes');
  await expect(page).toHaveURL(/\/administration\/personnes$/);
  await expect(page.getByTestId('page-personnes')).toBeVisible();
  await expect(page.getByTestId('titre-personnes')).toHaveText('Personnes et habilitations');
  await expect(page.getByTestId('menu-personnes')).toHaveAttribute('aria-current', 'page');
  for (const id of ['kpi-personnes', 'kpi-actives', 'kpi-echeance', 'kpi-terminees']) await expect(page.getByTestId(id)).toHaveText(/^\d+$/);
  await expect(page.getByTestId('registre-personnes')).toBeVisible();
  await expect(page.getByTestId('fiche-vide')).toBeVisible();

  // fiche en panneau : identité, habilitations (deux origines séparées), badges, activités, historique
  const first = page.locator('[data-testid^="selectionner-personne-"]').first();
  await first.click();
  await expect(page.getByTestId('fiche-personne')).toBeVisible();
  await expect(page.getByTestId('nom-personne')).not.toBeEmpty();
  await expect(page.getByTestId('onglet-identite')).toBeVisible();
  await page.getByTestId('ptab-habilitations').click();
  await expect(page.getByTestId('habilitations-permanentes')).toBeVisible();
  await expect(page.getByTestId('habilitations-activites')).toBeVisible();
  await expect(page.getByTestId('resume-acces')).toBeVisible();
  await page.getByTestId('ptab-badges').click();
  await expect(page.getByTestId('onglet-badges')).toContainText('pas une habilitation');
  await page.getByTestId('ptab-activites').click();
  await expect(page.getByTestId('onglet-activites')).toBeVisible();
  await page.getByTestId('ptab-historique').click();
  await expect(page.getByTestId('onglet-historique')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/personnes-bureau.png', fullPage: true });
  await page.getByTestId('fermer-fiche').click();
  await expect(page.getByTestId('fiche-personne')).toHaveCount(0);

  // recherche serveur : aucun résultat pour un nom improbable, sans rien créer
  await page.getByTestId('recherche-personne').fill('zzzz-aucune-personne-987654');
  await page.getByTestId('lancer-recherche').click();
  await expect(page.getByTestId('aucune-personne')).toBeVisible();
  await page.getByTestId('recherche-personne').fill('');
  await page.getByTestId('lancer-recherche').click();
  await expect(page.getByTestId('liste-personnes')).toBeVisible();

  // téléphone : tiroir pour la fiche, aucun défilement horizontal, actions de la taille d'un doigt
  const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, storageState: await page.context().storageState(), viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const phone = await context.newPage();
  const noScroll = async () => expect(await phone.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  const touchable = async (testId: string) => { const box = await phone.getByTestId(testId).first().boundingBox(); expect(box, testId).not.toBeNull(); expect(box!.height, testId).toBeGreaterThanOrEqual(44); };
  await phone.goto('/administration/personnes');
  await expect(phone.getByTestId('page-personnes')).toBeVisible();
  await noScroll();
  await phone.screenshot({ path: 'test-results/personnes-mobile-registre.png', fullPage: true });
  for (const id of ['ajouter-personne', 'importer-liste', 'lancer-recherche']) await touchable(id);
  await phone.locator('[data-testid^="selectionner-personne-"]').first().tap();
  await expect(phone.getByTestId('fiche-personne')).toBeVisible();
  await expect(phone.getByTestId('fiche-personne')).toHaveAttribute('role', 'dialog');
  await noScroll();
  for (const id of ['fermer-fiche', 'ptab-identite', 'ptab-habilitations', 'ptab-badges', 'ptab-activites', 'ptab-historique']) await touchable(id);
  await phone.getByTestId('ptab-habilitations').tap();
  await expect(phone.getByTestId('habilitations-permanentes')).toBeVisible();
  await phone.screenshot({ path: 'test-results/personnes-mobile-fiche.png' });
  await phone.getByTestId('fermer-fiche').tap();
  await expect(phone.getByTestId('fiche-personne')).toHaveCount(0);
  await context.close();
});
