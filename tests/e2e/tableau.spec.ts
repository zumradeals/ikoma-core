import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { freshOtp } from './otp';

const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { admin: { email: string; password: string; secret: string } };
const shots = process.env.TABLEAU_CAPTURES === '1';

async function loginAdmin(page: Page): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Adresse électronique').fill(fx.admin.email); await page.getByLabel('Mot de passe', { exact: true }).fill(fx.admin.password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await page.getByLabel('Code à six chiffres').fill(await freshOtp(fx.admin.secret));
  await page.getByRole('button', { name: 'Vérifier', exact: true }).click();
  await expect(page).toHaveURL(/\/espace/);
}
const noHorizontalScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

test('tableau de bord de l’administrateur : lisible sur ordinateur et sur téléphone, sans défilement horizontal', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await loginAdmin(page);
  await page.getByRole('link', { name: 'Tableau de bord' }).click();
  await expect(page).toHaveURL(/\/administration$/);
  await expect(page.getByTestId('titre-etat')).toContainText('Votre événement est');
  await expect(page.getByRole('progressbar')).toBeVisible();
  for (const key of ['zones', 'liste', 'badges', 'tablettes', 'groupes', 'comptes']) await expect(page.getByTestId(`statut-${key}`)).toHaveText(/(✓ Fait|● En cours|○ À faire)/);
  await expect(page.getByTestId('a-surveiller')).toBeVisible();
  await expect(page.getByTestId('dernieres-actions')).toBeVisible();
  expect(await noHorizontalScroll(page)).toBe(true);
  if (shots) await page.screenshot({ path: 'docs/captures/tableau/bureau.png', fullPage: true });

  await page.setViewportSize({ width: 380, height: 800 });
  await expect(page.getByTestId('titre-etat')).toBeVisible();
  expect(await noHorizontalScroll(page)).toBe(true);
  await page.getByTestId('ouvrir-menu').click();
  await expect(page.getByTestId('menu-tableau')).toBeVisible();
  if (shots) await page.screenshot({ path: 'docs/captures/tableau/telephone.png', fullPage: true });
});
