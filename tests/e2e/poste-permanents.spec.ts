import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Holder = { id: string; name: string; identifier: string; qr: string };
type Fixtures = {
  agent: { email: string; password: string }; admin: { email: string; password: string; secret: string }; enrolCode: string; event: { id: string; name: string }; hall: string; poste: string;
  holders: { valid: Holder; none: Holder; revoked: Holder; conflict: Holder; leaver: Holder };
};
const fx = JSON.parse(readFileSync('.secrets/permanent-fixtures.json', 'utf8')) as Fixtures;
const PIN = '739261';

let tablet: BrowserContext;
test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }, info) => {
  tablet = await browser.newContext({ baseURL: info.project.use.baseURL, viewport: { width: 1024, height: 768 }, permissions: ['camera'] });
  const page = await tablet.newPage();
  await page.goto('/login'); await page.getByLabel('Adresse électronique').fill(fx.agent.email); await page.getByLabel('Mot de passe', { exact: true }).fill(fx.agent.password); await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/espace/);
  await page.close();
});
test.afterAll(async () => { await tablet.close(); });

const button = (page: Page, name: string | RegExp) => page.getByRole('button', { name });

/** Enrôle la tablette la première fois, la déverrouille ensuite (même PIN), puis attend l'écran de contrôle. */
async function open(page: Page): Promise<void> {
  await page.goto('/controle');
  const enrol = page.getByTestId('ecran-enrolement'); const lock = page.getByTestId('ecran-verrou'); const ready = page.getByTestId('choix-point').or(page.getByTestId('point'));
  await enrol.or(lock).or(ready).first().waitFor();
  if (await enrol.isVisible()) {
    await page.getByTestId('code-enrolement').fill(fx.enrolCode); await page.getByTestId('pin').fill(PIN); await page.getByTestId('pin-confirmation').fill(PIN); await page.getByTestId('enroler').click();
  } else if (await lock.isVisible()) {
    await page.getByTestId('pin-deverrouillage').fill(PIN); await page.getByTestId('deverrouiller').click();
  }
  await expect(ready).toBeVisible();
}
async function unlockIfLocked(page: Page): Promise<void> {
  const lock = page.getByTestId('ecran-verrou');
  if (await lock.isVisible().catch(() => false)) { await page.getByTestId('pin-deverrouillage').fill(PIN); await page.getByTestId('deverrouiller').click(); }
}
async function choosePoint(page: Page, name: string): Promise<void> {
  if (await page.getByTestId('choix-point').isVisible()) await button(page, new RegExp(name)).first().click();
  else if ((await page.getByTestId('point').innerText()) !== name) { await page.getByTestId('changer-point').click(); await button(page, new RegExp(name)).first().click(); }
  await expect(page.getByTestId('point')).toHaveText(name);
}
async function scan(page: Page, mode: 'controle' | 'sortie', code: string): Promise<void> {
  await unlockIfLocked(page);
  await page.getByTestId(mode === 'controle' ? 'mode-controle' : 'mode-sortie').click();
  await page.getByTestId('code-manuel').fill(code); await page.getByTestId('valider-code').click();
  await expect(page.getByTestId('resultat')).toBeVisible();
}
const next = async (page: Page) => { await page.getByTestId('suivant').click(); await expect(page.getByTestId('resultat')).toHaveCount(0); };

async function counters(admin: Page): Promise<{ received: number; applied: number; conflicts: number }> {
  await admin.goto('/administration/postes');
  await expect(admin.getByRole('heading', { name: 'Administration · Postes' })).toBeVisible({ timeout: 20_000 });
  const text = await admin.locator(`xpath=//h2[contains(., "${fx.poste}")]/following-sibling::p[@data-testid="compteurs"]`).innerText({ timeout: 10_000 });
  const [received, applied, conflicts] = text.match(/\d+/g)!.map(Number);
  return { received, applied, conflicts };
}
type Row = { identifier: string; decision: string; reason: string };
async function decisions(admin: Page): Promise<Row[]> {
  await admin.goto('/administration/habilitations?event=' + fx.event.id);
  await expect(admin.getByTestId('page-habilitations')).toBeVisible();
  // Les décisions sont dans les « Réglages avancés », repliés par défaut.
  await admin.getByTestId('reglages-avances').locator('summary').click();
  const rows = admin.locator('[data-testid="table-decisions"] tbody tr');
  await expect(rows.first()).toBeVisible();
  return (await rows.evaluateAll(trs => trs.map(tr => { const c = Array.from(tr.querySelectorAll('td')).map(td => (td.textContent ?? '').replace(/\s+/g, ' ').trim()); return { identifier: c[1], decision: c[3], reason: c[4] }; })));
}

