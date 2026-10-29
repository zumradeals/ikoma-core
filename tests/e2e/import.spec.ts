import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';
function otp(secret: string): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of secret) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes = []; for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hash = createHmac('sha1', Buffer.from(bytes)).update(counter).digest(); const offset = hash[19] & 15;
  return ((hash.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}
test('un administrateur contrôle 1000 lignes, confirme et retrouve les visiteurs', async ({ page }) => {
 const user = JSON.parse(readFileSync('.secrets/screen-import.json', 'utf8')) as { email: string; password: string; secret: string };
 await page.setViewportSize({width:1280,height:960});
 await page.goto('/login'); await page.getByLabel('Adresse électronique').fill(user.email); await page.getByLabel('Mot de passe', {exact:true}).fill(user.password); await page.getByRole('button',{name:'Se connecter'}).click();
 await page.getByLabel('Code à six chiffres').fill(otp(user.secret)); await page.getByRole('button',{name:'Vérifier',exact:true}).click();
 await page.getByRole('link',{name:'Liste d’invités',exact:true}).click();await expect(page.getByRole('heading',{name:'1. Choisir l’événement et le fichier'})).toBeVisible();await page.screenshot({path:'test-results/l3-etape-1.png',fullPage:true});
 await page.getByLabel('Fichier de visiteurs').setInputFiles('tests/fixtures/liste-fictive-1000.csv');await page.getByRole('button',{name:'Vérifier le fichier',exact:true}).click();
 const report=page.getByRole('region',{name:'Rapport de contrôle'});await expect(report).toContainText('985');await expect(report).toContainText('15');await expect(report).toContainText('Homonyme');await expect(report).toContainText('Doublon');
 await page.screenshot({path:'test-results/l3-etape-2.png',fullPage:true});await page.getByRole('button',{name:'Continuer vers la confirmation'}).click();await expect(page.getByRole('heading',{name:'3. Confirmer l’import'})).toBeVisible();await page.screenshot({path:'test-results/l3-etape-3.png',fullPage:true});
 await page.getByRole('button',{name:'Importer les lignes valides'}).click();await expect(page.getByRole('heading',{name:'✓ Import terminé'})).toBeVisible();await expect(page.getByText('985 visiteurs ajoutés', {exact:false})).toBeVisible();
 await page.getByLabel('Nom à rechercher').fill('Personne fictive L3 0001');await page.getByRole('button',{name:'Rechercher',exact:true}).click();await expect(page.getByText('Personne fictive L3 0001 · Visiteur',{exact:true})).toBeVisible();
});
