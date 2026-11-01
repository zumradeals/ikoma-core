import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { supervisor: { email: string; password: string; secret: string } };

test('pilotage V2 : accès sans 2FA, menu tablette repliable, quatre formats et écrans communs', async ({ page }) => {
  test.setTimeout(90_000);
  await loginWithSecondFactor(page, fx.supervisor.email, fx.supervisor.password, fx.supervisor.secret);
  await expect(page).toHaveURL(/\/pilotage$/);
  for (const [width, height] of [[1440, 900], [1024, 768], [768, 1024], [390, 844]]) {
    await page.setViewportSize({ width, height });
    await expect(page.getByTestId('compteur-presents')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (width < 1200) {
      const toggle = page.getByTestId('ouvrir-menu-pilotage');
      await expect(toggle).toBeVisible();
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      await expect(page.getByRole('navigation', { name: 'Menu superviseur' })).toBeHidden();
      await toggle.click();
      await expect(page.getByTestId('menu-plus')).toBeVisible();
      await toggle.click();
    } else await expect(page.getByTestId('menu-tableau')).toBeVisible();
    if (width < 900) {
      await expect(page.getByTestId('nav-evacuation')).toBeVisible();
      expect((await page.getByTestId('nav-evacuation').boundingBox())!.height).toBeGreaterThanOrEqual(56);
    }
    await page.screenshot({ path: `test-results/pilotage-v2-${width}x${height}.png`, fullPage: true });
  }
  for (const path of ['/pilotage/alertes', '/pilotage/presents', '/pilotage/litiges', '/pilotage/groupes', '/pilotage/evacuation', '/administration/journal']) {
    await page.goto(path);
    await expect(page.getByTestId('pilotage-layout')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  }
  await page.goto('/pilotage');
  const other = page.getByTestId('autres-actions');
  await expect(other.getByTestId('annuler-debut')).toBeHidden();
  await other.locator('summary').click();
  await expect(other.getByTestId('annuler-debut')).toBeVisible();
});
