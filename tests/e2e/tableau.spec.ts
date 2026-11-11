import { test, expect, type Page } from '@playwright/test';
import { expandMenu, openMenu, openPage, openZones } from './menu';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { admin: { email: string; password: string; secret: string } };
async function loginAdmin(page: Page): Promise<void> {
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  await expect(page).toHaveURL(/\/administration$/);
}
const noHorizontalScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

test('administration unifiée : connexion, modules réels, liens directs, historique et captures responsive', async ({ page, browser }) => {
  test.setTimeout(240_000); // intègre les éventuels délais de connexion imposés par le serveur
  await page.setViewportSize({ width: 1448, height: 1086 });
  await loginAdmin(page);
  await expect(page.getByTestId('admin-layout')).toHaveCount(1);
  await expect(page.getByRole('link', { name: 'Mon espace', exact: true })).toHaveCount(0);
  await expect(page.getByTestId('titre-general')).toContainText('Tableau de bord');
  await expect(page.getByTestId('indicateurs-globaux')).toBeVisible();
  await expect(page.getByTestId('graphique-acces')).toBeVisible();
  await expect(page.getByTestId('derniers-mouvements')).toBeVisible();
  await expect(page.getByTestId('acces-rapides')).toBeVisible();
  // L'ancienne préparation pas à pas n'est plus sous la vue d'ensemble.
  await expect(page.getByTestId('titre-etat')).toHaveCount(0);
  await expect(page.getByRole('progressbar')).toHaveCount(0);
  expect(await noHorizontalScroll(page)).toBe(true);
  await page.screenshot({ path: 'test-results/administration/tableau-bureau.png', fullPage: true });
  await openPage(page, 'administration', 'comptes');
  await expect(page.getByRole('heading', { name: 'Administration · Comptes' })).toBeVisible();
  await expect(page.getByTestId('menu-administration')).toHaveAttribute('aria-current', 'page');
  await expect(page.getByTestId('sous-menu-comptes')).toHaveAttribute('aria-current', 'page');
  await page.screenshot({ path: 'test-results/administration/comptes-bureau.png', fullPage: true });
  await page.getByLabel('Rechercher un compte').fill('compte introuvable 987654');
  await expect(page.getByRole('status')).toContainText('Aucun compte ne correspond');
  await page.getByLabel('Rechercher un compte').clear();
  await openMenu(page, 'badges-cartes');
  await expect(page.getByTestId('titre-badges-cartes')).toHaveText('Badges et cartes');
  await expect(page.getByTestId('admin-layout')).toHaveCount(1);
  await expandMenu(page);
  await page.getByTestId('menu-tableau').click();
  await expect(page).toHaveURL(/\/administration$/);
  await page.goBack();
  await expect(page.getByTestId('menu-badges-cartes')).toHaveAttribute('aria-current', 'page');
  await page.goForward();
  await expect(page.getByTestId('menu-tableau')).toHaveAttribute('aria-current', 'page');
  for (const [menu, key] of [['activites', 'categories'], ['operations', 'groupes'], ['operations', 'postes'], ['administration', 'journal'], ['administration', 'donnees']]) {
    await openPage(page, menu, key);
    await expect(page.getByTestId('admin-layout')).toHaveCount(1);
    await expect(page.getByTestId(`menu-${menu}`)).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId(`sous-menu-${key}`)).toHaveAttribute('aria-current', 'page');
    expect(await noHorizontalScroll(page)).toBe(true);
  }
  await openZones(page);
  await expect(page).toHaveURL(/\/administration\/site$/);
  await expect(page.getByTestId('menu-site')).toHaveAttribute('aria-current', 'page');
  // Pages sorties du menu mais toujours atteignables : liste d'invités depuis Personnes, politique de conservation depuis Données.
  await expandMenu(page);
  await page.getByTestId('menu-personnes').click();
  await page.getByTestId('importer-liste').click();
  await expect(page).toHaveURL(/\/administration\/imports$/);
  await expect(page.getByTestId('menu-personnes')).toHaveAttribute('aria-current', 'page');
  await page.goto('/administration/donnees');
  await page.getByRole('link', { name: 'Consulter la politique' }).click();
  await expect(page).toHaveURL(/\/administration\/conservation$/);
  await expect(page.getByTestId('sous-menu-donnees')).toHaveAttribute('aria-current', 'page');
  await page.goto('/administration/badges/lots?status=Disponible&page=2');
  await expect(page.getByTestId('menu-badges-cartes')).toHaveAttribute('aria-current', 'page');
  await page.goto('/administration/site');
  await page.screenshot({ path: 'test-results/administration/zones-bureau.png', fullPage: true });
  await page.goto('/espace');
  await expect(page).toHaveURL(/\/administration$/);
  for (const width of [820, 380]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto('/administration');
    await expect(page.getByTestId('menu-tableau')).toHaveAttribute('aria-current', 'page');
    expect(await noHorizontalScroll(page)).toBe(true);
    await page.getByTestId('ouvrir-menu').click();
    await openPage(page, 'administration', 'comptes');
    await expect(page.getByTestId('sous-menu-comptes')).toHaveAttribute('aria-current', 'page');
    expect(await noHorizontalScroll(page)).toBe(true);
    await page.screenshot({ path: `test-results/administration/comptes-${width}.png`, fullPage: true });
    await page.getByTestId('ouvrir-menu').click();
    await expandMenu(page);
    await page.getByTestId('menu-tableau').click();
    await expect(page.getByTestId('menu-tableau')).toHaveAttribute('aria-current', 'page');
    expect(await noHorizontalScroll(page)).toBe(true);
    await page.screenshot({ path: `test-results/administration/tableau-${width}.png`, fullPage: true });
  }
  const touch = await browser.newContext({ baseURL: test.info().project.use.baseURL, storageState: await page.context().storageState(), viewport: { width: 820, height: 1180 }, isMobile: true, hasTouch: true });
  const tablet = await touch.newPage();
  await tablet.goto('/administration/comptes');
  for (const control of [tablet.getByRole('button', { name: 'Créer le compte', exact: true }), tablet.getByRole('button', { name: /^Modifier/ }).first(), tablet.getByLabel('Nom', { exact: true })]) {
    expect((await control.boundingBox())!.height).toBeGreaterThanOrEqual(56);
  }
  expect(await noHorizontalScroll(tablet)).toBe(true);
  await tablet.screenshot({ path: 'test-results/administration/comptes-tablette-tactile.png', fullPage: true });
  await touch.close();
  await page.setViewportSize({ width: 1448, height: 1086 });
  await openPage(page, 'administration', 'securite');
  await expect(page.getByRole('heading', { name: 'Confirmer votre mot de passe' })).toBeVisible();
  await expect(page.getByTestId('admin-layout')).toHaveCount(1);
  await page.getByLabel('Mot de passe', { exact: true }).fill(fx.admin.password);
  await page.getByRole('button', { name: 'Confirmer', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Sécuriser mon compte' })).toBeVisible();
  await expect(page.getByTestId('sous-menu-securite')).toHaveAttribute('aria-current', 'page');
});
