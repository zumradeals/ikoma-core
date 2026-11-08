import type { LocalList } from './list';

export type Capability = 'accueil' | 'scan' | 'sortie';
export type PosteRefusal = { reason: 'poste_non_identifie' | 'poste_non_habilite' | 'politique_perimee'; message: string };

/** Opérations de la file soumises à une capacité de poste. Les opérations de groupe ne sont pas concernées par S02. */
export const OP_CAPABILITY: Record<string, Capability> = {
  identite: 'accueil', valider: 'accueil', refuser: 'accueil', badge: 'accueil', entree: 'accueil', non_liste: 'accueil', controle: 'scan', sortie: 'sortie',
};

/**
 * S02 : restrictions de poste appliquées localement AVANT tout résultat favorable, avec les règles du serveur (qui les revérifie à la synchronisation).
 *  - poste non identifié (aucun point de contrôle, ou copie sans capacités) : refus ;
 *  - capacité absente du point tenu par la tablette : refus ;
 *  - copie plus ancienne que la limite DÉJÀ validée (poste_hors_reseau_max_heures ; 0 = désactivée) : refus. Aucune durée nouvelle.
 * Retourne null quand l'opération peut être décidée localement.
 */
export function posteGate(list: LocalList, capability: Capability, now: number, knownAt: string | null): PosteRefusal | null {
  const poste = list.snapshot.poste;
  if (!poste.point_code || !Array.isArray(poste.capabilities)) {
    return { reason: 'poste_non_identifie', message: 'Ce poste n’est pas identifié : l’administrateur doit préciser son point de contrôle.' };
  }
  if (!poste.capabilities.includes(capability)) {
    return { reason: 'poste_non_habilite', message: 'Cette opération n’est pas permise à ce poste.' };
  }
  const maxHours = list.snapshot.settings.offline_max_hours ?? 0;
  if (maxHours > 0 && (knownAt === null || now - Date.parse(knownAt) > maxHours * 3_600_000)) {
    return { reason: 'politique_perimee', message: 'La copie de cette tablette est trop ancienne pour autoriser un passage : reconnectez-la au réseau.' };
  }
  return null;
}
