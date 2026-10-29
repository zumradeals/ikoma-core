import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from '../e2e/otp';
const fx = JSON.parse(readFileSync('.secrets/agent-v2-fixtures.json', 'utf8')) as { email: string; password: string; enrolCode: string; names: Record<string, string>; freeBadges: string[]; admin: { email: string; password: string; secret: string } };
const PIN = '583614';
const sizes = [[1440, 900], [1024, 768], [768, 1024], [390, 844]];
async function captures(page: Page, screen: string) {
  for (const [width, height] of sizes) {
    await page.setViewportSize({ width, height });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${screen} à ${width}px`).toBe(true);
    await page.screenshot({ path: `test-results/agent-v2-captures/${screen}-${width}x${height}.png`, fullPage: true });
  }
}
async function open(page: Page, path: string) {
  await page.goto(path);
  const enrol = page.getByTestId('ecran-enrolement'); const lock = page.getByTestId('ecran-verrou');
  await enrol.or(lock).first().waitFor();
  if (await enrol.isVisible()) {
    await page.getByTestId('code-enrolement').fill(fx.enrolCode);
    await page.getByTestId('pin').fill(PIN); await page.getByTestId('pin-confirmation').fill(PIN);
    await page.getByTestId('enroler').click();
  } else { await page.getByTestId('pin-deverrouillage').fill(PIN); await page.getByTestId('deverrouiller').click(); }
  await expect(page.getByTestId('agent-layout')).toBeVisible();
}
async function identity(page: Page, name: string) {
  await page.getByLabel(/Nom du visiteur/).fill(name);
  await page.getByRole('button', { name: new RegExp(name) }).first().click();
  await page.getByRole('button', { name: 'C’est bien cette personne' }).click();
  await expect(page.getByText('Étape 3 sur 7')).toBeVisible();
}

test('Agent V2 : parcours réel, pièce désactivée/activée, quatre formats et écrans associés', async ({ browser }, info) => {
  const adminContext = await browser.newContext({ baseURL: info.project.use.baseURL });
  const agentContext = await browser.newContext({ baseURL: info.project.use.baseURL, permissions: ['camera'], viewport: { width: 1024, height: 768 } });
  const admin = await adminContext.newPage(); const page = await agentContext.newPage();
  let original: boolean | undefined;
  async function setDocument(value: boolean) {
    await admin.goto('/administration/postes');
    const box = admin.getByTestId('reglage-piece-identite');
    if (original === undefined) original = await box.isChecked();
    await box.setChecked(value);
    const saved = admin.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname === '/administration/postes/reglages');
    await admin.getByRole('button', { name: 'Enregistrer les réglages' }).click();
    expect((await saved).status()).toBeLessThan(400);
    await expect(box).toBeChecked({ checked: value });
  }
  try {
    await loginWithSecondFactor(admin, fx.admin.email, fx.admin.password, fx.admin.secret);
    await setDocument(false);
    await page.goto('/login'); await page.getByLabel('Adresse électronique').fill(fx.email); await page.getByLabel('Mot de passe', { exact: true }).fill(fx.password); await page.getByRole('button', { name: 'Se connecter' }).click();
    await expect(page).toHaveURL(/\/espace$/);
    await expect(page.getByTestId('agent-layout')).toBeVisible();
    await captures(page, '01-mon-espace');
    await open(page, '/poste');
    await captures(page, '02-etape-1-recherche');
    const toggle = page.getByTestId('menu-agent-toggle');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false'); await toggle.click();
    await expect(page.getByTestId('agent-nav-controle')).toBeVisible(); await toggle.click();
    await identity(page, fx.names.listed);
    await expect(page.getByTestId('bloc-piece')).toHaveCount(0);
    await page.getByLabel('Pièce d’identité contrôlée', { exact: true }).check();
    await page.getByRole('button', { name: 'Prendre la photo', exact: true }).click();
    await captures(page, '03-etape-3-piece-desactivee');
    await page.getByRole('button', { name: 'Enregistrer et passer à la décision' }).click();
    await page.getByRole('button', { name: /Valider/ }).click();
    await expect(page.getByText('Étape 5 sur 7')).toBeVisible();
    await captures(page, '04-etape-5-scan-association');
    await page.getByLabel(/QR illisible/).fill(fx.freeBadges[0]); await page.getByRole('button', { name: 'Associer ce badge' }).click();
    await expect(page.getByText('Étape 6 sur 7')).toBeVisible();
    await captures(page, '05-etape-6-remise');
    await page.getByRole('button', { name: /Autoriser l’accès/ }).click();
    await expect(page.getByRole('heading', { name: /Entrée enregistrée/ })).toBeVisible();
    await captures(page, '06-etape-7-entree');
    await page.getByRole('button', { name: 'Visiteur suivant' }).click();
    await open(page, '/groupe'); await captures(page, '07-groupes-cars');
    await open(page, '/controle'); await page.getByTestId('choix-point').getByRole('button').first().click();
    await captures(page, '08-controle-site');
    await page.goto('/securite');
    if (await page.getByLabel('Mot de passe', { exact: true }).isVisible()) {
      await page.getByLabel('Mot de passe', { exact: true }).fill(fx.password);
      await page.getByRole('button', { name: 'Confirmer', exact: true }).click();
    }
    await expect(page.getByRole('heading', { name: 'Sécuriser mon compte' })).toBeVisible();
    await captures(page, '09-securite');
    await setDocument(true);
    await open(page, '/poste');
    const synced = page.waitForResponse(r => new URL(r.url()).pathname === '/poste/api/sync' && r.status() === 200);
    await page.getByTestId('synchroniser').click(); await synced;
    await identity(page, fx.names.second);
    await expect(page.getByTestId('bloc-piece')).toBeVisible();
    await page.getByLabel('Pièce d’identité contrôlée', { exact: true }).check();
    await page.getByRole('button', { name: 'Prendre la photo', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Enregistrer et passer à la décision' })).toBeDisabled();
    await page.getByTestId('piece-type').selectOption('cni'); await page.getByTestId('photo-piece').click();
    await expect(page.getByTestId('piece-numero')).toBeEmpty();
    await expect(page.getByRole('button', { name: 'Enregistrer et passer à la décision' })).toBeEnabled();
    await captures(page, '10-etape-3-piece-activee');
    await page.getByRole('button', { name: 'Enregistrer et passer à la décision' }).click();
    await expect(page.getByText('Étape 4 sur 7')).toBeVisible();
    await page.getByTestId('verrouiller').click(); await expect(page.getByTestId('ecran-verrou')).toBeVisible();
    await captures(page, '11-verrouillage');
  } finally {
    if (original !== undefined) await setDocument(original);
    await agentContext.close(); await adminContext.close();
  }
});
