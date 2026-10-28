import { test, expect } from '@playwright/test';
test('la page d’accueil se rouvre sans réseau après une première visite', async ({ page, context }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Accueillez, contrôlez et suivez vos visiteurs en toute sécurité' })).toBeVisible();
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
    if (!navigator.serviceWorker.controller) await new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true }));
  });
  await page.setViewportSize({ width: 820, height: 1180 });
  await page.screenshot({ path: 'test-results/accueil-tablette.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'test-results/accueil-mobile.png', fullPage: true });
  await context.setOffline(true);
  await page.close();
  const offline = await context.newPage();
  await offline.goto('/');
  await expect(offline.getByRole('heading', { name: 'Accueillez, contrôlez et suivez vos visiteurs en toute sécurité' })).toBeVisible();
  await expect(offline.getByRole('status')).toContainText('même sans réseau');
});
