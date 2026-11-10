import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
const admin = JSON.parse(readFileSync('.secrets/screen-consolidation.json', 'utf8')) as Account;

async function noHorizontalScroll(page: Page, label: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), label).toBe(true);
}

test('navigation consolidée : espace Badges et cartes, quatre responsabilités, invités (ordinateur et tablette)', async ({ page }) => {
  test.setTimeout(240_000);
  await loginWithSecondFactor(page, admin.email, admin.password, admin.secret);
  for (const [label, width, height] of [['1440', 1440, 900], ['1024', 1024, 768]] as const) {
    await page.setViewportSize({ width, height });
    if (width < 1100) { /* le menu se replie sur tablette : on l'ouvre quand il existe */ }
    const openMenu = async () => { const toggle = page.getByTestId('ouvrir-menu'); if (await toggle.isVisible() && (await toggle.getAttribute('aria-expanded')) === 'false') await toggle.click(); };

    // Accueil : une seule entrée « Badges et cartes » ; plus d'entrée par page.
    await page.goto('/administration'); await openMenu();
    await expect(page.getByTestId('menu-badges-cartes')).toHaveText('Badges et cartes');
    for (const ancien of ['menu-badges', 'menu-titulaires', 'menu-atelier', 'menu-modeles-cartes', 'menu-atelier-parcours', 'menu-imports']) await expect(page.getByTestId(ancien)).toHaveCount(0);
    await noHorizontalScroll(page, `tableau ${label}`);
    await page.screenshot({ path: `test-results/consolidation-tableau-${label}.png`, fullPage: true });

    // L'espace : quatre responsabilités, compteurs, rappel du poste d'accueil.
    await page.getByTestId('menu-badges-cartes').click();
    await expect(page).toHaveURL(/\/administration\/badges$/);
    for (const key of ['registre', 'modeles', 'impression', 'lots']) await expect(page.getByTestId(`onglet-${key}`)).toBeVisible();
    await expect(page.getByTestId('onglet-registre')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('compteurs-badges-cartes')).toBeVisible();
    await expect(page.getByTestId('rappel-accueil')).toContainText('acte distinct de la sortie physique');
    await noHorizontalScroll(page, `badges ${label}`);
    await page.screenshot({ path: `test-results/consolidation-badges-visiteurs-${label}.png`, fullPage: true });

    // Lots et réservations : onglet dédié (stock pour les visiteurs non annoncés), actif sur sa page.
    await page.getByTestId('onglet-lots').click();
    await expect(page.getByRole('heading', { name: 'Administration · Lots et réservations de badges' })).toBeVisible();
    await expect(page.getByTestId('onglet-lots')).toHaveAttribute('aria-current', 'page');
    await noHorizontalScroll(page, `lots ${label}`);
    await page.screenshot({ path: `test-results/consolidation-lots-${label}.png`, fullPage: true });
    // Personnel permanent : onglet du menu « Personnes et habilitations ».
    await page.goto('/administration/personnes');
    await page.getByTestId('sous-menu-titulaires').click();
    await expect(page.getByTestId('page-titulaires')).toBeVisible();
    await expect(page.getByTestId('sous-menu-titulaires')).toHaveAttribute('aria-current', 'page');
    await noHorizontalScroll(page, `titulaires ${label}`);
    await page.screenshot({ path: `test-results/consolidation-cartes-${label}.png`, fullPage: true });

    // Retour à l'espace Badges et cartes (les pages du menu Personnes n'ont pas ses onglets).
    await page.goto('/administration/badges');
    // Modèles institutionnels : un modèle par catégorie ; l'ancien modèle par activité (lots) n'existe plus.
    await page.getByTestId('onglet-modeles').click();
    await expect(page.getByTestId('page-modeles')).toBeVisible();
    await expect(page.getByTestId('page-modeles-cartes')).toBeVisible();
    await expect(page.getByTestId('bloc-modele-badge')).toHaveCount(0);
    await expect(page.getByTestId('modele-historique')).toHaveCount(0);
    await noHorizontalScroll(page, `modeles ${label}`);
    await page.screenshot({ path: `test-results/consolidation-modeles-${label}.png`, fullPage: true });

    // Impression et historique : le suivi des tirages d'abord ; l'atelier par lots (stock existant) s'ouvre à la demande, le modèle y est rappelé avec un lien vers Modèles.
    await page.getByTestId('onglet-impression').click();
    await expect(page.getByTestId('page-atelier')).toBeVisible();
    await expect(page.getByTestId('suivi-tirages')).toBeVisible();
    await expect(page.getByTestId('etape-modele')).toHaveCount(0);
    await page.getByTestId('ouvrir-atelier-lots').click();
    await expect(page.getByTestId('rappel-modele')).toBeVisible();
    await noHorizontalScroll(page, `impression ${label}`);

    // Invités : page « Fiches des invités » (onglet du menu Personnes), réservée aux invités temporaires, avec renvoi vers le registre.
    await page.goto('/administration/imports');
    await expect(page.getByTestId('ajouter-invite')).toHaveText('Ajouter un invité');
    await expect(page.getByText('Ajouter une personne')).toHaveCount(0);
    await page.getByTestId('ajouter-invite').click();
    await expect(page.getByRole('heading', { name: 'Fiches des invités' })).toBeVisible();
    await expect(page.getByTestId('sous-menu-invites')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('lien-registre-titulaires')).toBeVisible();
    await noHorizontalScroll(page, `invité ${label}`);
    await page.screenshot({ path: `test-results/a065-ajouter-invite-${label}.png`, fullPage: true });
  }
});
