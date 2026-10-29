import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Fixtures = { email: string; password: string; poste: string; names: Record<string, string>; takenBadge: string; vipBadges: string[]; freeBadges: string[]; qrSvg: string; qrBadge: string; enrolCode: string; bulk: string[]; bulkPrefix: string; admin: { email: string; password: string; secret: string } };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as Fixtures;
const shots = process.env.L5_CAPTURES === '1' || process.env.L6_CAPTURES === '1';
const PIN = '482915';

// Une seule tablette (un seul contexte de navigateur) pour tout le fichier : elle est enrôlée une fois, puis verrouillée/déverrouillée par PIN comme sur le terrain.
let tablet: BrowserContext;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }, info) => {
  tablet = await browser.newContext({ baseURL: info.project.use.baseURL, viewport: { width: 1280, height: 800 }, permissions: ['camera'] });
  const page = await tablet.newPage();
  await page.goto('/login'); await page.getByLabel('Adresse électronique').fill(fx.email); await page.getByLabel('Mot de passe', { exact: true }).fill(fx.password); await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/espace/);
  await page.close();
});
test.afterAll(async () => { await tablet.close(); });

/** Enrôle la tablette si elle ne l'est pas encore, ou la déverrouille avec son PIN. */
async function openDesk(page: Page): Promise<void> {
  const enrol = page.getByTestId('ecran-enrolement'); const lock = page.getByTestId('ecran-verrou'); const step = page.getByText('Étape 1 sur 7');
  await enrol.or(lock).or(step).first().waitFor();
  if (await enrol.isVisible()) {
    await page.getByTestId('code-enrolement').fill(fx.enrolCode); await page.getByTestId('pin').fill(PIN); await page.getByTestId('pin-confirmation').fill(PIN);
    await page.getByTestId('enroler').click();
  } else if (await lock.isVisible()) {
    await page.getByTestId('pin-deverrouillage').fill(PIN); await page.getByTestId('deverrouiller').click();
  }
  await expect(step).toBeVisible();
}

async function desk(viewport = { width: 1280, height: 800 }): Promise<Page> {
  const page = await tablet.newPage();
  await page.setViewportSize(viewport);
  await page.goto('/poste');
  await openDesk(page);
  return page;
}
const shot = async (page: Page, name: string) => { if (shots) await page.screenshot({ path: `docs/captures/${process.env.L6_CAPTURES === '1' ? 'L6' : 'L5'}/${name}.png`, fullPage: true }); };
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

test('un visiteur listé est traité en moins de 60 secondes, de la recherche à l’entrée', async () => {
  const page = await desk();
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
  await expect(page.getByTestId('en-attente')).toHaveText('0', { timeout: 30_000 }); await expect(page.getByText('En ligne')).toBeVisible();
  await page.close();
});

test('un VIP ressort en premier avec une alerte de couleur et passe par le même parcours', async () => {
  const page = await desk();
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
  await page.close();
});

test('un refus exige un motif, n’associe aucun badge et bloque la suite', async () => {
  const page = await desk();
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
  await page.close();
});

test('un visiteur hors période n’est validé qu’avec l’avis de l’hôte ou du superviseur', async () => {
  const page = await desk();
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
  await page.close();
});

test('un badge déjà associé est refusé en nommant la personne et en proposant un autre badge', async () => {
  const page = await desk();
  await toDecision(page, 'second', fx.names.second);
  await button(page, /Valider/).click();
  await manualBadge(page, fx.takenBadge);
  await expect(page.getByTestId('erreur')).toContainText(`Badge déjà associé à ${fx.names.taken}`); await expect(page.getByTestId('erreur')).toContainText('Prenez un autre badge.'); await shot(page, 'tablette-5-badge-deja-associe');
  await manualBadge(page, fx.freeBadges[2]);
  await expect(page.getByRole('heading', { name: `Remettez le badge ${fx.freeBadges[2]}` })).toBeVisible();
  await page.close();
});

test('le QR du badge est lu par la caméra (flux simulé), sans appel réseau externe', async ({ baseURL }) => {
  const page = await tablet.newPage();
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
      // L'image SVG est rastérisée une fois pour toutes : redessiner le SVG à chaque image donnait parfois un QR à moitié tracé dans le flux simulé.
      const bitmap = await createImageBitmap(image, { resizeWidth: 480, resizeHeight: 480 });
      let tick = 0;
      // Un pixel presque invisible change à chaque image : sans cela le flux simulé peut rester figé sur une image mal assemblée (QR déformé) et ne plus jamais se renouveler.
      setInterval(() => { context.fillStyle = '#fff'; context.fillRect(0, 0, 480, 480); context.drawImage(bitmap, 0, 0); context.fillStyle = tick++ % 2 ? '#fcfcfc' : '#fefefe'; context.fillRect(0, 0, 3, 3); }, 100);
      return canvas.captureStream(10);
    };
  }, fx.qrSvg);
  await page.goto('/poste'); await openDesk(page);
  await toDecision(page, 'scanner', fx.names.scan);
  await button(page, /Valider/).click();
  await expect(page.getByRole('heading', { name: `Remettez le badge ${fx.qrBadge}` })).toBeVisible({ timeout: 20000 });
  expect(external).toEqual([]);
  await page.close();
});

