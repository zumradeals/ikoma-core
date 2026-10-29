// Ce fichier s'exécute en dernier (ordre alphabétique de Playwright) : les parcours des cars créent des alertes (absents, écarts, car arrivé)
// dans l'événement de recette, et le test d'alertes du pilotage compte précisément les siennes.
import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Car = { id: string; name: string; bus: string; members: string[]; ids: string[]; badges: string[]; qr: Record<string, string> };
type Fixtures = {
  cars: Record<string, Car>; enrolCode4: string; posteCars: string; carAgent: { email: string; password: string }; liveEncadreur: { email: string; password: string };
  supervisor: { email: string; password: string; secret: string }; admin: { email: string; password: string; secret: string };
};
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as Fixtures;
const shots = process.env.L9_CAPTURES === '1';
const PIN = '482915';

let tablet: BrowserContext; let phone: BrowserContext;
test.describe.configure({ mode: 'serial' });
test.use({ actionTimeout: 15_000 });

const shot = async (page: Page, name: string) => { if (shots) await page.screenshot({ path: `docs/captures/L9/${name}.png`, fullPage: true }); };
const button = (page: Page, name: string | RegExp) => page.getByRole('button', { name });

async function login(context: BrowserContext, email: string, password: string, secret?: string): Promise<void> {
  const page = await context.newPage();
  if (secret) await loginWithSecondFactor(page, email, password, secret);
  else {
    await page.goto('/login'); await page.getByLabel('Adresse électronique').fill(email); await page.getByLabel('Mot de passe', { exact: true }).fill(password); await page.getByRole('button', { name: 'Se connecter' }).click();
  }
  await expect(page).toHaveURL(email === fx.admin.email ? /\/administration$/ : email === fx.supervisor.email ? /\/pilotage$/ : /\/espace/);
  await page.close();
}

test.beforeAll(async ({ browser }, info) => {
  const baseURL = info.project.use.baseURL;
  tablet = await browser.newContext({ baseURL, viewport: { width: 1024, height: 768 }, permissions: ['camera'] });
  // La caméra arrière montre le QR demandé par le test (window.__setQr) : un badge à la fois, comme devant une vraie caméra.
  await tablet.addInitScript(() => {
    const original = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
    const canvas = document.createElement('canvas'); canvas.width = 480; canvas.height = 480;
    const context = canvas.getContext('2d')!;
    let bitmap: ImageBitmap | null = null; let tick = 0;
    (window as unknown as { __setQr: (svg: string | null) => Promise<void> }).__setQr = async svg => {
      if (!svg) { bitmap = null; return; }
      const image = new Image(); image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg.replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="480"'));
      await image.decode();
      bitmap = await createImageBitmap(image, { resizeWidth: 480, resizeHeight: 480 });
    };
    // Léger tremblement de la main : un QR que le lecteur ne sait pas décoder sur une image fixe l'est sur une image voisine (comme devant une vraie caméra).
    setInterval(() => {
      context.fillStyle = '#fff'; context.fillRect(0, 0, 480, 480);
      if (bitmap) { const k = 0.94 + Math.random() * 0.1; const size = 480 * k; context.drawImage(bitmap, (480 - size) / 2 + (Math.random() - 0.5) * 12, (480 - size) / 2 + (Math.random() - 0.5) * 12, size, size); }
      context.fillStyle = tick++ % 2 ? '#fcfcfc' : '#fefefe'; context.fillRect(0, 0, 3, 3);
    }, 100);
    navigator.mediaDevices.getUserMedia = async constraints => {
      const video = constraints?.video as MediaTrackConstraints | boolean | undefined;
      if (typeof video !== 'object' || video.facingMode !== 'environment') return original(constraints);
      return canvas.captureStream(10);
    };
  });
  phone = await browser.newContext({ baseURL, viewport: { width: 380, height: 800 }, hasTouch: true, isMobile: true });
  await login(tablet, fx.carAgent.email, fx.carAgent.password);
});
test.afterAll(async () => { await tablet.close(); await phone.close(); });

const setQr = (page: Page, code: string | null, car?: Car) => page.evaluate(svg => Promise.race([(window as unknown as { __setQr: (s: string | null) => Promise<void> }).__setQr(svg), new Promise<void>((_, reject) => setTimeout(() => reject(new Error('setQr bloqué plus de 10 s')), 10_000))]), code && car ? car.qr[code] : null);

