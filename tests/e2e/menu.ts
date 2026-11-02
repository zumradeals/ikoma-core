import type { Page } from '@playwright/test';

/** Déplie toutes les rubriques du menu latéral (seule la rubrique de la page en cours est dépliée au chargement). */
export async function expandMenu(page: Page): Promise<void> {
  const toggle = page.getByTestId('ouvrir-menu');
  if (await toggle.isVisible() && (await toggle.getAttribute('aria-expanded')) === 'false') await toggle.click();
  const closed = page.locator('[data-testid^="rubrique-"][aria-expanded="false"]');
  while (await closed.count() > 0) await closed.first().click();
}