test('un visiteur non listé reste bloqué tant que l’hôte n’a pas accepté', async () => {
  const page = await desk();
  await button(page, /Visiteur non listé/).click();
  const form = page.getByRole('form', { name: 'Fiche rapide' });
  await expect(form.getByRole('button', { name: /Créer la fiche/ })).toBeDisabled(); await shot(page, 'tablette-non-liste');
  const name = `Imprévu Recette ${fx.names.listed.split(' ').pop()}`;
  await form.getByLabel('Nom du visiteur').fill(name); await form.getByLabel('Organisation').fill('Société fictive imprévue'); await form.getByLabel('Personne à voir').selectOption({ index: 1 });
  await form.getByRole('button', { name: /Créer la fiche/ }).click();
  await expect(page.getByRole('heading', { name: 'En attente de l’accord de l’hôte' })).toBeVisible(); await shot(page, 'tablette-non-liste-attente');
  await expect(button(page, /Valider/)).toHaveCount(0);
  await button(page, 'Vérifier la réponse de l’hôte').click(); await expect(page.getByRole('heading', { name: 'En attente de l’accord de l’hôte' })).toBeVisible();
  await page.close();
});

test('captures mobile du parcours', async () => {
  test.skip(!shots, 'captures uniquement avec L5_CAPTURES=1');
  const page = await desk({ width: 390, height: 844 });
  await shot(page, 'mobile-1-recherche');
  await search(page, 'konate'); await shot(page, 'mobile-1-resultats'); await pick(page, fx.names.mobile).click(); await shot(page, 'mobile-2-fiche');
  await button(page, 'C’est bien cette personne').click(); await page.getByLabel('Pièce d’identité contrôlée').check(); await button(page, 'Prendre la photo').click(); await shot(page, 'mobile-3-identite');
  await button(page, 'Enregistrer et passer à la décision').click(); await shot(page, 'mobile-4-decision');
  await button(page, /Valider/).click(); await shot(page, 'mobile-5-badge');
  await manualBadge(page, fx.freeBadges[3]); await shot(page, 'mobile-6-remise');
  await button(page, /Autoriser l’accès/).click(); await shot(page, 'mobile-7-entree');
  await page.close();
});

test('la tablette se verrouille, refuse un mauvais PIN et se rouvre avec le bon', async () => {
  const page = await desk();
  await shot(page, 'l6-tablette-statut');
  await button(page, 'Verrouiller').click();
  await expect(page.getByRole('heading', { name: 'Tablette verrouillée' })).toBeVisible(); await expect(page.getByText('Étape 1 sur 7')).toHaveCount(0); await shot(page, 'l6-verrou');
  await page.getByTestId('pin-deverrouillage').fill('999111'); await page.getByTestId('deverrouiller').click();
  await expect(page.getByTestId('erreur-gate')).toContainText('PIN incorrect'); await shot(page, 'l6-pin-incorrect');
  await page.getByTestId('pin-deverrouillage').fill(PIN); await page.getByTestId('deverrouiller').click();
  await expect(page.getByText('Étape 1 sur 7')).toBeVisible();
  await page.close();
});

async function adminCounters(admin: Page): Promise<{ received: number; applied: number; conflicts: number }> {
  await admin.goto('/administration/postes');
  await expect(admin.getByRole('heading', { name: 'Administration · Postes' })).toBeVisible({ timeout: 20_000 });
  const text = await admin.locator(`xpath=//h2[contains(., "${fx.poste}")]/following-sibling::p[@data-testid="compteurs"]`).innerText({ timeout: 10_000 });
  const [received, applied, conflicts] = text.match(/\d+/g)!.map(Number);
  return { received, applied, conflicts };
}

