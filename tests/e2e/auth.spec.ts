import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

test('connexion par mot de passe, comptes et journal', async ({ page }) => {
  // Conserve la limitation des connexions sans utiliser de second facteur.
  test.setTimeout(180_000);
  const mark = (step: string) => console.info(`IKOMA_E2E_STEP:${step}`);
  mark('auth.start');
  const user = JSON.parse(readFileSync('.secrets/screen-account.json', 'utf8')) as { email: string; password: string; secret: string };
  await page.setViewportSize({ width: 820, height: 1180 });

  mark('auth.login.page');
  await page.goto('/login');
  await page.screenshot({ path: 'test-results/connexion.png', fullPage: true });
  await test.step('connexion sans code OTP', async () => {
    await loginWithSecondFactor(page, user.email, user.password, user.secret, undefined, step => mark(`auth.${step}`));
    mark('auth.login.done');
  });

  await test.step('accès à la gestion des comptes', async () => {
    mark('auth.accounts.menu');
    await page.getByTestId('ouvrir-menu').click();
    mark('auth.accounts.click');
    await page.getByRole('link', { name: 'Comptes', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Administration · Comptes' })).toBeVisible();
    mark('auth.accounts.visible');
    await page.screenshot({ path: 'test-results/comptes.png', fullPage: true });
  });

  await test.step('accès au journal auditable', async () => {
    mark('auth.journal.navigate');
    await page.goto('/administration/journal');
    await expect(page.getByRole('heading', { name: 'Administration · Journal' })).toBeVisible();
    mark('auth.journal.visible');
    await page.screenshot({ path: 'test-results/journal.png', fullPage: true });
  });
});
