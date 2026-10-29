import { test, expect } from '@playwright/test';

test('IKOMA ACCESS : landing et connexion utilisent la largeur des dashboards sur quatre formats', async ({ page }) => {
  test.setTimeout(120_000);
  for (const [width, height] of [[1440, 900], [1024, 768], [768, 1024], [390, 844]]) {
    await page.setViewportSize({ width, height });
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Contrôlez les accès.');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Maîtrisez les visites.');
    await expect(page.locator('img[src="/brand/ikoma-access-icon.svg"]').first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const heroWidth = await page.locator('main section').first().evaluate(el => el.getBoundingClientRect().width);
    expect(heroWidth).toBeGreaterThanOrEqual(width - 1);
    await page.screenshot({ path: `test-results/ikoma-access-landing-${width}x${height}.png`, fullPage: true });

    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Se connecter' })).toBeVisible();
    await expect(page.getByLabel('Adresse électronique')).toBeVisible();
    await expect(page.getByLabel('Mot de passe', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Se connecter' })).toBeVisible();
    await page.getByRole('button', { name: 'Afficher le mot de passe' }).click();
    await expect(page.getByLabel('Mot de passe', { exact: true })).toHaveAttribute('type', 'text');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: `test-results/ikoma-access-login-${width}x${height}.png`, fullPage: true });
  }
});

test('IKOMA ACCESS : la landing reste une page publique avec une connexion nominative', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('link', { name: /Demander une présentation/i }).first()).toHaveAttribute('href', /mailto:ikomagroup2@gmail.com/);
  await expect(page.getByRole('navigation', { name: 'Navigation principale' }).getByRole('link', { name: 'Fonctionnalités' })).toBeVisible();
  await page.getByRole('link', { name: /Se connecter/i }).first().click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByLabel('Mot de passe', { exact: true })).toHaveAttribute('type', 'password');
});
