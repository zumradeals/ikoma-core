# Gérer les données dans IKOMA ACCESS

## Personnes

Dans Liste d'invités, recherchez et ouvrez la fiche. « Retirer la fiche » bloque les admissions futures et conserve les contrôles précédents. Une personne présente doit d'abord sortir réellement. « Consulter les fiches retirées » permet de retrouver ces fiches.

« Analyser une suppression » ouvre une vérification. Une fiche avec portrait, badge nominatif, contrôle ou autre dépendance protégée ne peut pas être effacée. Pour une fiche inutilisée, recopiez sa référence puis confirmez votre mot de passe. Une modification survenue entre-temps oblige à refaire l'analyse.

## Badges

« Consulter le lot » filtre ses badges ; chaque carte possède un lien vers ses détails, réservations et associations. L'annulation reste du ressort du superviseur, avec motif. L'administrateur ne reçoit pas ce droit automatiquement.

Un lot réservé, nominatif, utilisé ou présenté à l'impression ou à un aperçu contenant un véritable QR ne peut pas être supprimé. Pour les lots antérieurs à cette version, l'absence d'impression ne peut pas être prouvée : ils restent protégés. Les numéros des lots retirés ne sont jamais réattribués.

## Événements

Le menu Actions permet de modifier une préparation, clôturer, archiver ou analyser une suppression. La fin prévue n'est pas une clôture. Synchronisez d'abord les tablettes et traitez tous les conflits ; révoquez ensuite les postes et reconnectez-les pour qu'ils déclarent leur effacement. Le serveur refuse une clôture avec présences, restitutions ou opérations bloquantes. Il ne crée aucune sortie à votre place.

Un événement clôturé peut être archivé. « Consulter les archives » permet de le retrouver et « Ouvrir » donne accès à ses informations. Aucune réouverture n'est proposée dans ce lot.

## Gestion des données

Le menu regroupe les compteurs réels, archives, durées applicables, analyses et historique. Le nettoyage de démonstration concerne uniquement le jeu identifié, jamais un événement jugé fictif d'après son nom. Le nettoyage de conservation applique les échéances approuvées et conserve les références statistiques.

1. Sélectionnez l'opération puis « Analyser sans supprimer ».
2. Lisez les quantités et les références conservées.
3. Recopiez le mot de confirmation et confirmez votre mot de passe.
4. La demande attend le service de maintenance, qui vérifie une sauvegarde complète récente et recommence l'analyse.
5. Consultez son résultat. « En attente » ou « Fichiers à reprendre » ne signifie pas « Terminée ».

Les durées approuvées ne peuvent pas être raccourcies depuis l'écran pour forcer un effacement.

## Exploitation et récupération

Le service ponctuel `maintenance` est distinct de PHP-FPM, utilise le compte propriétaire déjà prévu pour les migrations et n'expose ni commandes ni secrets au web. `deploy/prod/maintenance.sh`, planifié chaque minute lors d'une nouvelle installation, vérifie les demandes et lance la sauvegarde existante avant le traitement. Aucun de ces scripts n'est exécuté en production dans cette livraison. Pour une installation existante, l'exploitant devra installer cette planification après autorisation de déploiement.

Le manifeste de sauvegarde doit couvrir base, fichiers et effectifs, avec empreintes vérifiées et une ancienneté maximale d'une heure. Ce contrôle prouve la présence et l'intégrité des exports chiffrés ; il ne remplace pas un exercice de restauration. La copie externe reste celle de la politique d'exploitation existante.

Les fichiers sont inscrits dans `pending_file_deletions` pendant la transaction et supprimés après commit. Un échec laisse une entrée non terminée et un état visible. Le service reprend ces entrées sans réappliquer l'anonymisation. Un crash après validation de la base est récupéré depuis l'état `base_validee`. Ne pas rejouer une suppression de base déjà validée.

## Limites explicites

- Administrateurs globaux à une installation : ce lot n'introduit pas une architecture multi-clients inexistante dans le schéma actuel.
- Une tablette déconnectée ne reçoit aucune révocation instantanée ; ses opérations restent soumises aux contrôles de synchronisation existants. Une déclaration de perte bloque le bilan par un conflit.
- La preuve de non-impression est conservatrice : toute exposition à l'impression protège le lot, même si l'utilisateur annule sa boîte d'impression.
- Les groupes utilisent leur workflow existant (départ et fin d'arrivée). Aucune clôture parallèle n'est inventée.
- Les postes sont retirés par révocation ; leurs références ne sont pas supprimées.
- L'analyse utilise une empreinte de l'installation entière. Une activité sans rapport direct peut donc demander une nouvelle analyse ; le verrou de gestion est bref mais bloque les écritures. À mesurer sur les volumes réels avant une exploitation de grande taille.