test('30 visiteurs traités sans réseau, tablette rouverte sans réseau, puis envoi complet sans perte ni doublon', async ({ browser, baseURL }) => {
  test.setTimeout(600_000);
  const online = await desk();
  // La coquille du poste doit être en cache avant la coupure.
  await online.evaluate(async () => {
    await navigator.serviceWorker.ready;
    if (!navigator.serviceWorker.controller) await new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true }));
  });
  await expect(online.getByTestId('en-attente')).toHaveText('0');
  const adminContext = await browser.newContext({ baseURL });
  const admin = await adminContext.newPage();
  await loginWithSecondFactor(admin, fx.admin.email, fx.admin.password, fx.admin.secret);
  await expect(admin).toHaveURL(/\/administration$/);
  const before = await adminCounters(admin);

  await tablet.setOffline(true);
  await online.close();
  const page = await tablet.newPage();
  await page.goto('/poste'); // servi par le service worker : aucune réponse du serveur
  await openDesk(page);
  await expect(page.getByText('Hors ligne')).toBeVisible(); await shot(page, 'l6-hors-ligne');
  const started = Date.now();
  for (let i = 0; i < 30; i++) {
    const name = fx.bulk[i];
    await search(page, name); await pick(page, name).click();
    await button(page, 'C’est bien cette personne').click();
    await page.getByLabel('Pièce d’identité contrôlée').check(); await button(page, 'Prendre la photo').click(); await button(page, 'Enregistrer et passer à la décision').click();
    await button(page, /Valider/).click();
    await manualBadge(page, fx.freeBadges[10 + i]);
    await button(page, /Autoriser l’accès/).click();
    await expect(page.getByRole('heading', { name: /Entrée enregistrée/ })).toBeVisible();
    await button(page, 'Visiteur suivant').click();
  }
  console.log(`30 visiteurs hors ligne en ${Math.round((Date.now() - started) / 1000)} s`);
  await expect(page.getByTestId('en-attente')).toHaveText('120'); await shot(page, 'l6-file-en-attente');

  // Rien d'identifiable n'est lisible dans IndexedDB.
  const dump = await page.evaluate(async () => {
    const db = await new Promise<IDBDatabase>((resolve, reject) => { const request = indexedDB.open('ikoma-poste'); request.onsuccess = () => resolve(request.result); request.onerror = () => reject(request.error); });
    const out: string[] = [];
    for (const name of Array.from(db.objectStoreNames)) {
      const rows = await new Promise<unknown[]>(resolve => { const query = db.transaction(name).objectStore(name).getAll(); query.onsuccess = () => resolve(query.result); });
      out.push(JSON.stringify(rows, (_key, value) => (value instanceof Uint8Array ? Array.from(value).map(c => String.fromCharCode(c)).join('') : typeof CryptoKey !== 'undefined' && value instanceof CryptoKey ? 'cle' : value)));
    }
    db.close();
    return out.join('\n');
  });
  expect(dump.length).toBeGreaterThan(10_000);
  for (const secret of [fx.bulk[0], fx.bulk[29], fx.names.listed, 'Organisation fictive de recette', fx.freeBadges[10], fx.enrolCode.replaceAll('-', ''), fx.email]) expect(dump, secret).not.toContain(secret);

  // Retour du réseau : la première réponse du serveur est perdue (le lot est appliqué mais la tablette ne le sait pas) ; le renvoi ne doit rien doubler.
  let lost = false;
  await page.route('**/poste/api/sync', async route => {
    if (!lost) { lost = true; await route.fetch(); await route.abort('connectionreset'); } else await route.continue();
  });
  await tablet.setOffline(false); // l'événement « en ligne » déclenche déjà l'envoi : le piège doit être posé avant
  await button(page, 'Synchroniser maintenant').click();
  await expect(page.getByTestId('en-attente')).toHaveText('0', { timeout: 120_000 });
  await expect(page.getByTestId('derniere-sync')).not.toHaveText('jamais'); await shot(page, 'l6-synchronise');
  expect(lost).toBe(true);
  const after = await adminCounters(admin);
  expect(after.received - before.received).toBe(120); expect(after.applied - before.applied).toBe(120); expect(after.conflicts - before.conflicts).toBe(0);
  await shot(admin, 'l6-admin-postes');
  await adminContext.close();
  await page.close();
});

test('la révocation par l’administrateur efface la tablette à sa prochaine synchronisation', async ({ browser, baseURL }) => {
  test.setTimeout(180_000); // inclut le délai TOTP et une éventuelle limitation temporaire du serveur
  const page = await desk();
  await expect(page.getByRole('button', { name: 'Effacer cette tablette' })).toHaveCount(0); // l'effacement n'est pas à la portée de l'agent
  const adminContext = await browser.newContext({ baseURL });
  const admin = await adminContext.newPage();
  await loginWithSecondFactor(admin, fx.admin.email, fx.admin.password, fx.admin.secret);
  await expect(admin).toHaveURL(/\/administration$/);
  await admin.goto('/administration/postes');
  admin.once('dialog', dialog => dialog.accept());
  const card = admin.locator(`xpath=//h2[contains(., "${fx.poste}")]/ancestor::*[self::section or self::div][1]`);
  await card.getByRole('button', { name: 'Révoquer ce poste' }).click();
  await expect(card.getByText('Révoqué')).toBeVisible(); await shot(admin, 'l6-admin-revoque');
  await button(page, 'Synchroniser maintenant').click();
  await expect(page.getByTestId('ecran-enrolement')).toBeVisible(); await expect(page.getByTestId('message-efface')).toContainText('déconnectée par l’administrateur'); await shot(page, 'l6-efface');
  const rows = await page.evaluate(async () => {
    const db = await new Promise<IDBDatabase>((resolve, reject) => { const request = indexedDB.open('ikoma-poste'); request.onsuccess = () => resolve(request.result); request.onerror = () => reject(request.error); });
    let total = 0;
    for (const name of Array.from(db.objectStoreNames)) total += await new Promise<number>(resolve => { const query = db.transaction(name).objectStore(name).count(); query.onsuccess = () => resolve(query.result); });
    db.close();
    return total;
  });
  expect(rows).toBe(0);
  await adminContext.close();
  await page.close();
});
