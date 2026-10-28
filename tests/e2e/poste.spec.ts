import { test, expect, type Browser, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

type Fixtures = { email: string; password: string; poste: string; names: Record<string, string>; takenBadge: string; vipBadges: string[]; freeBadges: string[]; qrSvg: string; qrBadge: string };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as Fixtures;
const shots = process.env.L5_CAPTURES === '1';
let storage = '';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }, info) => {
  const context = await browser.newContext({ baseURL: info.project.use.baseURL });
  const page = await context.newPage();
  await page.goto('/login'); await page.getByLabel('Adresse électronique').fill(fx.email); await page.getByLabel('Mot de passe', { exact: true }).fill(fx.password); await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/espace/);
  storage = await context.storageState().then(state => JSON.stringify(state));
  await context.close();
});

async function desk(browser: Browser, baseURL: string | undefined, viewport = { width: 1280, height: 800 }): Promise<Page> {
  const context = await browser.newContext({ baseURL, viewport, permissions: ['camera'], storageState: JSON.parse(storage) });
  const page = await context.newPage();
  await page.goto('/poste');
  await page.getByRole('button', { name: new RegExp(fx.poste) }).click();
  await expect(page.getByText('Étape 1 sur 7')).toBeVisible();
  return page;
}
const shot = async (page: Page, name: string) => { if (shots) await page.screenshot({ path: `docs/captures/L5/${name}.png`, fullPage: true }); };
const search = (page: Page, text: string) => page.getByLabel(/Nom du visiteur/).fill(text);
const pick = (page: Page, name: string) => page.getByRole('button', { name: new RegExp(name) }).first();
const button = (page: Page, name: string | RegExp) => page.getByRole('button', { name });

/** Du premier visiteur à l'identité et à la décision (étapes 1 à 4). */
async function toDecision(page: Page, word: string, name: string) {
  await search(page, word); await pick(page, name).click();
  await expect(page.getByRole('heading', { name: 'Vérifiez la fiche avec le visiteur' })).toBeVisible();
  await button(page, 'C’est bien cette personne').click();
  await page.getByLabel('Pièce d’identité contrôlée').check();
  await button(page, 'Prendre la photo').click();
  await button(page, 'Enregistrer et passer à la décision').click();
  await expect(page.getByText('Étape 4 sur 7')).toBeVisible();
}
async function manualBadge(page: Page, code: string) {
  await page.getByLabel(/QR illisible/).fill(code); await button(page, 'Associer ce badge').click();
}

test('un visiteur listé est traité en moins de 60 secondes, de la recherche à l’entrée', async ({ browser, baseURL }) => {
  const page = await desk(browser, baseURL);
  const started = Date.now();
  await shot(page, 'tablette-1-recherche');
  await search(page, 'dembele'); // sans accent : la recherche le tolère
  await expect(pick(page, fx.names.listed)).toBeVisible(); await shot(page, 'tablette-1-resultats');
  await pick(page, fx.names.listed).click();
  await expect(page.getByRole('heading', { name: 'Vérifiez la fiche avec le visiteur' })).toBeVisible(); await expect(page.getByText('Salle principale')).toBeVisible(); await shot(page, 'tablette-2-fiche');
  await button(page, 'C’est bien cette personne').click();
  await expect(page.getByText('Étape 3 sur 7')).toBeVisible();
  await expect(button(page, 'Enregistrer et passer à la décision')).toBeDisabled(); await expect(page.getByText('Cochez la pièce d’identité et prenez la photo.')).toBeVisible(); await shot(page, 'tablette-3-identite-vide');
  await page.getByLabel('Pièce d’identité contrôlée').check();
  await button(page, 'Prendre la photo').click(); await expect(page.getByAltText('Photo prise du visiteur')).toBeVisible(); await shot(page, 'tablette-3-identite');
  await button(page, 'Enregistrer et passer à la décision').click();
  await expect(page.getByRole('heading', { name: /Décision pour/ })).toBeVisible(); await shot(page, 'tablette-4-decision');
  await button(page, /Valider/).click();
  await expect(page.getByRole('heading', { name: /Scannez le badge/ })).toBeVisible(); await shot(page, 'tablette-5-badge');
  await manualBadge(page, fx.freeBadges[0].toLowerCase());
  await expect(page.getByRole('heading', { name: `Remettez le badge ${fx.freeBadges[0]}` })).toBeVisible(); await shot(page, 'tablette-6-remise');
  await button(page, /Autoriser l’accès/).click();
  await expect(page.getByRole('heading', { name: /Entrée enregistrée/ })).toBeVisible(); await shot(page, 'tablette-7-entree');
  const wall = (Date.now() - started) / 1000;
  const label = await page.getByTestId('chrono').innerText();
  const [, minutes, seconds] = label.match(/(\d+) min (\d+) s/)!;
  const shown = Number(minutes) * 60 + Number(seconds);
  expect(wall).toBeLessThan(60); expect(shown).toBeLessThan(60); expect(Math.abs(shown - wall)).toBeLessThanOrEqual(3);
  await expect(page.getByText('Objectif de 60 secondes tenu')).toBeVisible();
  await button(page, 'Visiteur suivant').click(); await expect(page.getByText('Étape 1 sur 7')).toBeVisible();
  await expect(page.getByText('Opérations en attente : 0')).toBeVisible(); await expect(page.getByText('En ligne')).toBeVisible();
  await page.context().close();
});

