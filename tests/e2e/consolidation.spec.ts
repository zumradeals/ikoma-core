import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
const admin = JSON.parse(readFileSync('.secrets/screen-consolidation.json', 'utf8')) as Account;

async function noHorizontalScroll(page: Page, label: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), label).toBe(true);
}

test('navigation consolidée : badges visiteurs, cartes permanentes, invités (ordinateur et tablette)', async ({ page }) => {
  test.setTimeout(240_000);
  await loginWithSecondFactor(page, admin.email, admin.password, admin.secret);
  for (const [label, width, height] of [['1440', 1440, 900], ['1024', 1024, 768]] as const) {
    await page.setViewportSize({ width, height });
    if (width < 1100) { /* le menu se replie sur tablette : on l'ouvre quand il existe */ }
    const openMenu = async () => { const toggle = page.getByTestId('ouvrir-menu'); if (await toggle.isVisible() && (await toggle.getAttribute('aria-expanded')) === 'false') await toggle.click(); };

    // Rubrique d'accueil : une entrée par usage, aucune sous-entrée ouverte.
    await page.goto('/administration'); await openMenu();
    await expect(page.getByTestId('menu-badges')).toHaveText('Badges visiteurs');
    await expect(page.getByTestId('menu-titulaires')).toContainText('Personnes et cartes permanentes');
    await expect(page.getByTestId('menu-atelier')).toHaveText('Atelier des badges');
    await expect(page.getByTestId('menu-modeles-cartes')).toHaveCount(0);
    await noHorizontalScroll(page, `tableau ${label}`);
    await page.screenshot({ path: `test-results/a065-tableau-${label}.png`, fullPage: true });

    // Badges visiteurs : l'atelier est une sous-entrée ; renvoi explicite vers les cartes permanentes.
    await page.goto('/administration/badges'); await openMenu();
    await expect(page.getByTestId('menu-atelier')).toBeVisible();
    await expect(page.getByTestId('menu-modeles-cartes')).toHaveCount(0);
    await noHorizontalScroll(page, `badges ${label}`);
    await page.screenshot({ path: `test-results/a065-badges-visiteurs-${label}.png`, fullPage: true });
    await page.getByTestId('lien-cartes-permanentes').click();
    await expect(page.getByTestId('page-titulaires')).toBeVisible();

    // Titulaires et cartes : sous-entrées propres, renvoi vers les badges visiteurs.
    await openMenu();
    await expect(page.getByTestId('menu-modeles-cartes')).toBeVisible();
    await expect(page.getByTestId('menu-habilitations')).toBeVisible();
    await expect(page.getByTestId('menu-atelier')).toBeVisible();
    await expect(page.getByTestId('navigation-cartes')).toContainText('Badges visiteurs');
    await noHorizontalScroll(page, `titulaires ${label}`);
    await page.screenshot({ path: `test-results/a065-titulaires-cartes-${label}.png`, fullPage: true });
    await page.getByTestId('lien-modeles').click();
    await expect(page.getByTestId('page-modeles-cartes')).toBeVisible();
    await page.screenshot({ path: `test-results/a065-modeles-cartes-${label}.png`, fullPage: true });

    // Invités : « Ajouter un invité », réservé aux invités temporaires, avec renvoi vers le registre.
    await page.goto('/administration/imports');
    await expect(page.getByTestId('ajouter-invite')).toHaveText('Ajouter un invité');
    await expect(page.getByText('Ajouter une personne')).toHaveCount(0);
    await page.getByTestId('ajouter-invite').click();
    await expect(page.getByRole('heading', { name: 'Ajouter un invité' })).toBeVisible();
    await expect(page.getByTestId('lien-registre-titulaires')).toBeVisible();
    await noHorizontalScroll(page, `invité ${label}`);
    await page.screenshot({ path: `test-results/a065-ajouter-invite-${label}.png`, fullPage: true });
  }
});
