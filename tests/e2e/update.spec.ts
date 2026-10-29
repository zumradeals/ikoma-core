import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
test('le build suivant remplace la page hors ligne après resynchronisation', async ({ page, context }) => {
  test.setTimeout(180000);
  const file = 'resources/js/pages/Home.vue'; const initial = readFileSync(file, 'utf8');
  await page.goto('/');
  await page.evaluate(async () => { await navigator.serviceWorker.ready; if (!navigator.serviceWorker.controller) await new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true })); });
  await expect(page.getByRole('heading', { level: 1 })).toContainText('Contrôlez les accès.');
  try {
    if (!initial.includes('Contrôlez les accès.<br><span')) throw new Error('Marqueur de version introuvable sur la landing.');
    writeFileSync(file, initial.replace('Contrôlez les accès.<br><span', 'Contrôlez les accès. (version synchronisée)<br><span'));
    execSync('npm run build', { stdio: 'pipe' });
    await page.evaluate(async () => {
      const registration = await navigator.serviceWorker.ready;
      const changed = new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true }));
      await registration.update(); await changed;
    });
    await page.reload();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Contrôlez les accès. (version synchronisée)');
    await context.setOffline(true); await page.close();
    const reopened = await context.newPage(); await reopened.goto('/');
    await expect(reopened.getByRole('heading', { level: 1 })).toContainText('Contrôlez les accès. (version synchronisée)');
  } finally { writeFileSync(file, initial); execSync('npm run build', { stdio: 'pipe' }); }
});
