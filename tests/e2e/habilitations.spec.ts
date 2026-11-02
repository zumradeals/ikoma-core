import { test, expect, type Page } from '@playwright/test';
import { fakePhoto } from './photo';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';

type Account = { email: string; password: string; secret: string };
const admin = JSON.parse(readFileSync('.secrets/screen-permanents.json', 'utf8')) as Account;

const local = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);

async function noHorizontalScroll(page: Page, label: string) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), label).toBe(true);
}

test('titulaires permanents : habilitations par zone, perte, révocation et remplacement de carte, confiance hors ligne (ordinateur et tablette)', async ({ page }) => {
  test.setTimeout(300_000);
  const name = `Personne Habilitations ${Date.now()}`;
  await page.setViewportSize({ width: 1440, height: 900 });
  await loginWithSecondFactor(page, admin.email, admin.password, admin.secret);

  await page.goto('/administration/categories-institutionnelles');
  if (await page.getByTestId('initialiser-categories').isVisible()) await page.getByTestId('initialiser-categories').click();
  await page.goto('/administration/titulaires');
  if (await page.getByTestId('creer-contexte').isVisible()) { await page.getByTestId('creer-contexte').click(); await expect(page.getByTestId('contexte-permanent')).toContainText('en place'); }
  await page.getByText('Ajouter une personne').click();
  await page.getByTestId('creer-prenom').fill(name.split(' ')[0]);
  await page.getByTestId('creer-nom').fill(name.split(' ').slice(1).join(' '));
  await page.getByTestId('creer-categorie').selectOption('AILCT-02');
  await page.getByTestId('enregistrer-titulaire').click();
  await expect(page.getByText('Titulaire enregistré.')).toBeVisible();
  await page.getByTestId('recherche-titulaires').fill(name);
  await page.getByTestId('lancer-recherche').click();
  const row = page.locator('[data-testid^="titulaire-"]').filter({ hasText: name });
  await expect(row).toBeVisible();
  const identifier = (await row.getByTestId('identifiant-titulaire').innerText()).trim();

  // Zone, politique explicite puis habilitation : rien n'est implicite.
  await page.goto('/administration/habilitations');
  await expect(page.getByTestId('page-habilitations')).toBeVisible();
  // Point de départ rejouable : aucune confiance approuvée (réglages avancés, repliés par défaut).
  await page.getByTestId('reglages-avances').locator('summary').click();
  await page.getByTestId('confiance-minutes').fill('0');
  await page.getByTestId('confiance-approuvee').uncheck();
  await page.getByTestId('confiance-enregistrer').click();
  await expect(page.getByTestId('confiance-resume')).toContainText('aucune');
  // 1. Où ? Une zone ouverte aux permanents (première zone fermée trouvée, sinon la première).
  const zone = page.locator('li[data-testid^="zone-"]').first();
  await expect(zone).toBeVisible();
  const zoneId = ((await zone.getAttribute('data-testid')) ?? '').replace('zone-', '');
  if ((await page.getByTestId('etat-zone-' + zoneId).innerText()).trim() === 'Fermée') await page.getByTestId('bascule-zone-' + zoneId).click();
  await expect(page.getByTestId('etat-zone-' + zoneId)).toHaveText('Ouverte');
  // 2. Qui ? 3. Où et quand ? Une personne, la zone ouverte, tout l'événement.
  await page.getByTestId('chercher-personne').fill(name);
  await page.getByTestId('personne-' + identifier).check();
  await page.getByTestId('choisir-zone-' + zoneId).check();
  await page.getByTestId('periode-dates').check();
  await page.getByTestId('hab-debut').fill(local(new Date(Date.now() - 3_600_000)));
  await page.getByTestId('hab-fin').fill(local(new Date(Date.now() + 86_400_000)));
  await page.getByTestId('hab-accorder').click();
  const entitlement = page.getByTestId('acces-' + identifier);
  await expect(page.getByTestId('liste-habilitations')).toContainText(name);
  await expect(page.getByTestId('liste-habilitations')).toContainText('En cours');
  // Confiance hors ligne : sans approbation, aucune confiance effective.
  await page.getByTestId('confiance-minutes').fill('30');
  await page.getByTestId('confiance-enregistrer').click();
  await expect(page.getByTestId('confiance-resume')).toContainText('aucune');
  await page.getByTestId('confiance-approuvee').check();
  await page.getByTestId('confiance-enregistrer').click();
  await expect(page.getByTestId('confiance-resume')).toContainText('30 min');
  await expect(page.getByTestId('liste-terminaux')).toBeVisible();
  await page.screenshot({ path: 'test-results/a06-habilitations-1440.png', fullPage: true });

  // Carte : émission, puis perte déclarée, remplacement ; le titulaire reste le même.
  await page.goto('/administration/titulaires');
  await page.getByTestId('recherche-titulaires').fill(name);
  await page.getByTestId('lancer-recherche').click();
  await page.locator('[data-testid^="titulaire-"]').filter({ hasText: name }).getByRole('link', { name: 'Carte' }).click();
  await page.getByTestId('fichier-portrait').setInputFiles({ name: 'photo-fictive.png', mimeType: 'image/png', buffer: fakePhoto() });
  await page.getByTestId('envoyer-portrait').click();
  await page.getByTestId('preparer-carte').click();
  await expect(page.getByTestId('statut-carte-1')).toContainText('Active');
  await expect(page.getByTestId('aucune-habilitation-titulaire')).toHaveCount(0);
  await page.getByTestId('declarer-perte').click();
  await page.getByTestId('cycle-motif').selectOption('perdue');
  await expect(page.getByTestId('cycle-valider')).toBeDisabled();
  await page.getByTestId('cycle-confirmation').check();
  await page.getByTestId('cycle-valider').click();
  await expect(page.getByTestId('statut-carte-1')).toContainText('Perdue');
  await expect(page.getByTestId('aucune-carte-active')).toBeVisible();
  await page.getByTestId('remplacer-carte').click();
  await page.getByTestId('cycle-motif').selectOption('perdue');
  await page.getByTestId('cycle-confirmation').check();
  await page.getByTestId('cycle-valider').click();
  await expect(page.getByTestId('statut-carte-2')).toContainText('Active');
  await expect(page.getByTestId('statut-carte-1')).toContainText('Perdue');
  await expect(page.getByTestId('identifiant-carte')).toHaveText(identifier);
  await expect(page.getByTestId('apercu-verso')).toContainText('Carte n° 2');
  await page.screenshot({ path: 'test-results/a06-carte-cycle-1440.png', fullPage: true });

  // Tablette.
  await page.setViewportSize({ width: 1024, height: 768 });
  await page.reload();
  await noHorizontalScroll(page, 'carte 1024');
  await page.screenshot({ path: 'test-results/a06-carte-cycle-1024.png', fullPage: true });
  await page.goto('/administration/habilitations');
  await noHorizontalScroll(page, 'habilitations 1024');
  await page.screenshot({ path: 'test-results/a06-habilitations-1024.png', fullPage: true });

  // Retrait de l'habilitation : historique conservé.
  await entitlement.getByRole('button', { name: 'Retirer' }).click();
  await page.getByTestId('motif-retrait').fill('Fin de mission (essai)');
  await page.getByTestId('confirmer-retrait').click();
  await expect(page.getByTestId('liste-habilitations')).toContainText('Retiré');
});