/** Ouvre l'écran des groupes de la tablette (enrôlement la première fois, PIN ensuite). */
async function openCars(page: Page): Promise<void> {
  await page.goto('/groupe');
  const enrol = page.getByTestId('ecran-enrolement'); const lock = page.getByTestId('ecran-verrou'); const ready = page.getByTestId('choix-groupe');
  await enrol.or(lock).or(ready).first().waitFor();
  if (await enrol.isVisible()) { await page.getByTestId('code-enrolement').fill(fx.enrolCode4); await page.getByTestId('pin').fill(PIN); await page.getByTestId('pin-confirmation').fill(PIN); await page.getByTestId('enroler').click(); }
  else if (await lock.isVisible()) { await page.getByTestId('pin-deverrouillage').fill(PIN); await page.getByTestId('deverrouiller').click(); }
  await expect(ready).toBeVisible();
}
async function chooseCar(page: Page, car: Car): Promise<void> {
  await page.getByTestId('choisir-groupe').filter({ hasText: car.bus + ' ·' }).click();
  await expect(page.getByTestId('nom-groupe')).toHaveText(car.bus);
}
async function progress(page: Page, done: number, total: number): Promise<void> {
  try { await expect(page.getByTestId('progression')).toHaveText(`Badges remis : ${done} sur ${total}`, { timeout: 15_000 }); }
  catch (e) {
    const message = await page.getByTestId('erreur').innerText().catch(() => 'aucun message');
    const state = await page.evaluate(() => {
      const v = document.querySelector('video');
      return JSON.stringify({ video: v ? { ready: v.readyState, w: v.videoWidth, paused: v.paused, shown: v.offsetParent !== null, tracks: (v.srcObject as MediaStream | null)?.getTracks().map(t => t.readyState) } : null, courant: document.querySelector('[data-testid=personne-courante]')?.textContent, confirmee: !!document.querySelector('[data-testid=code-badge]'), videos: document.querySelectorAll('video').length, busy: document.querySelector('#contenu')?.getAttribute('data-busy'), erreur: document.querySelector('[data-testid=erreur]')?.textContent, attente: document.querySelector('[data-testid=en-attente]')?.textContent });
    });
    console.log('DIAG', state);
    await page.locator('video').first().screenshot({ path: '/tmp/video-diag.png' }).catch(() => undefined);
    throw new Error(`Progression attendue ${done}/${total} — message affiché : ${message} — ${(e as Error).message}`, { cause: e });
  }
}
const pendingOps = (page: Page) => page.getByTestId('en-attente');

test('car de 50 stagiaires : appel, correspondance et remise du badge en moins de 20 s par personne (caméra simulée)', async () => {
  test.setTimeout(360_000);
  const car = fx.cars['1'];
  const page = await tablet.newPage();
  page.on('pageerror', e => console.log('PAGEERROR', e.message)); page.on('console', m => { if (['error', 'warning'].includes(m.type())) console.log('CONSOLE', m.type(), m.text().slice(0, 200)); });
  await openCars(page); await shot(page, 'groupe-choix');
  await chooseCar(page, car);
  await progress(page, 0, 50); await shot(page, 'groupe-appel');
  const started = Date.now();
  for (let i = 0; i < 50; i++) {
    await expect(page.getByTestId('personne-courante')).toHaveText(car.members[i]);
    await button(page, 'Valider la correspondance').click();
    await expect(page.getByTestId('code-badge')).toBeVisible(); // correspondance enregistrée : le badge peut être présenté
    await setQr(page, car.badges[i], car);
    await progress(page, i + 1, 50);
    await setQr(page, null);
    console.log('PERSONNE', i + 1, Date.now() - started, 'ms cumulées, en attente', await pendingOps(page).innerText());
    if (i === 24) await shot(page, 'groupe-mi-parcours');
  }
  const average = (Date.now() - started) / 50 / 1000;
  expect(average, `${average.toFixed(1)} s par stagiaire`).toBeLessThan(20);
  test.info().annotations.push({ type: 'moyenne', description: `${average.toFixed(2)} s par stagiaire` });
  await button(page, 'Fermer le car (embarquement)').click(); await button(page, 'Oui, fermer le car').click();
  await expect(page.getByTestId('bilan-car')).toContainText('Groupe complet'); await expect(page.getByTestId('bilan-car')).toContainText('50 stagiaires embarqués');
  await shot(page, 'groupe-complet');
  await expect(pendingOps(page)).toHaveText('0', { timeout: 60_000 });
  await page.close();
});

