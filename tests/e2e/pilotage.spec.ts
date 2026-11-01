import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Fixtures = { email: string; password: string; enrolCode3: string; poste3: string; pilotageNames: string[]; pilotageBadges: string[]; supervisor: { email: string; password: string; secret: string } };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as Fixtures;
const shots = process.env.L8_CAPTURES === '1';
const TABLET_PIN = '739261';
const EVAC_PIN = '583614';

// Deux appareils : le smartphone du superviseur (380 px) et la tablette d'un agent d'accueil, qui travaille de son côté.
let phone: BrowserContext; let tablet: BrowserContext;
let sup: Page;
test.describe.configure({ mode: 'serial' });

const shot = async (page: Page, name: string) => { if (shots) await page.screenshot({ path: `docs/captures/L8/${name}.png`, fullPage: true }); };
const button = (page: Page, name: string | RegExp) => page.getByRole('button', { name });
const noOverflow = async (page: Page) => expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
const counter = (page: Page, key: string) => page.getByTestId('compteur-' + key).getByTestId('valeur');

test.beforeAll(async ({ browser }, info) => {
  test.setTimeout(180_000);
  const baseURL = info.project.use.baseURL;
  phone = await browser.newContext({ baseURL, viewport: { width: 380, height: 800 }, hasTouch: true, isMobile: true });
  sup = await phone.newPage();
  await loginWithSecondFactor(sup, fx.supervisor.email, fx.supervisor.password, fx.supervisor.secret);
  await expect(sup).toHaveURL(/\/pilotage$/);
  tablet = await browser.newContext({ baseURL, viewport: { width: 1024, height: 768 }, permissions: ['camera'] });
  const agent = await tablet.newPage();
  await agent.goto('/login'); await agent.getByLabel('Adresse électronique').fill(fx.email); await agent.getByLabel('Mot de passe', { exact: true }).fill(fx.password); await agent.getByRole('button', { name: 'Se connecter' }).click();
  await expect(agent).toHaveURL(/\/espace/);
  await agent.close();
});
test.afterAll(async () => { await phone.close(); await tablet.close(); });

async function openDesk(page: Page): Promise<void> {
  const response = await page.goto('/poste');
  expect(response?.status(), `Réponse HTTP /poste : ${await page.locator('body').innerText().catch(() => 'page indisponible')}`).toBe(200);
  const enrol = page.getByTestId('ecran-enrolement'); const lock = page.getByTestId('ecran-verrou'); const ready = page.getByText('Étape 1 sur 7');
  await enrol.or(lock).or(ready).first().waitFor();
  if (await enrol.isVisible()) { await page.getByTestId('code-enrolement').fill(fx.enrolCode3); await page.getByTestId('pin').fill(TABLET_PIN); await page.getByTestId('pin-confirmation').fill(TABLET_PIN); await page.getByTestId('enroler').click(); }
  else if (await lock.isVisible()) { await page.getByTestId('pin-deverrouillage').fill(TABLET_PIN); await page.getByTestId('deverrouiller').click(); }
  await expect(ready).toBeVisible();
}
async function toDecision(page: Page, name: string) {
  await page.getByLabel(/Nom du visiteur/).fill(name); await button(page, new RegExp(name)).first().click();
  await button(page, 'C’est bien cette personne').click();
  await page.getByLabel('Pièce d’identité contrôlée').check(); await button(page, 'Prendre la photo').click(); await button(page, 'Enregistrer et passer à la décision').click();
}
async function admit(page: Page, name: string, badge: string) {
  await toDecision(page, name);
  await button(page, /Valider/).click();
  await page.getByLabel(/QR illisible/).fill(badge); await button(page, 'Associer ce badge').click();
  await button(page, /Autoriser l’accès/).click();
  await expect(page.getByRole('heading', { name: /Entrée enregistrée/ })).toBeVisible();
  await button(page, 'Visiteur suivant').click();
}
async function refuse(page: Page, name: string, motif: string) {
  await toDecision(page, name);
  await button(page, /Refuser/).click(); await page.getByLabel(/Motif du refus/).fill(motif); await button(page, /Confirmer le refus/).click();
  await expect(page.getByRole('heading', { name: /Accès refusé/ })).toBeVisible();
  await button(page, 'Visiteur suivant').click();
}

