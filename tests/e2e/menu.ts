import type { Page } from '@playwright/test';

/** Ouvre le menu latéral quand il est replié (petit écran). Les sept menus sont toujours plats : il n'y a plus de rubrique à déplier. */
export async function expandMenu(page: Page): Promise<void> {
  const toggle = page.getByTestId('ouvrir-menu');
  if (await toggle.isVisible() && (await toggle.getAttribute('aria-expanded')) === 'false') await toggle.click();
}

/** Va à l'un des sept menus (`site`, `personnes`, `activites`, `badges-cartes`, `operations`, `administration`, `tableau`). */
export async function openMenu(page: Page, menu: string): Promise<void> {
  await expandMenu(page);
  await page.getByTestId(`menu-${menu}`).click();
}

/** Va à la gestion des zones : toute zone est une zone du site, gérée dans la section « Zones » de Site et accès. */
export async function openZones(page: Page): Promise<void> {
  await openMenu(page, 'site');
  await page.getByTestId('tab-zones').click();
}

/** Va à une page d'un menu, par son sous-menu (onglet sous le titre) : `openPage(page, 'administration', 'comptes')`. */
export async function openPage(page: Page, menu: string, item: string): Promise<void> {
  await openMenu(page, menu);
  const tab = page.getByTestId(`sous-menu-${item}`);
  if (await tab.getAttribute('aria-current') !== 'page') await tab.click();
}
