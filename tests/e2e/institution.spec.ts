import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { deflateSync } from 'node:zlib';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
const admin = JSON.parse(readFileSync('.secrets/screen-institution.json', 'utf8')) as Account;
const desk = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as { email: string; password: string; supervisor: Account };
const agent: Account = { email: desk.email, password: desk.password, secret: '' };
const sizes = [[1440, 900], [1024, 768], [390, 844]];

/** PNG 64×64 uni (aucun fichier binaire dans le dépôt). */
function logoPng(): Buffer {
  const crc = (buf: Buffer) => { let c = ~0; for (const b of buf) { c ^= b; for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xEDB88320 & -(c & 1)); } return (~c) >>> 0; };
  const chunk = (type: string, data: Buffer) => { const body = Buffer.concat([Buffer.from(type), data]); const out = Buffer.alloc(12 + data.length); out.writeUInt32BE(data.length, 0); body.copy(out, 4); out.writeUInt32BE(crc(body), 8 + data.length); return out; };
  const row = Buffer.concat([Buffer.from([0]), Buffer.concat(Array.from({ length: 64 }, () => Buffer.from([12, 45, 102, 255])))]);
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(64, 0); ihdr.writeUInt32BE(64, 4); ihdr[8] = 8; ihdr[9] = 6;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(Buffer.concat(Array.from({ length: 64 }, () => row)))), chunk('IEND', Buffer.alloc(0))]);
}

async function noHorizontalScroll(page: Page, label: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), label).toBe(true);
}

test('identité institutionnelle : configuration par l’administrateur, affichage cohérent, retour au standard', async ({ page, browser }, info) => {
  test.setTimeout(240_000);
  await page.setViewportSize({ width: 1280, height: 900 });
  await loginWithSecondFactor(page, admin.email, admin.password, admin.secret);
  try {
    await page.goto('/administration/identite');
    await expect(page.getByTestId('identite-standard')).toBeVisible();
    await page.screenshot({ path: 'test-results/a03-admin-identite-standard.png', fullPage: true });

    await page.getByTestId('champ-nom').fill('AILCT');
    await page.getByTestId('champ-nom_complet').fill('Académie Internationale de Lutte Contre le Terrorisme');
    await page.getByTestId('champ-accueil_description').fill('Plateforme d’accueil et de contrôle des accès de l’Académie.');
    await page.getByTestId('champ-pied').fill('AILCT — usage interne');
    await page.getByTestId('hex-primary').fill('#0C2D66'); await page.getByTestId('hex-secondary').fill('#1F5FBF'); await page.getByTestId('hex-accent').fill('#B8860B');
    await page.getByTestId('enregistrer-identite').click();
    await expect(page.getByTestId('identite-personnalisee')).toContainText('AILCT');
    await page.getByTestId('fichier-logo').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: logoPng() });
    await page.getByTestId('envoyer-logo').click();
    await expect(page.getByTestId('logo-actuel')).toBeVisible();
    await page.screenshot({ path: 'test-results/a03-admin-identite-ailct.png', fullPage: true });

    // Accueil : désactivé par défaut pour une installation institutionnelle (la racine conduit à la connexion), activable depuis l'écran Identité.
    await expect(page.getByTestId('etat-accueil')).toContainText('désactivée');
    const closed = await browser.newContext({ baseURL: info.project.use.baseURL });
    const closedPage = await closed.newPage();
    await closedPage.goto('/');
    await expect(closedPage).toHaveURL(/\/login$/);
    await page.getByTestId('accueil-actif').check();
    await page.getByTestId('enregistrer-accueil').click();
    await expect(page.getByTestId('etat-accueil')).toContainText('activée');
    await page.screenshot({ path: 'test-results/a065-identite-accueil-active.png', fullPage: true });
    await closedPage.goto('/');
    await expect(closedPage.getByTestId('accueil-institution')).toBeVisible();
    await closed.close();

    // L'espace d'administration porte l'identité de l'institution.
    await page.goto('/administration');
    await expect(page.locator('.admin-brand')).toContainText('AILCT');
    await expect(page.locator('.admin-brand img')).toHaveAttribute('src', /\/institution\/logo/);
    await page.screenshot({ path: 'test-results/a03-admin-ailct-1280.png' });

    // Pages publiques : trois formats, aucune mention d'IKOMA visible, aucun débordement.
    const visitor = await browser.newContext({ baseURL: info.project.use.baseURL });
    const pub = await visitor.newPage();
    for (const [width, height] of sizes) {
      await pub.setViewportSize({ width, height });
      await pub.goto('/');
      await expect(pub.getByTestId('accueil-institution')).toBeVisible();
      await expect(pub.getByRole('heading', { level: 1 })).toContainText('Académie Internationale de Lutte Contre le Terrorisme');
      expect(await pub.locator('body').innerText()).not.toMatch(/IKOMA/i);
      await noHorizontalScroll(pub, `accueil ${width}`);
      await pub.screenshot({ path: `test-results/a03-accueil-ailct-${width}x${height}.png`, fullPage: true });
      await pub.goto('/login');
      await expect(pub.getByText('Bienvenue à AILCT')).toBeVisible();
      expect(await pub.locator('body').innerText()).not.toMatch(/IKOMA/i);
      await expect(pub).toHaveTitle(/AILCT/);
      expect(await pub.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--inst-primary').trim().toUpperCase())).toBe('#0C2D66');
      await noHorizontalScroll(pub, `connexion ${width}`);
      await pub.screenshot({ path: `test-results/a03-connexion-ailct-${width}x${height}.png`, fullPage: true });
    }

    // Les parcours de l'agent et du superviseur gardent leurs droits ; seule l'identité affichée change.
    for (const [role, account, path, width, height] of [['agent', agent, '/espace', 1024, 768], ['superviseur', desk.supervisor, '/pilotage', 390, 844]] as const) {
      const ctx = await browser.newContext({ baseURL: info.project.use.baseURL, viewport: { width, height } });
      const p = await ctx.newPage();
      await loginWithSecondFactor(p, account.email, account.password, account.secret);
      await expect(p).toHaveURL(new RegExp(path + '$'));
      await expect(p.locator(role === 'agent' ? '.agent-brand' : '.pilotage-brand')).toContainText('AILCT');
      await noHorizontalScroll(p, role);
      await p.screenshot({ path: `test-results/a03-${role}-ailct-${width}x${height}.png` });
      await ctx.close();
    }
    await visitor.close();
  } finally {
    // Remet toujours l'installation de recette à l'identité IKOMA ACCESS standard.
    await page.goto('/administration/identite');
    if (await page.getByTestId('ouvrir-restauration').isVisible()) {
      await page.getByTestId('ouvrir-restauration').click();
      await page.getByTestId('confirmation-restauration').fill('RESTAURER IKOMA');
      await page.getByTestId('confirmer-restauration').click();
      await expect(page.getByTestId('identite-standard')).toBeVisible();
    }
  }
  const standard = await browser.newContext({ baseURL: info.project.use.baseURL });
  const home = await standard.newPage();
  await home.goto('/');
  await expect(home.getByRole('heading', { level: 1 })).toContainText('Contrôlez les accès.');
  await home.goto('/login');
  await expect(home.getByText('Bienvenue sur IKOMA ACCESS')).toBeVisible();
  await expect(home).toHaveTitle(/IKOMA ACCESS/);
  await standard.close();
});