test('un VIP ressort en premier avec une alerte de couleur et passe par le même parcours', async ({ browser, baseURL }) => {
  const page = await desk(browser, baseURL);
  await search(page, fx.names.vip.split(' ').pop()!);
  await expect(page.getByText('Personnalité VIP : circuit prioritaire, sans file d’attente')).toBeVisible();
  await pick(page, fx.names.vip).click();
  await expect(page.getByText('Personnalité VIP : circuit prioritaire, traitement discret')).toBeVisible(); await expect(page.getByText('Espace VIP')).toBeVisible();
  await button(page, 'C’est bien cette personne').click();
  await page.getByLabel('Pièce d’identité contrôlée').check(); await button(page, 'Prendre la photo').click(); await button(page, 'Enregistrer et passer à la décision').click();
  await button(page, /Valider/).click();
  await manualBadge(page, fx.freeBadges[4]); // badge de catégorie Visiteur pour un VIP : refusé
  await expect(page.getByTestId('erreur')).toContainText('Badge de catégorie Visiteur, visiteur de catégorie VIP'); await expect(page.getByTestId('erreur')).toContainText('Prenez un badge de catégorie VIP.');
  await manualBadge(page, fx.vipBadges[0]);
  await expect(page.getByRole('heading', { name: `Remettez le badge ${fx.vipBadges[0]}` })).toBeVisible();
  await button(page, /Autoriser l’accès/).click(); await expect(page.getByRole('heading', { name: /Entrée enregistrée/ })).toBeVisible();
  await page.context().close();
});

test('un refus exige un motif, n’associe aucun badge et bloque la suite', async ({ browser, baseURL }) => {
  const page = await desk(browser, baseURL);
  await toDecision(page, 'refus', fx.names.refused);
  await button(page, /Refuser/).click();
  await expect(button(page, /Confirmer le refus/)).toBeDisabled(); await shot(page, 'tablette-4-refus-motif');
  await page.getByLabel(/Motif du refus/).fill('Pièce d’identité illisible');
  await button(page, /Confirmer le refus/).click();
  await expect(page.getByRole('heading', { name: /Accès refusé/ })).toBeVisible(); await expect(page.getByText('Aucun badge n’est associé')).toBeVisible(); await shot(page, 'tablette-4-refuse');
  await button(page, 'Visiteur suivant').click();
  await search(page, 'refus'); await pick(page, fx.names.refused).click(); await button(page, 'C’est bien cette personne').click();
  await page.getByLabel('Pièce d’identité contrôlée').check(); await button(page, 'Prendre la photo').click(); await button(page, 'Enregistrer et passer à la décision').click();
  await expect(page.getByTestId('erreur')).toContainText('L’accès a été refusé à ce visiteur');
  await page.context().close();
});

test('un visiteur hors période n’est validé qu’avec l’avis de l’hôte ou du superviseur', async ({ browser, baseURL }) => {
  const page = await desk(browser, baseURL);
  await search(page, 'horsperiode'); await pick(page, fx.names.outside).click();
  await expect(page.getByText('Hors de sa période de validité')).toBeVisible(); await shot(page, 'tablette-2-hors-periode');
  await button(page, 'C’est bien cette personne').click();
  await page.getByLabel('Pièce d’identité contrôlée').check(); await button(page, 'Prendre la photo').click(); await button(page, 'Enregistrer et passer à la décision').click();
  const validate = button(page, /Valider/);
  await expect(validate).toBeDisabled(); await expect(page.getByText('Visiteur hors période : indiquez l’avis obtenu')).toBeVisible(); await shot(page, 'tablette-4-hors-periode');
  await page.getByLabel('Avis du superviseur').check(); await expect(validate).toBeEnabled(); await validate.click();
  await manualBadge(page, fx.freeBadges[1]);
  await expect(page.getByRole('heading', { name: `Remettez le badge ${fx.freeBadges[1]}` })).toBeVisible();
  await button(page, /Autoriser l’accès/).click(); await expect(page.getByRole('heading', { name: /Entrée enregistrée/ })).toBeVisible();
  await page.context().close();
});

