// S09 — parcours ciblé : l'administrateur annonce deux visites et associe un véhicule, le superviseur voit les retards dans le Pilotage,
// constate une absence avec motif, puis clôture une personnalité à clôture réservée. Données fictives d'un événement distinct de la recette.
// Ce fichier s'exécute en dernier : il n'agit que sur son propre événement.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
type Fixtures = { admin: Account; supervisor: Account; s09: { event: string; ids: Record<'announced' | 'absent' | 'vip', string>; people: Record<'announced' | 'absent' | 'vip', string>; names: Record<'announced' | 'absent' | 'vip', string>; vipBadge: string } };
const fx = JSON.parse(readFileSync('.secrets/desk-fixtures.json', 'utf8')) as Fixtures;
const s9 = fx.s09;
const PLATE = 'AB-1234-CD';

test.describe.configure({ mode: 'serial' });
test.use({ actionTimeout: 15_000 });

/** Heure attendue passée de 20 minutes (UTC, comme le serveur) : au-delà du délai de tolérance de 15 minutes, donc « en retard ». */
const twentyMinutesAgo = () => new Date(Date.now() - 20 * 60_000).toISOString().slice(0, 16);

test('l’administrateur annonce deux visites et associe un véhicule, sans rendre personne présent', async ({ page }) => {
  await loginWithSecondFactor(page, fx.admin.email, fx.admin.password, fx.admin.secret);
  for (const key of ['announced', 'absent'] as const) {
    await page.goto(`/administration/personnes/${s9.people[key]}`);
    const visit = page.getByTestId(`visite-${s9.ids[key]}`);
    await visit.getByTestId('admin-heure-attendue').fill(twentyMinutesAgo());
    await visit.getByTestId('admin-annoncer').click();
    await expect(visit.getByTestId('heure-annoncee')).toContainText('n’est pas une présence');
  }
  await page.goto(`/administration/personnes/${s9.people.announced}`);
  const visit = page.getByTestId(`visite-${s9.ids.announced}`);
  await visit.getByTestId('admin-plaque').fill(PLATE);
  await visit.getByTestId('admin-ajouter-vehicule').click();
  await expect(visit.getByTestId('admin-vehicules')).toContainText(PLATE);
  // la même plaque ne peut pas être associée à une autre participation
  await page.goto(`/administration/personnes/${s9.people.absent}`);
  const other = page.getByTestId(`visite-${s9.ids.absent}`);
  await other.getByTestId('admin-plaque').fill('ab 1234 cd');
  await other.getByTestId('admin-ajouter-vehicule').click();
  await expect(other.getByTestId('admin-erreur-vehicule')).toContainText('déjà annoncée');
});

test('le superviseur voit les retards dans le Pilotage, constate une absence avec motif', async ({ page }) => {
  await loginWithSecondFactor(page, fx.supervisor.email, fx.supervisor.password, fx.supervisor.secret);
  await page.goto(`/pilotage?event=${s9.event}`);
  await expect(page.getByTestId('situation-titre')).toHaveText('2 visites en retard');
  await page.goto(`/pilotage/attendus?event=${s9.event}`);
  const late = page.locator('[data-testid="attendu-en_retard"]');
  await expect(late).toHaveCount(2);
  await expect(page.getByTestId('liste-attendus')).toContainText(s9.names.announced);
  await expect(page.getByTestId('plaques-attendu')).toContainText(PLATE);
  await expect(page.getByTestId('aucun-vehicule')).toBeVisible(); // le véhicule annoncé n'est pas entré : aucun véhicule présent
  const card = page.locator('[data-testid^="attendu-"]').filter({ hasText: s9.names.absent });
  await card.getByTestId('absence-debut').click();
  await expect(card.getByTestId('absence-confirmer')).toBeDisabled();
  await card.getByTestId('absence-motif').fill('Prévenu : empêché (donnée fictive)');
  await card.getByTestId('absence-confirmer').click();
  await expect(page.locator('[data-testid^="attendu-"]').filter({ hasText: s9.names.absent }).getByTestId('motif-absence')).toContainText('empêché');
  await expect(page.locator('[data-testid="attendu-en_retard"]')).toHaveCount(1);
  await page.goto(`/pilotage?event=${s9.event}`);
  await expect(page.getByTestId('situation-titre')).toHaveText('1 visite en retard');
});

test('le superviseur clôture la personnalité : sortie, restitution et clôture, motif et confirmation obligatoires', async ({ page }) => {
  await loginWithSecondFactor(page, fx.supervisor.email, fx.supervisor.password, fx.supervisor.secret);
  await page.goto(`/pilotage/visiteurs/${s9.ids.vip}?event=${s9.event}`);
  await expect(page.getByTestId('etat')).toHaveText('Présent');
  await expect(page.getByTestId('badge')).toContainText(s9.vipBadge);
  await page.getByTestId('cloture-debut').click();
  await expect(page.getByTestId('cloture-suite')).toBeDisabled();
  await page.getByTestId('cloture-motif').fill('Fin de la visite officielle (donnée fictive)');
  await page.getByTestId('cloture-suite').click();
  await expect(page.getByRole('alert').filter({ hasText: 'définitives' })).toBeVisible();
  await page.getByTestId('cloture-confirmer').click();
  await expect(page.getByTestId('cloture-deja')).toBeVisible();
  await expect(page.getByTestId('etat')).toHaveText('Sorti');
  await expect(page.getByTestId('badge')).toHaveCount(0); // le badge est restitué, la personne n'a plus de badge attribué
  // la clôture administrative d'une personne n'est pas la sortie d'un véhicule : le véhicule annoncé d'une autre personne n'a pas bougé
  await page.goto(`/pilotage/attendus?event=${s9.event}`);
  await expect(page.getByTestId('aucun-vehicule')).toBeVisible();
});
