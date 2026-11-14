import { PosteRuntime } from './runtime';

/**
 * Coffre de la tablette partagé pendant TOUTE la connexion de l'agent : un seul exemplaire pour la page entière.
 * Le PIN est saisi une fois ; tant que l'agent reste connecté, passer de l'accueil au contrôle, aux groupes, à « Mon espace » ou à « Sécurité » ne le redemande pas,
 * parce que ces écrans se changent sans recharger la page et retrouvent ce même coffre ouvert. La clé de données reste UNIQUEMENT en mémoire (jamais enregistrée en clair,
 * jamais dans le navigateur) : recharger la page, fermer l'application, se déconnecter, ouvrir la page de connexion, verrouiller le poste ou rester inactif la fait disparaître,
 * et le PIN est alors redemandé. Le chiffrement des données et la protection par PIN ne changent pas.
 */
let shared: PosteRuntime | null = null;
let idleTimer: ReturnType<typeof setTimeout> | undefined;
let idleMs = 300_000;
let listening = false;
const ACTIVITY = ['pointerdown', 'keydown', 'touchstart'] as const;

export function sharedRuntime(): PosteRuntime {
  shared ??= new PosteRuntime();
  return shared;
}

/** Pour les tests : remplace le coffre partagé (et le verrouille avant). */
export function useSharedRuntime(runtime: PosteRuntime | null): void {
  lockSharedRuntime();
  shared = runtime;
}

function bump(): void {
  clearTimeout(idleTimer);
  if (shared?.vault.unlocked) idleTimer = setTimeout(() => lockSharedRuntime(), idleMs);
}

/**
 * Verrouillage à l'inactivité, indépendant de l'écran affiché : le coffre ne reste jamais ouvert sans limite quand l'agent est sur une autre page
 * (réglage « inactivité » de l'Administration, inchangé).
 */
export function armIdleLock(minutes: number): void {
  idleMs = Math.max(1, minutes) * 60_000;
  if (!listening && typeof window !== 'undefined') { for (const name of ACTIVITY) window.addEventListener(name, bump, { passive: true }); listening = true; }
  bump();
}

/** Ferme le coffre (déconnexion, page de connexion, verrouillage voulu). Les opérations en attente restent chiffrées, rien n'est effacé. */
export function lockSharedRuntime(): void {
  clearTimeout(idleTimer);
  shared?.lock();
}
