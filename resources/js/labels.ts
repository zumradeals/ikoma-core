export const roleLabels: Record<string, string> = {
  administrateur: 'Administrateur', superviseur: 'Superviseur sécurité', agent_accueil: "Agent d’accueil", hote: 'Hôte / organisateur', encadreur: 'Encadreur de groupe',
};
export const actionLabels: Record<string, string> = {
  'liste.import_apercu': 'Liste : aperçu contrôlé', 'liste.import_confirme': 'Liste : import confirmé', 'liste.import_abandonne': 'Liste : import abandonné',
  'badge.lot_cree': 'Badges : lot créé', 'badge.reserve': 'Badges : plage réservée', 'badge.associe': 'Badge associé', 'badge.restitue': 'Badge restitué', 'badge.annule': 'Badge annulé', 'badge.controle_refuse': 'Badge : contrôle refusé', 'badge.reglage_modifie': 'Badges : réglages modifiés',
  'visiteur.identite_verifiee': 'Visiteur : identité et photo enregistrées', 'visiteur.valide': 'Visiteur validé', 'visiteur.refuse': 'Visiteur refusé', 'visiteur.entree': 'Visiteur : entrée enregistrée', 'visiteur.non_liste_cree': 'Visiteur non listé créé', 'visiteur.hote_decision': 'Décision de l’hôte sur un non listé',
  'controle.refuse': 'Contrôle sur site : refusé ou zone non autorisée', 'sortie.enregistree': 'Sortie enregistrée', 'sortie.refusee': 'Sortie refusée', 'presence.corrigee': 'Présence corrigée', 'poste.cree': 'Poste créé', 'poste.code_emis': 'Code d’enrôlement émis', 'poste.enrole': 'Poste enrôlé', 'poste.enrolement_refuse': 'Enrôlement refusé', 'poste.revoque': 'Poste révoqué', 'poste.efface': 'Tablette effacée', 'poste.reglage_modifie': 'Réglages des postes modifiés', 'sync.recue': 'Synchronisation reçue', 'sync.conflit': 'Conflit de synchronisation',
  'groupe.cree': 'Groupe créé', 'groupe.ouvert': 'Groupe ouvert pour l’appel', 'groupe.badge_remis': 'Groupe : badge remis', 'groupe.absent': 'Groupe : stagiaire absent', 'groupe.embarque': 'Groupe : car fermé, embarquement enregistré', 'groupe.arrive': 'Groupe : stagiaire arrivé', 'groupe.confirme_par_encadreur': 'Groupe : présence confirmée par l’encadreur', 'groupe.encadreur_designe': 'Groupe : encadreur désigné', 'groupe.stagiaire_rattache': 'Groupe : stagiaire rattaché', 'groupe.stagiaire_retire': 'Groupe : stagiaire retiré', 'groupe.reglage_modifie': 'Groupes : règle de contrôle modifiée',
  'zone.creee': 'Zone créée', 'zone.modifiee': 'Zone modifiée', 'categorie.creee': 'Catégorie créée', 'categorie.modifiee': 'Catégorie modifiée',
  'connexion.reussie': 'Connexion réussie', 'connexion.echec': 'Échec de connexion', 'deconnexion': 'Déconnexion',
  'acces.refuse': 'Accès refusé', 'compte.cree': 'Compte créé', 'compte.active': 'Compte activé', 'compte.desactive': 'Compte désactivé',
  'role.change': 'Rôle modifié', 'mot_de_passe.reinitialise': 'Mot de passe réinitialisé', 'session.expiree': 'Session expirée', 'session.duree_modifiee': "Durée d’inactivité modifiée",
};
export function displayDate(value: string): string {
  return new Intl.DateTimeFormat('fr-CI', { dateStyle: 'short', timeStyle: 'medium' }).format(new Date(value));
}
