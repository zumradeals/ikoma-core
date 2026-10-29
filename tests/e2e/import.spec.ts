import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { loginWithSecondFactor } from './otp';
test('un administrateur contrôle 1000 lignes, confirme et retrouve les visiteurs', async ({ page }) => {
 test.setTimeout(180_000);

 const user = JSON.parse(readFileSync('.secrets/screen-import.json', 'utf8')) as { email: string; password: string; secret: string };
 await page.setViewportSize({width:1280,height:960});
 await loginWithSecondFactor(page, user.email, user.password, user.secret);
 await page.getByRole('link',{name:'Liste d’invités',exact:true}).click();await expect(page.getByRole('heading',{name:'1. Choisir l’événement et le fichier'})).toBeVisible();await page.screenshot({path:'test-results/l3-etape-1.png',fullPage:true});
 await page.getByLabel('Fichier de visiteurs').setInputFiles('tests/fixtures/liste-fictive-1000.csv');await page.getByRole('button',{name:'Vérifier le fichier',exact:true}).click();
 const report=page.getByRole('region',{name:'Rapport de contrôle'});await expect(report).toContainText('985');await expect(report).toContainText('15');await expect(report).toContainText('Homonyme');await expect(report).toContainText('Doublon');
 await page.screenshot({path:'test-results/l3-etape-2.png',fullPage:true});await page.getByRole('button',{name:'Continuer vers la confirmation'}).click();await expect(page.getByRole('heading',{name:'3. Confirmer l’import'})).toBeVisible();await page.screenshot({path:'test-results/l3-etape-3.png',fullPage:true});
 await page.getByRole('button',{name:'Importer les lignes valides'}).click();await expect(page.getByRole('heading',{name:'✓ Import terminé'})).toBeVisible();await expect(page.getByText('985 visiteurs ajoutés', {exact:false})).toBeVisible();
 await page.getByLabel('Nom à rechercher').fill('Personne fictive L3 0001');await page.getByRole('button',{name:'Rechercher',exact:true}).click();await expect(page.getByText('Personne fictive L3 0001 · Visiteur',{exact:true})).toBeVisible();
});
