// S10 — parcours ciblé : le superviseur voit le centre de supervision (horloge à la seconde, panneaux simultanés, panneau de détail qui garde le tableau,
// clôture réservée proposée sans être confirmée) ; l'administrateur voit les deux domaines de l'atelier. Lecture seule : aucune donnée n'est modifiée,
// pour que le parcours de clôture S09 qui s'exécute ensuite retrouve ses données intactes. Données fictives de l'événement S09.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
type Fixtures = { admin: Account; supervisor: Account; s09: { event: string; ids: Record<'announced' | 'absent' | 'vip', string>; names: Record<'announced' | 'absent' | 'vip', string> } };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as Fixtures;
const s9 = fx.s09;

test.describe.configure({ mode: 'serial' });
test.use({ actionTimeout: 15_000 });

test('le superviseur voit le centre de supervision et consulte un détail sans quitter le tableau de bord', async ({ page }) => {
  await loginWithSecondFactor(page, fx.supervisor.email, fx.supervisor.password, fx.supervisor.secret);
  await page.goto(`/pilotage?event=${s9.event}`);
  const centre = page.getByTestId('centre');
  await expect(centre).toBeVisible();
  // horloge à la seconde : elle avance seule
  const clock = page.getByTestId('horloge');
  await expect(clock).toHaveText(/^\d{2}:\d{2}:\d{2}$/);
  const first = await clock.innerText();
  await expect.poll(async () => clock.innerText(), { timeout: 5000 }).not.toBe(first);
  // les panneaux sont visibles ensemble
  for (const id of ['postes', 'presents', 'visites', 'vehicules', 'badges', 'refus', 'cloture']) await expect(page.getByTestId(`centre-${id}`)).toBeVisible();
  await expect(page.getByTestId('presents-total')).toContainText('1');
  await expect(page.getByTestId('aucun-poste')).toBeVisible();
  await expect(page.getByTestId('aucun-vehicule-centre')).toBeVisible();
  // détail : le panneau s'ouvre à côté, le tableau reste en place
  await page.getByTestId('detail-presents').click();
  await expect(page.getByTestId('panneau-detail')).toBeVisible();
  await expect(page.getByTestId('detail-liste-presents')).toContainText(s9.names.vip);
  await expect(page.getByTestId('centre-postes')).toBeVisible();
  // clôture réservée : proposée dans le panneau, jamais confirmée ici
  await page.getByTestId('detail-cloture').click();
  await expect(page.getByTestId('detail-liste-cloture')).toContainText(s9.names.vip);
  await page.getByTestId(`choisir-cloture-${s9.ids.vip}`).click();
  await expect(page.getByTestId('cloture-cible')).toHaveText(s9.names.vip);
  await page.getByTestId('cloture-debut').click();
  await expect(page.getByTestId('cloture-suite')).toBeDisabled();
  await page.getByTestId('fermer-detail').click();
  await expect(page.getByTestId('panneau-detail')).toHaveCount(0);
  await expect(page.getByTestId('centre-cloture')).toBeVisible();
});

test('le superviseur n’accède à aucune page d’agent ni d’administration', async ({ page }) => {
  await loginWithSecondFactor(page, fx.supervisor.email, fx.supervisor.password, fx.supervisor.secret);
  for (const url of ['/poste', '/controle', '/administration/badges', '/administration/postes']) {
    const response = await page.goto(url);
    expect(response?.status(), url).toBe(403);
  }
});

test('l’administrateur retrouve l’espace Badges et cartes : trois responsabilités et espace de travail', async ({ page }) => {
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  await page.goto('/administration/badges');
  await expect(page.getByTestId('onglet-registre')).toContainText('Registre et cartes');
  await expect(page.getByTestId('onglet-modeles')).toContainText('Modèles institutionnels');
  await expect(page.getByTestId('onglet-impression')).toContainText('Impression et historique');
  await expect(page.getByTestId('compteurs-badges-cartes')).toContainText('Supports actifs');
  await expect(page.getByTestId('colonne-registre')).toBeVisible();
  await expect(page.getByTestId('colonne-apercu')).toBeVisible();
  await expect(page.getByTestId('rappel-accueil')).toContainText('acte distinct de la sortie physique');
  await page.getByTestId('lien-cartes-permanentes').click();
  await expect(page).toHaveURL(/\/administration\/titulaires$/);
  await page.getByTestId('onglet-registre').click();
  await expect(page).toHaveURL(/\/administration\/badges$/);
});