test('un compteur et une alerte créés par un autre poste apparaissent en moins de 5 secondes sur le smartphone', async () => {
  test.setTimeout(180_000);
  await sup.goto('/pilotage');
  await expect(sup.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible(); await noOverflow(sup);
  const before = { presents: Number(await counter(sup, 'presents').innerText()), arrives: Number(await counter(sup, 'arrives').innerText()), refuses: Number(await counter(sup, 'refuses').innerText()) };
  await shot(sup, 'l8-tableau-avant');
  const agent = await tablet.newPage();
  await openDesk(agent);
  await admit(agent, fx.pilotageNames[0], fx.pilotageBadges[0]);
  await expect(agent.getByTestId('en-attente')).toHaveText('0', { timeout: 30_000 });
  const t0 = Date.now();
  await expect(counter(sup, 'presents')).toHaveText(String(before.presents + 1), { timeout: 5000 });
  await expect(counter(sup, 'arrives')).toHaveText(String(before.arrives + 1), { timeout: 5000 });
  expect(Date.now() - t0).toBeLessThan(5000);
  await refuse(agent, fx.pilotageNames[1], 'Pièce d’identité illisible');
  await expect(agent.getByTestId('en-attente')).toHaveText('0', { timeout: 30_000 });
  await expect(counter(sup, 'refuses')).toHaveText(String(before.refuses + 1), { timeout: 5000 });
  await expect(sup.getByTestId('alerte-refus').first()).toBeVisible({ timeout: 5000 });
  await expect(sup.getByTestId('alerte-refus').first()).toContainText('Haute'); await shot(sup, 'l8-tableau-alerte');
  await expect(sup.getByTestId('situation')).not.toHaveAttribute('data-level', 'calme'); await expect(sup.getByTestId('situation-titre')).toContainText(/alerte/);
  await expect(sup.getByTestId('terrain-litiges')).toHaveAttribute('data-warn', 'true');
  await noOverflow(sup);
  await agent.close();
});

test('alertes : voir, prendre en compte, trier par gravité', async () => {
  await sup.getByTestId('nav-alertes').click();
  // On attend la page des alertes elle-même : le tableau de bord a aussi un titre « Alertes à traiter » et n'en montre que cinq.
  await expect(sup).toHaveURL(/\/pilotage\/alertes(\?|$)/); await expect(sup.getByRole('heading', { name: 'Alertes', exact: true })).toBeVisible(); await expect(sup.getByTestId('liste-alertes')).toBeVisible(); await noOverflow(sup);
  const severities = await sup.locator('[data-testid^="alerte-"]').evaluateAll(nodes => nodes.map(n => n.getAttribute('data-severity')));
  const rank: Record<string, number> = { critique: 0, haute: 1, normale: 2 };
  expect(severities.map(s => rank[s!])).toEqual([...severities.map(s => rank[s!])].sort((a, b) => a - b));
  await shot(sup, 'l8-alertes');
  const target = sup.getByTestId('alerte-refus').first();
  await expect(target.getByTestId('prendre-en-compte')).toBeVisible();
  await expect.poll(async () => (await target.getByTestId('prendre-en-compte').boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(56);
  const before = await sup.locator('[data-testid^="alerte-"]').count();
  await target.getByTestId('prendre-en-compte').click();
  await expect(sup.locator('[data-testid^="alerte-"]')).toHaveCount(before - 1);
  await sup.getByRole('link', { name: 'Prises en compte' }).click();
  await expect(sup.getByTestId('alerte-refus')).toHaveCount(1); await expect(sup.getByTestId('alerte-refus')).toContainText('prise en compte');
});

test('présents : recherche, fiche, annulation de badge en deux temps, personne « à rebadger »', async () => {
  await sup.getByTestId('nav-presents').click();
  await expect(sup.getByRole('heading', { name: 'Personnes présentes' })).toBeVisible(); await noOverflow(sup);
  await sup.getByTestId('recherche').fill('pilotage fictif 01');
  await expect(sup.getByTestId('liste-presents').getByRole('link', { name: new RegExp(fx.pilotageNames[0]) })).toBeVisible();
  await shot(sup, 'l8-presents');
  await sup.getByTestId('liste-presents').getByRole('link', { name: new RegExp(fx.pilotageNames[0]) }).click();
  await expect(sup.getByTestId('nom')).toHaveText(fx.pilotageNames[0]); await expect(sup.getByTestId('badge')).toContainText(fx.pilotageBadges[0]);
  await sup.getByTestId('annuler-debut').click();
  await expect(sup.getByTestId('annuler-suite')).toBeDisabled(); // pas de motif : on ne peut pas continuer
  await sup.getByTestId('annuler-motif').fill('Badge perdu par le visiteur');
  await sup.getByTestId('annuler-suite').click();
  await expect(sup.getByTestId('annuler-confirmer')).toBeVisible(); await shot(sup, 'l8-annulation-confirmation');
  await sup.getByTestId('annuler-confirmer').click();
  await expect(sup.getByTestId('a-rebadger')).toContainText('À rebadger'); await expect(sup.getByTestId('annulation')).toHaveCount(0); await shot(sup, 'l8-a-rebadger');
  await noOverflow(sup);
  await sup.goto('/pilotage');
  await expect(sup.getByTestId('alerte-badge_annule').first()).toContainText('rebadger', { timeout: 5000 });
});

test('litiges : décision « autoriser avec conditions », visible à l’accueil', async () => {
  await sup.getByTestId('nav-litiges').click();
  await expect(sup.getByRole('heading', { name: 'Cas litigieux' })).toBeVisible(); await noOverflow(sup);
  const row = sup.getByTestId('liste-litiges').locator('li', { hasText: fx.pilotageNames[1] });
  await expect(row).toContainText('Pièce d’identité illisible'); await shot(sup, 'l8-litiges');
  await expect(row.getByTestId('autoriser')).toBeDisabled(); // les conditions sont obligatoires
  await row.locator('textarea').fill('Pièce vérifiée à vue par le superviseur');
  await row.getByTestId('autoriser').click();
  await expect(row.getByTestId('decision')).toContainText('Autorisé avec conditions'); await shot(sup, 'l8-litiges-decide');
  // la tablette de l'accueil reçoit la décision à la synchronisation : la fiche porte la mention et la personne est de nouveau « attendue »
  const agent = await tablet.newPage();
  await openDesk(agent);
  await button(agent, 'Synchroniser maintenant').click();
  await expect(agent.getByTestId('en-attente')).toHaveText('0', { timeout: 30_000 });
  await agent.getByLabel(/Nom du visiteur/).fill(fx.pilotageNames[1]); await button(agent, new RegExp(fx.pilotageNames[1])).first().click();
  await expect(agent.getByTestId('mention-autorisation')).toContainText('Pièce vérifiée à vue par le superviseur');
  await agent.close();
});

test('groupes et journal sur le smartphone', async () => {
  await sup.goto('/pilotage/groupes');
  await expect(sup.getByRole('heading', { name: 'Groupes et cars' })).toBeVisible(); await noOverflow(sup); await shot(sup, 'l8-groupes');
  await sup.goto('/administration/journal');
  await expect(sup.getByRole('heading', { name: 'Administration · Journal' })).toBeVisible(); await shot(sup, 'l8-journal');
  await noOverflow(sup);
});

test('évacuation : liste par zone, impression A4 par zone, copie chiffrée consultable hors ligne avec sa fraîcheur', async () => {
  test.setTimeout(120_000);
  await sup.goto('/pilotage/evacuation');
  await expect(sup.getByTestId('total')).toBeVisible(); await noOverflow(sup);
  const total = Number((await sup.getByTestId('total').innerText()).match(/\d+/)![0]);
  await expect(sup.getByTestId('personne').first()).toBeVisible(); await shot(sup, 'l8-evacuation');
  // conserver chiffré pour l'usage hors ligne
  await sup.getByTestId('evac-pin-creation').fill(EVAC_PIN); await sup.getByTestId('evac-pin-confirmation').fill(EVAC_PIN); await sup.getByTestId('evac-conserver-bouton').click();
  await expect(sup.getByTestId('info')).toContainText('chiffrée');
  // rien de lisible dans IndexedDB
  const dump = await sup.evaluate(async () => {
    const db = await new Promise<IDBDatabase>((resolve, reject) => { const request = indexedDB.open('ikoma-pilotage'); request.onsuccess = () => resolve(request.result); request.onerror = () => reject(request.error); });
    const out: string[] = [];
    for (const name of Array.from(db.objectStoreNames)) {
      const rows = await new Promise<unknown[]>(resolve => { const query = db.transaction(name).objectStore(name).getAll(); query.onsuccess = () => resolve(query.result); });
      out.push(JSON.stringify(rows, (_key, value) => (value instanceof Uint8Array ? Array.from(value).map(c => String.fromCharCode(c)).join('') : typeof CryptoKey !== 'undefined' && value instanceof CryptoKey ? 'cle' : value)));
    }
    db.close();
    return out.join('\n');
  });
  expect(dump.length).toBeGreaterThan(500);
  for (const secret of [fx.pilotageNames[0], 'Organisation fictive de recette', EVAC_PIN, fx.supervisor.email]) expect(dump, secret).not.toContain(secret);
  // impression : une page A4 par zone, sans navigation ni boutons
  await sup.emulateMedia({ media: 'print' });
  await expect(sup.getByRole('navigation', { name: 'Navigation du pilotage' })).toBeHidden(); await expect(sup.getByTestId('imprimer')).toBeHidden();
  const zones = await sup.locator('.zone-sheet').count();
  const pdf = await sup.pdf({ format: 'A4', printBackground: true });
  const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) ?? []).length;
  expect(zones).toBeGreaterThan(0); expect(pages).toBeGreaterThanOrEqual(zones);
  await sup.emulateMedia({ media: 'screen' });
  // attendre le service worker, couper le réseau, rouvrir
  await sup.evaluate(async () => { await navigator.serviceWorker.ready; if (!navigator.serviceWorker.controller) await new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true })); });
  await phone.setOffline(true);
  const off = await phone.newPage();
  await off.goto('/pilotage/evacuation'); // coquille publique du cache de l'application
  await expect(off.getByTestId('evac-verrou')).toBeVisible(); await expect(off.getByText(fx.pilotageNames[0])).toHaveCount(0); await shot(off, 'l8-evacuation-verrouillee');
  await off.getByTestId('evac-pin').fill('111222'); await off.getByTestId('evac-deverrouiller').click();
  await expect(off.getByTestId('erreur-evac')).toContainText('PIN incorrect');
  await off.getByTestId('evac-pin').fill(EVAC_PIN); await off.getByTestId('evac-deverrouiller').click();
  await expect(off.getByTestId('total')).toContainText(String(total));
  await expect(off.getByTestId('fraicheur')).toContainText('Situation connue à'); await expect(off.getByTestId('fraicheur')).toContainText('hors ligne'); await shot(off, 'l8-evacuation-hors-ligne');
  await expect(off.getByText(fx.pilotageNames[0])).toBeVisible();
  await noOverflow(off);
  await off.close();
  await phone.setOffline(false);
});

test('la déconnexion efface la copie locale du superviseur', async () => {
  await sup.goto('/espace');
  const exists = () => sup.evaluate(async () => (await indexedDB.databases()).some(d => d.name === 'ikoma-pilotage'));
  expect(await exists()).toBe(true);
  await sup.locator('.pilotage-account summary').click();
  await sup.getByRole('button', { name: 'Se déconnecter' }).click();
  await expect(sup).toHaveURL(/\/(login|$)/);
  await expect.poll(() => exists()).toBe(false);
});
