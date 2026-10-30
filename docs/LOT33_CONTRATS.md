# L33 — Audit et contrats de gestion des données

Base auditée : c7e8b0fde8b2ec110ce61f6dbfb21ab59e81bc79.

## Audit préalable

- EventsController crée et ouvre ; `events.status` existe sans cycle opérationnel. Dates et anonymisation ne constituent pas une clôture.
- AdminVisitorsController/HostGuests : modification stricte, retrait logique déjà présent ; ce retrait refuse à tort tout historique. `withdrawn_at`, `list_revision`, Desk, BadgeService et ListSnapshot protègent déjà les admissions.
- BadgeService impose `badges.annuler` et un motif ; l'administrateur ne possède pas cette permission. BadgeLots génère les codes depuis le maximum vivant : supprimer le dernier lot réutiliserait ses numéros. BadgePreparation protège la destination nominative par trigger.
- Zones/catégories : modification existante, références composites et RESTRICT ; accueil obligatoire à protéger. Groupes : workflow déjà explicite, pas de suppression. Postes : révocation et effacement local existants, historique conservé.
- Comptes : désactivation existante ; protection du compte courant, mais ajouter une vérification collective du dernier administrateur sous le verrou existant.
- PurgeDemoData : identifiants déterministes et contrôle de composition/activité, transaction ; extraction du même moteur pour l'interface, sans appel de commande système.
- DataRetention : compte privilégié distinct, conservation approuvée, présences protégées ; suppression de fichiers dans la transaction à remplacer par une file durable après validation. Pas de purge d'audit ajoutée.
- Tablettes : une copie hors ligne ne peut pas recevoir une révocation instantanée. Une clôture exige que tous les appareils enrôlés soient révoqués ET aient accusé l'effacement, aucun conflit ouvert, aucune association à restituer et aucune présence. Le serveur bloque aussi les nouvelles écritures sur un événement clos/archivé.
- Aucun cloisonnement client n'existe dans ce schéma : les administrateurs sont globaux à l'installation ; aucun nouveau pouvoir transversal n'est accordé aux hôtes/agents.

## Matrice des opérations et permissions

| Entité | Opération | Permission existante | Conditions / références conservées |
|---|---|---|---|
| Personne | consulter les retirées, retirer | listes.importer | retrait interdit si présente ; historique, portrait et destination conservés |
| Personne | modifier | listes.importer | règles HostGuests strictes existantes |
| Personne | supprimer | listes.importer | aucun contrôle, badge, portrait, groupe, notification, refus ni synchronisation ; attendu uniquement |
| Badge | détails | badges.gerer | sans divulgation du jeton QR |
| Badge | annuler | badges.annuler | BadgeService, motif obligatoire ; aucun contournement administrateur |
| Lot | supprimer | badges.gerer | tous disponibles, non réservés, non nominatifs, jamais utilisés, jamais présentés à l'impression ni à une tablette ; numéros non réattribués |
| Événement | modifier | evenements.gerer | préparation sans activité ni tablette enrôlée |
| Événement | clôturer | evenements.gerer | aucune présence ni restitution/conflit/notification en attente ; appareils révoqués avec effacement confirmé |
| Événement | archiver | evenements.gerer | clôturé ; consultation conservée |
| Événement | supprimer | evenements.gerer | inutilisé ; seules zones, catégories et leurs liaisons préparatoires supprimables |
| Catégorie | supprimer | categories.gerer | aucune référence ; seules ses liaisons de configuration sont supprimées explicitement |
| Zone | supprimer | zones.gerer | non accueil, aucune liaison de sécurité ni poste ni historique de contrôle |
| Groupe | supprimer | groupes.gerer | aucun membre, réservation, lot ou workflow commencé |
| Poste | retirer | postes.gerer | mécanisme de révocation existant ; jamais de suppression de traces |
| Compte | désactiver | comptes.gerer + roles.attribuer | dernier administrateur actif protégé, références conservées |
| Démonstration | purge | comptes.gerer + rôle administrateur | moteur partagé, preuve de sauvegarde, confirmation renforcée ; exécution privilégiée |
| Conservation | purge/anonymisation | comptes.gerer + rôle administrateur | durées approuvées, événement clos/archivé, aucune dépendance bloquante ; exécution privilégiée |

## États et transitions

`NULL` ou état historique = ouvert. `clos` puis `archive`. Aucune réouverture dans ce lot : une réactivation exigerait de réenrôler les appareils et de réexaminer les garanties. La date de fin reste prévisionnelle. Le champ existant status suffit.

Le retrait conserve l'historique ; l'anonymisation reste collective à échéance de conservation, jamais un effacement individuel arbitraire. Les durées approuvées ne peuvent pas être raccourcies par l'écran.

## Suppressions et concurrence

Un seul moteur analyse les dépendances et exécute les suppressions. Analyse avant confirmation et analyse identique sous verrou au moment de l'exécution. Aperçu lié à l'acteur, l'entité, l'état des dépendances et expirant après dix minutes. Saisie de la référence et du mot de passe courant, 2FA confirmée. Les refus et échecs sont journalisés après annulation de la transaction métier, sans données personnelles.

Les opérations de gestion prennent des verrous exclusifs sur les tables modifiables (les références étrangères empêchent les insertions concurrentes dans les journaux protégés), avec attente maximale de cinq secondes. Elles prennent ces verrous dans un ordre commun pour fermer les courses entre aperçu et écritures opérationnelles. Les contraintes RESTRICT restent le dernier rempart. Aucun DELETE sur audit/access_events/identity_checks n'est accordé au compte web.

## Maintenance privilégiée

Le web dépose une demande bornée à `demo` ou `conservation` avec l'empreinte de l'aperçu. Un processus distinct, lancé avec le compte de maintenance déjà prévu, recontrôle droits actuels, état, échéance de l'aperçu et preuve matérielle de sauvegarde. Aucun SQL, chemin de fichier ou commande soumis par l'administrateur n'est exécuté.

Les fichiers devenus sans référence sont inscrits dans une file durable dans la transaction ; leur effacement survient après commit, avec reprise idempotente et état observable. En cas d'échec fichier, ne pas déclarer le nettoyage complet. Le journal d'audit reste intact.

Une preuve de sauvegarde doit couvrir les exports base et fichiers et leurs empreintes. Le simple indicateur BackupStatus ne prouve pas leur présence. En absence de preuve, l'opération privilégiée reste bloquée. L'installation du processus de maintenance relève de l'exploitation, sans déploiement automatique dans cette mission.

## Compléments de revue

La déclaration d’effacement est reçue après effacement local ; envoyer un ordre ne vaut pas accusé. Les anciens marqueurs des postes révoqués sont invalidés par migration. Une perte déclarée crée un conflit bloquant. Une politique approuvée ne peut plus être désapprouvée depuis cet écran. Les identifiants retirés sont en ajout seul pour le compte web. Les fichiers encore référencés sont protégés même si une entrée de file existe.
