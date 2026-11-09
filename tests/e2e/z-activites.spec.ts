// V1 / chantier Activités — registre et fiche d'activité (ordinateur puis téléphone). Consultation seulement : aucun parcours ici ne crée ni ne modifie de donnée.
// Données fictives de la recette uniquement.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
import { openMenu } from './menu';

type Account = { email: string; password: string; secret: string };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { admin: Account };

test.use({ actionTimeout: 15_000 });

test('Activités : registre, fiche en panneau, onglets, puis usage sur téléphone', async ({ page, browser }) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  await page.goto('/administration');
  await openMenu(page, 'activites');
  await expect(page).toHaveURL(/\/administration\/evenements$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Activités' })).toBeVisible();
  for (const id of ['kpi-en-cours', 'kpi-participants', 'kpi-a-venir', 'kpi-cloturees']) await expect(page.getByTestId(id)).toHaveText(/^\d+$/);
  await expect(page.getByTestId('registre-activites')).toBeVisible();
  await expect(page.getByTestId('liste-evenements')).toBeVisible();

  // fiche en panneau : vue d'ensemble, participants, accès et badges, historique et clôture
  await page.locator('[data-testid^="selectionner-activite-"]').first().click();
  await expect(page.getByTestId('fiche-activite')).toBeVisible();
  await expect(page.getByTestId('fiche-titre')).not.toBeEmpty();
  await expect(page.getByTestId('panneau-ensemble')).toBeVisible();
  await page.getByTestId('onglet-participants').click();
  await expect(page.getByTestId('panneau-participants')).toBeVisible();
  await page.getByTestId('onglet-acces').click();
  await expect(page.getByTestId('panneau-acces')).toContainText('habilitations permanentes appartiennent au profil permanent');
  await page.getByTestId('onglet-historique').click();
  await expect(page.getByTestId('panneau-historique')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await page.screenshot({ path: 'test-results/activites-bureau.png', fullPage: true });
  await page.getByTestId('fermer-fiche').click();
  await expect(page.getByTestId('fiche-activite')).toHaveCount(0);

  // recherche : aucun résultat pour un nom improbable
  await page.getByTestId('recherche-activite').fill('zzzz-aucune-activite-987654');
  await expect(page.getByTestId('aucune-activite')).toBeVisible();
  await page.getByTestId('recherche-activite').fill('');

  // téléphone : tiroir pour la fiche, aucun défilement horizontal, actions de la taille d'un doigt
  const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, storageState: await page.context().storageState(), viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const phone = await context.newPage();
  const noScroll = async () => expect(await phone.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await phone.goto('/administration/evenements');
  await expect(phone.getByTestId('registre-activites')).toBeVisible();
  await noScroll();
  await phone.screenshot({ path: 'test-results/activites-mobile-registre.png', fullPage: true });
  await phone.locator('[data-testid^="selectionner-activite-"]').first().tap();
  await expect(phone.getByTestId('fiche-activite')).toBeVisible();
  await expect(phone.getByTestId('fiche-activite')).toHaveAttribute('role', 'dialog');
  await noScroll();
  await phone.getByTestId('onglet-participants').tap();
  await expect(phone.getByTestId('panneau-participants')).toBeVisible();
  await phone.screenshot({ path: 'test-results/activites-mobile-fiche.png' });
  await phone.getByTestId('fermer-fiche').tap();
  await expect(phone.getByTestId('fiche-activite')).toHaveCount(0);
  await context.close();
});
