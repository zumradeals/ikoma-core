export const roleLabels: Record<string, string> = {
  administrateur: 'Administrateur', superviseur: 'Superviseur sécurité', agent_accueil: "Agent d’accueil", hote: 'Hôte / organisateur', encadreur: 'Encadreur de groupe',
};
export const actionLabels: Record<string, string> = {
  'liste.import_apercu': 'Liste : aperçu contrôlé', 'liste.import_confirme': 'Liste : import confirmé', 'liste.import_abandonne': 'Liste : import abandonné',
  'badge.lot_cree': 'Badges : lot créé', 'badge.reserve': 'Badges : plage réservée', 'badge.associe': 'Badge associé', 'badge.restitue': 'Badge restitué', 'badge.annule': 'Badge annulé', 'badge.controle_refuse': 'Badge : contrôle refusé', 'badge.reglage_modifie': 'Badges : réglages modifiés',
  'zone.creee': 'Zone créée', 'zone.modifiee': 'Zone modifiée', 'categorie.creee': 'Catégorie créée', 'categorie.modifiee': 'Catégorie modifiée',
  'connexion.reussie': 'Connexion réussie', 'connexion.echec': 'Échec de connexion', 'deconnexion': 'Déconnexion',
  'acces.refuse': 'Accès refusé', 'compte.cree': 'Compte créé', 'compte.active': 'Compte activé', 'compte.desactive': 'Compte désactivé',
  'role.change': 'Rôle modifié', 'mot_de_passe.reinitialise': 'Mot de passe réinitialisé', 'session.expiree': 'Session expirée', 'session.duree_modifiee': "Durée d’inactivité modifiée",
};
export function displayDate(value: string): string {
  return new Intl.DateTimeFormat('fr-CI', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value));
}
