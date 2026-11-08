import type { LocalList } from './list';

export type Capability = 'accueil' | 'scan' | 'sortie';
export type PosteRefusal = { reason: 'poste_non_identifie' | 'poste_non_habilite' | 'politique_non_approuvee' | 'politique_perimee'; message: string };

/**
 * Classement des opérations de la file hors ligne (S02).
 *  - `capability` : capacité de poste exigée (identique côté serveur, PostCapabilities) ;
 *  - `favorable` : l'opération produit une DÉCISION D'ACCÈS (contrôle valide, validation, badge, entrée, remise de badge de groupe, arrivée d'un car).
 *    Sans réseau, elle n'est permise que sous un délai approuvé et sur une copie assez récente.
 * Ne produisent aucune décision d'accès, donc jamais bloquées par la péremption : refus, identité, non listé, sortie (jamais soumise à la confiance), et les
 * opérations de car groupe_controle, groupe_depart et groupe_arrivee_fin (aucune porte de capacité non plus).
 */
export const OP_POLICY: Record<string, { capability: Capability; favorable: boolean }> = {
  identite: { capability: 'accueil', favorable: false }, valider: { capability: 'accueil', favorable: true }, refuser: { capability: 'accueil', favorable: false },
  badge: { capability: 'accueil', favorable: true }, entree: { capability: 'accueil', favorable: true }, non_liste: { capability: 'accueil', favorable: false },
  controle: { capability: 'scan', favorable: true }, sortie: { capability: 'sortie', favorable: false },
  groupe_badge: { capability: 'accueil', favorable: true }, groupe_arrivee: { capability: 'scan', favorable: true },
};

/**
 * Restrictions de poste appliquées localement AVANT tout résultat favorable, avec les règles du serveur (qui les revérifie à la synchronisation).
 *  - poste non identifié (aucun point de contrôle, ou copie sans capacités) : refus ;
 *  - capacité absente du point tenu par la tablette : refus ;
 *  - décision favorable SANS RÉSEAU : refus tant qu'aucun délai n'est approuvé (délai nul, absent ou non approuvé = refus, jamais « illimité »), puis refus si la
 *    copie est plus ancienne que ce délai. Aucune durée n'est fixée ici : c'est celle que l'institution a approuvée.
 *  - les cartes permanentes (`permanent`) gardent leur règle de confiance propre : seule la capacité de poste s'applique ici.
 * Retourne null quand l'opération peut être décidée localement.
 */
export function posteGate(list: LocalList, policy: { capability: Capability; favorable: boolean }, now: number, knownAt: string | null, context: { offline: boolean; permanent?: boolean }): PosteRefusal | null {
  const poste = list.snapshot.poste;
  if (!poste.point_code || !Array.isArray(poste.capabilities)) {
    return { reason: 'poste_non_identifie', message: 'Ce poste n’est pas identifié : l’administrateur doit préciser son point de contrôle.' };
  }
  if (!poste.capabilities.includes(policy.capability)) {
    return { reason: 'poste_non_habilite', message: 'Cette opération n’est pas permise à ce poste.' };
  }
  if (!policy.favorable || !context.offline || context.permanent) return null;
  const hours = list.snapshot.settings.offline_trust_hours ?? 0;
  if (!(hours > 0)) {
    return { reason: 'politique_non_approuvee', message: 'Aucun délai hors ligne n’est approuvé : sans réseau, aucun badge ni aucune admission n’est accepté. Reconnectez la tablette.' };
  }
  if (knownAt === null || now - Date.parse(knownAt) > hours * 3_600_000) {
    return { reason: 'politique_perimee', message: 'La copie de cette tablette est trop ancienne pour autoriser un passage : reconnectez-la au réseau.' };
  }
  return null;
}