test('un badge déjà associé est refusé en nommant la personne et en proposant un autre badge', async ({ browser, baseURL }) => {
  const page = await desk(browser, baseURL);
  await toDecision(page, 'second', fx.names.second);
  await button(page, /Valider/).click();
  await manualBadge(page, fx.takenBadge);
  await expect(page.getByTestId('erreur')).toContainText(`Badge déjà associé à ${fx.names.taken}`); await expect(page.getByTestId('erreur')).toContainText('Prenez un autre badge.'); await shot(page, 'tablette-5-badge-deja-associe');
  await manualBadge(page, fx.freeBadges[2]);
  await expect(page.getByRole('heading', { name: `Remettez le badge ${fx.freeBadges[2]}` })).toBeVisible();
  await page.context().close();
});

test('le QR du badge est lu par la caméra (flux simulé), sans appel réseau externe', async ({ browser, baseURL }) => {
  const page = await desk(browser, baseURL);
  const external: string[] = [];
  const origin = new URL(baseURL!).origin;
  page.on('request', request => { const url = request.url(); if (!url.startsWith('data:') && !url.startsWith('blob:') && new URL(url).origin !== origin) external.push(url); });
  // La caméra arrière montre le QR d'un vrai badge ; la caméra avant reste le flux factice du navigateur.
  await page.addInitScript(svg => {
    const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
    navigator.mediaDevices.getUserMedia = async constraints => {
      const video = constraints?.video as MediaTrackConstraints | boolean | undefined;
      if (typeof video !== 'object' || video.facingMode !== 'environment') return original(constraints);
      const canvas = document.createElement('canvas'); canvas.width = 480; canvas.height = 480;
      const context = canvas.getContext('2d')!;
      const image = new Image(); image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg.replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="480"'));
      await image.decode();
      setInterval(() => { context.fillStyle = '#fff'; context.fillRect(0, 0, 480, 480); context.drawImage(image, 0, 0, 480, 480); }, 100);
      return canvas.captureStream(10);
    };
  }, fx.qrSvg);
  await page.reload(); await expect(page.getByText('Étape 1 sur 7')).toBeVisible(); // le poste choisi est mémorisé par la tablette
  await toDecision(page, 'scanner', fx.names.scan);
  await button(page, /Valider/).click();
  await expect(page.getByRole('heading', { name: `Remettez le badge ${fx.qrBadge}` })).toBeVisible({ timeout: 20000 });
  expect(external).toEqual([]);
  await page.context().close();
});

test('un visiteur non listé reste bloqué tant que l’hôte n’a pas accepté', async ({ browser, baseURL }) => {
  const page = await desk(browser, baseURL);
  await button(page, /Visiteur non listé/).click();
  const form = page.getByRole('form', { name: 'Fiche rapide' });
  await expect(form.getByRole('button', { name: /Créer la fiche/ })).toBeDisabled(); await shot(page, 'tablette-non-liste');
  const name = `Imprévu Recette ${fx.names.listed.split(' ').pop()}`;
  await form.getByLabel('Nom du visiteur').fill(name); await form.getByLabel('Organisation').fill('Société fictive imprévue'); await form.getByLabel('Personne à voir').selectOption({ index: 1 });
  await form.getByRole('button', { name: /Créer la fiche/ }).click();
  await expect(page.getByRole('heading', { name: 'En attente de l’accord de l’hôte' })).toBeVisible(); await shot(page, 'tablette-non-liste-attente');
  await expect(button(page, /Valider/)).toHaveCount(0);
  await button(page, 'Vérifier la réponse de l’hôte').click(); await expect(page.getByRole('heading', { name: 'En attente de l’accord de l’hôte' })).toBeVisible();
  await page.context().close();
});

test('captures mobile du parcours', async ({ browser, baseURL }) => {
  test.skip(!shots, 'captures uniquement avec L5_CAPTURES=1');
  const page = await desk(browser, baseURL, { width: 390, height: 844 });
  await shot(page, 'mobile-1-recherche');
  await search(page, 'konate'); await shot(page, 'mobile-1-resultats'); await pick(page, fx.names.mobile).click(); await shot(page, 'mobile-2-fiche');
  await button(page, 'C’est bien cette personne').click(); await page.getByLabel('Pièce d’identité contrôlée').check(); await button(page, 'Prendre la photo').click(); await shot(page, 'mobile-3-identite');
  await button(page, 'Enregistrer et passer à la décision').click(); await shot(page, 'mobile-4-decision');
  await button(page, /Valider/).click(); await shot(page, 'mobile-5-badge');
  await manualBadge(page, fx.freeBadges[3]); await shot(page, 'mobile-6-remise');
  await button(page, /Autoriser l’accès/).click(); await shot(page, 'mobile-7-entree');
  await page.context().close();
});