test('confiance hors ligne : entrée autorisée, refus sans habilitation et carte révoquée, expiration en cours de session, sorties toujours possibles, rejeu sans doublon, serveur autorité finale', async ({ browser, baseURL }) => {
  test.setTimeout(300_000);
  // Première visite en ligne : enrôlement, copie de la situation (habilitations, politiques, cartes révoquées) et service worker.
  const first = await tablet.newPage();
  await open(first);
  await first.evaluate(() => navigator.serviceWorker.ready.then(() => true));
  // Une page n'est contrôlée par le service worker qu'après un rechargement : on le vérifie avant de couper le réseau.
  await open(first);
  await expect.poll(() => first.evaluate(() => !!navigator.serviceWorker.controller), { timeout: 30_000 }).toBe(true);
  await expect(first.getByTestId('en-attente')).toHaveText('0');
  await first.close();

  const adminContext = await browser.newContext({ baseURL, viewport: { width: 1440, height: 900 } });
  const admin = await adminContext.newPage();
  await loginWithSecondFactor(admin, fx.admin.email, fx.admin.password, fx.admin.secret);
  const before = await counters(admin);

  // Hors ligne, horloge de la page contrôlée : l'âge de la confiance se calcule sur ce temps.
  await tablet.setOffline(true);
  const page = await tablet.newPage();
  await page.clock.install();
  await open(page);
  await choosePoint(page, fx.poste);
  await expect(page.getByTestId('situation-connue')).toBeVisible();
  await expect(page.getByTestId('confiance-valide')).toContainText('encore');

  // 1. Entrée autorisée : confiance valide et habilitation explicite.
  await scan(page, 'controle', fx.holders.valid.qr);
  await expect(page.getByTestId('verdict')).toContainText('ACCÈS AUTORISÉ');
  await expect(page.getByTestId('connu-a')).toContainText('Hors ligne : situation connue à'); await next(page);
  // 2. Sans habilitation valide : refus. Carte révoquée connue localement : refus immédiat.
  await scan(page, 'controle', fx.holders.none.qr);
  await expect(page.getByTestId('verdict')).toContainText('ACCÈS REFUSÉ'); await expect(page.getByTestId('raison')).toContainText('Habilitation absente'); await next(page);
  await scan(page, 'controle', fx.holders.revoked.qr);
  await expect(page.getByTestId('verdict')).toContainText('ACCÈS REFUSÉ'); await expect(page.getByTestId('raison')).toContainText('Carte perdue'); await next(page);
  // 3. Un titulaire encore valide pour la tablette : l'autorité finale sera le serveur.
  await scan(page, 'controle', fx.holders.conflict.qr);
  await expect(page.getByTestId('verdict')).toContainText('ACCÈS AUTORISÉ'); await next(page);

  // 4. Le serveur révoque cette carte pendant que la tablette est hors ligne.
  await admin.goto(`/administration/titulaires/${fx.holders.conflict.id}/carte`);
  await admin.getByTestId('declarer-perte').click();
  await admin.getByTestId('cycle-motif').selectOption('volee');
  await admin.getByTestId('cycle-confirmation').check();
  await admin.getByTestId('cycle-valider').click();
  await expect(admin.getByTestId('statut-carte-1')).toContainText('Volée');

  // 5. La confiance expire PENDANT la session : plus aucune nouvelle entrée, bandeau explicite. Les sorties restent possibles.
  await page.clock.fastForward('40:00');
  await unlockIfLocked(page);
  await expect(page.getByTestId('confiance-expiree')).toContainText('EXPIRÉE');
  await scan(page, 'controle', fx.holders.valid.qr);
  await expect(page.getByTestId('verdict')).toContainText('ACCÈS REFUSÉ'); await expect(page.getByTestId('raison')).toContainText('Confiance hors ligne expirée'); await next(page);
  await scan(page, 'sortie', fx.holders.leaver.qr); // carte déclarée volée, titulaire présent : il doit pouvoir sortir
  await expect(page.getByTestId('verdict')).toContainText('SORTIE ENREGISTRÉE'); await next(page);
  await scan(page, 'sortie', fx.holders.valid.qr); // sortie de sécurité malgré la confiance expirée
  await expect(page.getByTestId('verdict')).toContainText('SORTIE ENREGISTRÉE'); await next(page);
  await expect(page.getByTestId('en-attente')).toHaveText('7');

  // 6. Retour du réseau : la première réponse est perdue, le renvoi ne doit rien doubler.
  let lost = false;
  await page.route('**/poste/api/sync', async route => {
    if (!lost) { lost = true; await route.fetch(); await route.abort('connectionreset'); } else await route.continue();
  });
  await tablet.setOffline(false);
  await button(page, 'Synchroniser maintenant').click();
  await expect(page.getByTestId('en-attente')).toHaveText('0', { timeout: 60_000 });
  expect(lost).toBe(true);
  const after = await counters(admin);
  expect(after.received - before.received).toBe(7);
  // Le serveur est l'autorité : l'entrée de la carte volée depuis la révocation est refusée et signalée en conflit ; le reste est appliqué une seule fois.
  expect(after.conflicts - before.conflicts).toBe(1);
  expect(after.applied - before.applied).toBe(6);

  const rows = await decisions(admin);
  const of = (h: Holder) => rows.filter(r => r.identifier === h.identifier);
  expect(of(fx.holders.valid).filter(r => r.decision === 'Entrée'), 'une seule entrée pour le titulaire habilité').toHaveLength(1);
  expect(of(fx.holders.valid).filter(r => r.decision === 'Sortie'), 'une seule sortie').toHaveLength(1);
  expect(of(fx.holders.valid).some(r => r.decision === 'Refus' && r.reason.includes('Confiance hors ligne expirée')), 'refus après expiration, journalisé').toBe(true);
  expect(of(fx.holders.leaver).some(r => r.decision === 'Sortie' && r.reason.includes('Carte volée')), 'sortie de la carte volée enregistrée').toBe(true);
  expect(of(fx.holders.conflict).some(r => r.decision === 'Refus' && r.reason.includes('Carte volée')), 'entrée refusée par le serveur').toBe(true);
  expect(of(fx.holders.conflict).some(r => r.decision === 'Entrée'), 'aucune entrée pour la carte révoquée').toBe(false);
  expect(of(fx.holders.none).some(r => r.reason.includes('Habilitation absente'))).toBe(true);
  expect(of(fx.holders.revoked).some(r => r.reason.includes('Carte perdue'))).toBe(true);
  await admin.screenshot({ path: 'test-results/a06-decisions-hors-ligne.png', fullPage: true });
  await page.screenshot({ path: 'test-results/a06-poste-confiance.png', fullPage: true });
  await adminContext.close();
});