test('car avec absents : confirmation de l’encadreur en direct, absents signalés, un encadreur ne touche jamais un autre groupe', async () => {
  test.setTimeout(240_000);
  const car = fx.cars['2'];
  await login(phone, fx.liveEncadreur.email, fx.liveEncadreur.password);
  const enc = await phone.newPage();
  await enc.goto('/mon-groupe');
  await expect(enc.getByTestId('mon-groupe')).toContainText(car.bus); await expect(enc.getByText(car.members[0])).toBeVisible();
  // Il ne voit rien du car 1, et le serveur lui refuse d'en confirmer un stagiaire.
  await expect(enc.getByText(fx.cars['1'].members[0])).toHaveCount(0);
  const status = await enc.evaluate(async id => {
    const token = decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))!.slice('XSRF-TOKEN='.length));
    return (await fetch(`/mon-groupe/${id}/confirmer`, { method: 'POST', headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' } })).status;
  }, fx.cars['1'].ids[0]);
  expect(status).toBe(403);

  const page = await tablet.newPage();
  await openCars(page); await chooseCar(page, car);
  await expect(page.getByTestId('attente-encadreur')).toBeVisible(); await expect(button(page, 'Valider la correspondance')).toBeDisabled(); await shot(page, 'groupe-attente-encadreur');
  for (let i = 0; i < 4; i++) { await enc.getByTestId('confirmer').first().click(); await expect(enc.getByTestId('confirmer')).toHaveCount(5 - i); }
  await expect(enc.getByTestId('confirmer')).toHaveCount(2); await shot(enc, 'encadreur-mon-groupe');
  for (let i = 0; i < 4; i++) {
    await expect(page.getByTestId('personne-courante')).toHaveText(car.members[i]);
    await expect(page.getByTestId('confirme-encadreur')).toBeVisible({ timeout: 30_000 }); // la confirmation arrive par la synchronisation
    await button(page, 'Valider la correspondance').click();
    await page.getByTestId('code-badge').fill(car.badges[i]); await button(page, 'Remettre ce badge').click();
    await progress(page, i + 1, 6);
  }
  await expect(page.getByTestId('personne-courante')).toHaveText(car.members[4]); await expect(page.getByTestId('attente-encadreur')).toBeVisible();
  await button(page, 'Fermer le car (embarquement)').click(); await shot(page, 'groupe-fermeture');
  await button(page, 'Oui, fermer le car').click();
  const bilan = page.getByTestId('bilan-car');
  await expect(bilan).toContainText('Car parti avec des absents'); await expect(page.getByTestId('liste-absents').locator('li')).toHaveText([car.members[4], car.members[5]]);
  await shot(page, 'groupe-absents');
  await expect(pendingOps(page)).toHaveText('0', { timeout: 60_000 });
  // L'encadreur voit le car parti et ne peut plus rien confirmer.
  await enc.reload(); await expect(enc.getByText('Le car est parti')).toBeVisible(); await expect(enc.getByTestId('confirmer')).toHaveCount(0);
  await page.close(); await enc.close();
});

test('sans réseau pendant la remise : rien n’est perdu ni dupliqué à la resynchronisation', async () => {
  test.setTimeout(240_000);
  const car = fx.cars['3'];
  const page = await tablet.newPage();
  await openCars(page); await chooseCar(page, car);
  for (let i = 0; i < 3; i++) {
    await button(page, 'Valider la correspondance').click();
    await page.getByTestId('code-badge').fill(car.badges[i]); await button(page, 'Remettre ce badge').click(); await progress(page, i + 1, 8);
  }
  await expect(pendingOps(page)).toHaveText('0', { timeout: 60_000 });
  await tablet.setOffline(true);
  await expect(page.getByTestId('hors-ligne')).toBeVisible();
  for (let i = 3; i < 6; i++) {
    await button(page, 'Valider la correspondance').click();
    await expect(page.getByTestId('code-badge')).toBeVisible();
    await setQr(page, car.badges[i], car);
    await progress(page, i + 1, 8);
    await setQr(page, null);
  }
  await expect(pendingOps(page)).not.toHaveText('0'); await shot(page, 'groupe-hors-ligne');
  await tablet.setOffline(false);
  await button(page, 'Synchroniser maintenant').click();
  await expect(pendingOps(page)).toHaveText('0', { timeout: 60_000 });
  await expect(page.getByTestId('conflits')).toHaveCount(0);
  // Après resynchronisation, la copie du serveur donne le même résultat : six badges, ni plus ni moins.
  await page.reload();
  await page.getByTestId('pin-deverrouillage').fill(PIN).catch(() => undefined); if (await page.getByTestId('deverrouiller').isVisible()) await page.getByTestId('deverrouiller').click();
  await expect(page.getByTestId('choix-groupe')).toBeVisible(); await chooseCar(page, car); await progress(page, 6, 8);
  await page.close();
});

