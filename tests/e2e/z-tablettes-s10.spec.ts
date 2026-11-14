// S10 — parcours de tablettes enrôlées : poste d'accueil (P2) et soute (P5). Données fictives de la recette (tablettes et badges créés par test:desk-fixtures).
// P2 : opérations contextuelles, visites attendues, refus et incidents, passage vers la restitution. Soute : sens Entrée / Sortie choisi avant le scan,
// habilitations appliquées dans les deux sens, aucune fonction d'accueil, mouvements visibles au journal de supervision.
// Ce fichier s'exécute après z-groupes (ordre alphabétique) : ses contrôles refusés ne comptent pas dans les alertes comptées plus tôt.
import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
type Fixtures = { email: string; password: string; event: string; supervisor: Account; s10: { enrolCodeP2: string; enrolCodeSoute: string; posteP2: string; posteSoute: string; badges: string[]; names: string[] } };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as Fixtures;
const PIN = '615243';

test.describe.configure({ mode: 'serial' });
test.use({ actionTimeout: 15_000 });

let p2: BrowserContext; let soute: BrowserContext;
async function tablet(browser: import('@playwright/test').Browser, baseURL: string | undefined): Promise<BrowserContext> {
  const context = await browser.newContext({ baseURL, viewport: { width: 1024, height: 768 }, permissions: ['camera'] });
  const page = await context.newPage();
  await page.goto('/login'); await page.getByLabel('Adresse électronique').fill(fx.email); await page.getByLabel('Mot de passe', { exact: true }).fill(fx.password); await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/espace/);
  await page.close();
  return context;
}
test.beforeAll(async ({ browser }, info) => {
  const baseURL = info.project.use.baseURL;
  p2 = await tablet(browser, baseURL);
  soute = await tablet(browser, baseURL);
});
test.afterAll(async () => { await p2.close(); await soute.close(); });

/** Enrôle la tablette si besoin, sinon la déverrouille, puis attend l'écran demandé. */
async function open(page: Page, url: string, code: string, ready: ReturnType<Page['getByTestId']>): Promise<void> {
  await page.goto(url);
  const enrol = page.getByTestId('ecran-enrolement'); const lock = page.getByTestId('ecran-verrou');
  await enrol.or(lock).or(ready).first().waitFor();
  if (await enrol.isVisible()) {
    await page.getByTestId('code-enrolement').fill(code); await page.getByTestId('pin').fill(PIN); await page.getByTestId('pin-confirmation').fill(PIN); await page.getByTestId('enroler').click();
  } else if (await lock.isVisible()) {
    await page.getByTestId('pin-deverrouillage').fill(PIN); await page.getByTestId('deverrouiller').click();
  }
  await expect(ready).toBeVisible();
}
async function choosePoint(page: Page, name: RegExp): Promise<void> {
  if (await page.getByTestId('choix-point').isVisible()) await page.getByRole('button', { name }).first().click();
  else { await page.getByTestId('changer-point').click(); await page.getByRole('button', { name }).first().click(); }
  await expect(page.getByTestId('point')).toHaveText(name);
}
async function scan(page: Page, code: string): Promise<void> {
  await page.getByTestId('code-manuel').fill(code);
  await page.getByTestId('valider-code').click();
}

