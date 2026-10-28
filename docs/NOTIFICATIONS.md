# Notifications de l’hôte

## Livré en L10

Notifications internes dans l’application, sans e-mail, SMS ou appel réseau sortant. « Mes notifications » recharge partiellement ses données toutes les 2,5 secondes lorsque l’onglet est visible. Le compteur et la liste ne concernent que le compte connecté et ses fiches hôte actuelles. Hors réseau, l’écran le signale ; aucune décision n’est promise hors ligne.

Trois types : arrivée enregistrée, visiteur non listé à valider, demande d’avis hors période. Les messages stockés sont génériques ; noms et organisation sont lus depuis la fiche après contrôle d’accès. Une clé de dédoublonnage empêche de notifier deux fois la même arrivée ou demande. L’avis hors période est lié aux dates de validité : un changement de dates invalide l’ancien avis.

Les réponses sont prises sous verrou et une demande traitée ne peut pas être décidée de nouveau. L’accord non listé met à jour host_validation ; il n’élargit jamais l’accès au-delà de l’accueil accompagné. Un refus crée un dossier de refus et une alerte superviseur. L’agent récupère l’accord ou le refus par la copie chiffrée et la lecture autorisée de la fiche ; aucune navigation Inertia n’est introduite dans le poste.

Délai initial sans réponse : **2 minutes**, choix du dirigeant du 28 octobre 2026, réglable dans Administration > Comptes. Le planificateur vérifie les demandes toutes les dix secondes. Les autorisations et privilèges d’audit existants restent inchangés.

## Étude : notifications poussées du navigateur (non implémentées)

Objectif possible : prévenir l’hôte quand l’application n’est pas au premier plan. Aucune garantie de délai : les systèmes mobiles peuvent suspendre le navigateur, refuser les autorisations ou limiter la livraison.

Conditions avant toute réalisation :

- Décision explicite du dirigeant sur le fournisseur, l’hébergement et l’exception aux appels réseau sortants. L’envoi de Web Push requiert généralement un service de distribution externe au serveur.
- HTTPS et service worker maîtrisé ; demande de permission volontaire, expliquée à l’utilisateur, sans bloquer l’usage normal.
- Abonnement rattaché au compte et à son appareil, renouvelable et révocable ; contrôle serveur strict et protection CSRF à chaque gestion d’abonnement. Suppression à la déconnexion explicite et lors de la désactivation du compte.
- Clés privées VAPID stockées hors du dépôt, rotation et sauvegarde encadrées ; aucun secret dans une réponse, une capture ou un journal.
- Notification générique sur l’écran verrouillé, sans nom, organisation, photo, badge, jeton ou détail d’accès. L’application ouverte consulte les données avec authentification et contrôle d’objet ; le message poussé n’accorde aucun droit et ne permet pas de répondre directement sans contrôle.
- Aucune réponse authentifiée mise en cache par le service worker. Expiration courte, dédoublonnage, limitation de débit, absence de contenu nominatif dans les traces du fournisseur.
- Validation juridique et des durées de conservation au lot L11 ; tests de révocation, de changement de compte, de tablette partagée, de perte de réseau et d’abonnement volé.

Décision demandée plus tard : intérêt du push par rapport au rechargement déjà livré, acceptation du tiers de distribution et conditions d’exploitation. Aucun fournisseur ni abonnement n’est ajouté par L10.