test('arrivée du car : scan continu, écarts signalés au superviseur', async ({ browser }, info) => {
  test.setTimeout(240_000);
  const car = fx.cars['4'];
  const page = await tablet.newPage();
  await openCars(page);
  await page.getByTestId('onglet-arrivee').click();
  // Quatre des cinq embarqués sont scannés, plus le badge d'une personne qui n'était pas embarquée.
  for (const code of [car.badges[0], car.badges[1], car.badges[2], car.badges[3]]) {
    await setQr(page, code, car);
    await expect(page.getByTestId('dernier-arrive')).toContainText(code, { timeout: 15_000 });
    await setQr(page, null);
  }
  await expect(page.getByTestId('compteur-arrivee')).toHaveText('Arrivés pendant cette séance : 4');
  await setQr(page, car.badges[5], car);
  await expect(page.getByTestId('ecart')).toBeVisible({ timeout: 15_000 }); await shot(page, 'arrivee-ecart');
  await setQr(page, null);
  await page.getByTestId('terminer-arrivee').filter({ hasText: car.bus }).click();
  await expect(page.getByTestId('bilan-arrivee')).toContainText('1 embarqué non scanné'); await shot(page, 'arrivee-bilan');
  await expect(pendingOps(page)).toHaveText('0', { timeout: 60_000 });
  await page.close();

  // Le superviseur voit l'avancement et les deux écarts.
  const supervisor = await browser.newContext({ baseURL: info.project.use.baseURL, viewport: { width: 380, height: 800 }, hasTouch: true, isMobile: true });
  await login(supervisor, fx.supervisor.email, fx.supervisor.password, fx.supervisor.secret);
  const sup = await supervisor.newPage();
  await sup.goto('/pilotage/groupes');
  const card = sup.getByTestId('liste-groupes').locator('li').filter({ hasText: car.bus });
  await expect(card).toContainText('car parti'); await expect(card.getByTestId('ecarts-groupe')).toBeVisible(); await shot(sup, 'pilotage-groupes');
  await sup.goto('/pilotage/alertes');
  await expect(sup.getByText('n’étaient pas embarquée').or(sup.getByText('alors que la personne n’était pas embarquée')).first()).toBeVisible();
  await expect(sup.getByText(/embarqué\(s\) du car Car 4 n’ont pas été scannés/)).toBeVisible();
  await supervisor.close();
});

test('administration : règle de contrôle des stagiaires et groupes', async ({ browser }, info) => {
  test.setTimeout(180_000); // délai TOTP et limitation temporaire éventuelle
  const admin = await browser.newContext({ baseURL: info.project.use.baseURL, viewport: { width: 1024, height: 900 } });
  await login(admin, fx.admin.email, fx.admin.password, fx.admin.secret);
  const page = await admin.newPage();
  await page.goto('/administration/groupes');
  await expect(page.getByRole('heading', { name: 'Administration · Groupes' })).toBeVisible();
  await expect(page.getByTestId('mode-encadreur')).toBeChecked(); await expect(page.getByText(fx.cars['1'].name)).toBeVisible(); await shot(page, 'admin-groupes');
  await page.getByTestId('mode-identite').check(); await Promise.all([page.waitForResponse(r => r.url().includes('/administration/groupes/reglage')), button(page, 'Enregistrer la règle').click()]);
  await page.goto('/administration/groupes'); await expect(page.getByTestId('mode-identite')).toBeChecked();
  await page.getByTestId('mode-encadreur').check(); await Promise.all([page.waitForResponse(r => r.url().includes('/administration/groupes/reglage')), button(page, 'Enregistrer la règle').click()]);
  await page.goto('/administration/groupes'); await expect(page.getByTestId('mode-encadreur')).toBeChecked();
  await admin.close();
});
