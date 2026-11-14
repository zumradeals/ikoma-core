import { PosteRuntime } from './runtime';

/**
 * Coffre de la tablette partagé pendant TOUTE la connexion de l'agent : un seul exemplaire pour la page entière, ouvert automatiquement, sans aucune saisie.
 * Passer de l'accueil au contrôle, aux groupes, à « Mon espace » ou à « Sécurité » retrouve ce même coffre ouvert (les écrans se changent sans recharger la page).
 * La clé de données reste UNIQUEMENT en mémoire. Se déconnecter ou ouvrir la page de connexion supprime le bail de la tablette (`closeSharedSession`) : le coffre
 * ne se rouvre qu'après une nouvelle connexion en ligne. Les données et la file d'opérations restent chiffrées ; rien n'est effacé.
 */
let shared: PosteRuntime | null = null;

export function sharedRuntime(): PosteRuntime {
  shared ??= new PosteRuntime();
  return shared;
}

/** Pour les tests : remplace le coffre partagé (et le ferme avant). */
export function useSharedRuntime(runtime: PosteRuntime | null): void {
  shared?.lock();
  shared = runtime;
}

/** Ferme le coffre en mémoire ET supprime le bail de la tablette (déconnexion, page de connexion). */
/** Même sans coffre ouvert dans cette page (page rechargée après la déconnexion) : le bail est supprimé du stockage de la tablette. */
export function closeSharedSession(): Promise<void> {
  // Sans IndexedDB (navigation privée, navigateur sans stockage) il n'existe aucun bail à supprimer : l'échec est sans conséquence.
  return sharedRuntime().closeSession().catch(() => undefined);
}
