import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { freshOtp } from './otp';

const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { admin: { email: string; password: string; secret: string } };
async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Adresse électronique').fill(fx.admin.email);
  await page.getByLabel('Mot de passe', { exact: true }).fill(fx.admin.password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.getByLabel('Code à six chiffres').fill(await freshOtp(fx.admin.secret));
  await page.getByRole('button', { name: 'Vérifier', exact: true }).click();
  await expect(page).toHaveURL(/\/administration$/);
}
const noHorizontalScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

test('administration unifiée : connexion, modules réels, liens directs, historique et captures responsive', async ({ page, browser }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1448, height: 1086 });
  await loginAdmin(page);
  await expect(page.getByTestId('admin-layout')).toHaveCount(1);
  await expect(page.getByRole('link', { name: 'Mon espace', exact: true })).toHaveCount(0);
  await expect(page.getByTestId('titre-etat')).toContainText('Votre événement');
  await expect(page.getByRole('progressbar')).toBeVisible();
  for (const key of ['zones', 'liste', 'badges', 'tablettes', 'groupes', 'comptes']) await expect(page.getByTestId(`statut-${key}`)).toHaveText(/(✓ Fait|● En cours|○ À faire)/);
  expect(await noHorizontalScroll(page)).toBe(true);
  await page.screenshot({ path: 'test-results/administration/tableau-bureau.png', fullPage: true });
  await page.getByTestId('menu-comptes').click();
  await expect(page.getByRole('heading', { name: 'Administration · Comptes' })).toBeVisible();
  await expect(page.getByTestId('menu-comptes')).toHaveAttribute('aria-current', 'page');
  await page.screenshot({ path: 'test-results/administration/comptes-bureau.png', fullPage: true });
  await page.getByLabel('Rechercher un compte').fill('compte introuvable 987654');
  await expect(page.getByRole('status')).toContainText('Aucun compte ne correspond');
  await page.getByLabel('Rechercher un compte').clear();
  await page.getByTestId('menu-badges').click();
  await expect(page.getByRole('heading', { name: 'Administration · Badges' })).toBeVisible();
  await expect(page.getByTestId('admin-layout')).toHaveCount(1);
  await page.getByTestId('menu-tableau').click();
  await expect(page).toHaveURL(/\/administration$/);
  await page.goBack();
  await expect(page.getByTestId('menu-badges')).toHaveAttribute('aria-current', 'page');
  await page.goForward();
  await expect(page.getByTestId('menu-tableau')).toHaveAttribute('aria-current', 'page');
  for (const key of ['zones', 'categories', 'imports', 'groupes', 'postes', 'journal', 'conservation']) {
    await page.getByTestId(`menu-${key}`).click();
    await expect(page.getByTestId('admin-layout')).toHaveCount(1);
    await expect(page.getByTestId(`menu-${key}`)).toHaveAttribute('aria-current', 'page');
    expect(await noHorizontalScroll(page)).toBe(true);
  }
  await page.goto('/administration/badges?status=Disponible&page=2');
  await expect(page.getByTestId('menu-badges')).toHaveAttribute('aria-current', 'page');
  await page.goto('/administration/zones');
  await page.screenshot({ path: 'test-results/administration/zones-bureau.png', fullPage: true });
  await page.goto('/espace');
  await expect(page).toHaveURL(/\/administration$/);
  for (const width of [820, 380]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto('/administration');
    await expect(page.getByTestId('menu-tableau')).toHaveAttribute('aria-current', 'page');
    expect(await noHorizontalScroll(page)).toBe(true);
    await page.getByTestId('ouvrir-menu').click();
    await page.getByTestId('menu-comptes').click();
    await expect(page.getByTestId('menu-comptes')).toHaveAttribute('aria-current', 'page');
    expect(await noHorizontalScroll(page)).toBe(true);
    await page.screenshot({ path: `test-results/administration/comptes-${width}.png`, fullPage: true });
    await page.getByTestId('ouvrir-menu').click();
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
  await page.getByTestId('menu-securite').click();
  await expect(page.getByRole('heading', { name: 'Confirmer votre mot de passe' })).toBeVisible();
  await expect(page.getByTestId('admin-layout')).toHaveCount(1);
  await page.getByLabel('Mot de passe', { exact: true }).fill(fx.admin.password);
  await page.getByRole('button', { name: 'Confirmer', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Sécuriser mon compte' })).toBeVisible();
  await expect(page.getByTestId('menu-securite')).toHaveAttribute('aria-current', 'page');
});
