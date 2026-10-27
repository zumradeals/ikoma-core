import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
test('le build suivant remplace la page hors ligne après resynchronisation', async ({ page, context }) => {
  test.setTimeout(180000);
  const file = 'resources/js/pages/Home.vue'; const initial = readFileSync(file, 'utf8');
  await page.goto('/');
  await page.evaluate(async () => { await navigator.serviceWorker.ready; if (!navigator.serviceWorker.controller) await new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true })); });
  await expect(page.getByRole('heading', { name: 'IKOMA Accueil — en construction' })).toBeVisible();
  try {
    writeFileSync(file, initial.replace('IKOMA Accueil — en construction', 'IKOMA Accueil — version synchronisée'));
    execSync('npm run build', { stdio: 'pipe' });
    await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.ready;
      const changed = new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true }));
      await registration.update(); await changed;
    });
    await page.reload();
    await expect(page.getByRole('heading', { name: 'IKOMA Accueil — version synchronisée' })).toBeVisible();
    await context.setOffline(true); await page.close();
    const reopened = await context.newPage(); await reopened.goto('/');
    await expect(reopened.getByRole('heading', { name: 'IKOMA Accueil — version synchronisée' })).toBeVisible();
  } finally { writeFileSync(file, initial); execSync('npm run build', { stdio: 'pipe' }); }
});