test('poste d’accueil P2 : opérations contextuelles, visites attendues, refus et incidents, passage vers la restitution', async () => {
  const page = await p2.newPage();
  await open(page, '/poste', fx.s10.enrolCodeP2, page.getByText('Étape 1 sur 7'));
  await expect(page.getByTestId('operations-accueil')).toBeVisible();
  await expect(page.getByTestId('operation-controle')).toHaveAttribute('href', '/controle?mode=controle');
  await expect(page.getByTestId('operation-sortie')).toHaveAttribute('href', '/controle?mode=sortie');
  await expect(page.getByTestId('operation-restitution')).toContainText('distinct de la sortie');
  // visites attendues : aucune visite annoncée dans l'événement de recette, l'état vide est explicite
  await page.getByTestId('ouvrir-attendus').click();
  await expect(page.getByTestId('aucune-attendue')).toBeVisible();
  await page.getByTestId('ouvrir-attendus').click();
  // refus et incidents du poste
  await page.getByTestId('ouvrir-incidents').click();
  await expect(page.getByTestId('incidents-poste')).toBeVisible();
  await expect(page.getByTestId('aucun-controle-refuse')).toBeVisible();
  // la restitution s'ouvre directement dans son mode, distinct de la sortie
  await page.getByTestId('operation-restitution').click();
  await expect(page).toHaveURL(/\/controle\?mode=restitution/);
  const lock = page.getByTestId('ecran-verrou'); const ready = page.getByTestId('choix-point').or(page.getByTestId('point'));
  await lock.or(ready).first().waitFor();
  if (await lock.isVisible()) { await page.getByTestId('pin-deverrouillage').fill(PIN); await page.getByTestId('deverrouiller').click(); }
  await expect(ready).toBeVisible();
  if (await page.getByTestId('choix-point').isVisible()) await page.getByRole('button', { name: /Hall/ }).first().click();
  await expect(page.getByTestId('mode-restitution')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId('mode-sortie')).toBeVisible();
  await page.close();
});

test('soute P5 : sens Entrée / Sortie choisi avant le scan, habilitations dans les deux sens, aucune fonction d’accueil', async () => {
  const page = await soute.newPage();
  await open(page, '/controle', fx.s10.enrolCodeSoute, page.getByTestId('choix-point').or(page.getByTestId('point')));
  await choosePoint(page, /Salle principale/);
  // uniquement le contrôle individuel : ni sortie, ni restitution, ni accueil
  await expect(page.getByTestId('operations-autorisees')).toContainText('Contrôle d’un badge');
  await expect(page.getByTestId('mode-sortie')).toHaveCount(0);
  await expect(page.getByTestId('mode-restitution')).toHaveCount(0);
  await expect(page.locator('a[href="/poste"]')).toHaveCount(0);
  // le sens doit être choisi avant tout scan
  await expect(page.getByTestId('sens-requis')).toBeVisible();
  await page.getByTestId('code-manuel').fill(fx.s10.badges[0]);
  await expect(page.getByTestId('valider-code')).toBeDisabled();
  // Entrée
  await page.getByTestId('sens-entree').click();
  await scan(page, fx.s10.badges[0]);
  await expect(page.getByTestId('verdict')).toContainText('ACCÈS AUTORISÉ');
  await expect(page.getByTestId('sens-resultat')).toHaveText('Entrée');
  await page.getByTestId('suivant').click();
  // Sortie, avec un autre badge
  await page.getByTestId('sens-sortie').click();
  await scan(page, fx.s10.badges[1]);
  await expect(page.getByTestId('verdict')).toContainText('ACCÈS AUTORISÉ');
  await expect(page.getByTestId('sens-resultat')).toHaveText('Sortie');
  await page.getByTestId('suivant').click();
  // habilitation : la zone VIP n'est pas autorisée à la catégorie « Visiteur », dans le sens choisi
  await choosePoint(page, /Espace VIP/);
  await page.getByTestId('sens-entree').click();
  await scan(page, fx.s10.badges[0]);
  await expect(page.getByTestId('zone-non-autorisee')).toBeVisible();
  await expect(page.getByTestId('sens-resultat')).toHaveText('Entrée');
  await page.getByTestId('suivant').click();
  // refus motivés et traçabilité, consultables sur le poste
  await page.getByTestId('ouvrir-incidents').click();
  await expect(page.getByTestId('incidents-poste')).toContainText('Zone non autorisée');
  await page.close();
});

test('le superviseur retrouve les passages de la soute, avec leur sens, dans le journal des mouvements', async ({ page }) => {
  await loginWithSecondFactor(page, fx.supervisor.email, fx.supervisor.password, fx.supervisor.secret);
  await page.goto(`/pilotage/mouvements?event=${fx.event}&type=controle`);
  const list = page.getByTestId('liste-mouvements');
  await expect(list).toContainText('entrée');
  await expect(list).toContainText('sortie');
  await expect(list).toContainText('Zone non autorisée');
});
