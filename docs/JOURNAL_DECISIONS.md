# Journal des décisions

## Décisions validées

| Date | Décision | Raison | Validé par |
|---|---|---|---|
| 2026-10-27 | Pile technique : Laravel + Inertia.js + Vue 3 (TypeScript) + PostgreSQL + Tailwind CSS. Livewire écarté. Poste d'accueil en PWA hors ligne. | Le cahier des charges exige un poste d'accueil fonctionnel sans internet. | Koné Djakaridja (dirigeant) |
| 2026-10-27 | Matrice L1 validée ; ajout de `categories.gerer` au rôle `administrateur` uniquement. | Document de fonctionnement v1.0, section 10 : « gestion des badges, zones et catégories ». | Dirigeant — validation explicite |

## Décisions en attente (voir section 12 du plan)

2. Hébergement de production (serveur local chez le client ou hébergement sécurisé)
3. Règle de contrôle des stagiaires (pièce d'identité ou encadreur)
4. Règle de restitution des badges (réutilisables ou usage unique)
5. Traitement des VIP (niveau de discrétion)
6. Durée de conservation des données et responsable de la liste
7. Nom définitif du produit et propriété du code
8. Matériel (tablettes, onduleurs, imprimante)

## Modèle de ligne

`| date | décision | raison | qui a validé |`

## L1 — décisions techniques du 27 octobre 2026

- Référence L1 et L2 : `docs/reference/Mode_de_fonctionnement_verification_visiteurs.pdf`, v1.0, sections 2 et 10 pour les droits. PDF déplacé depuis docs/, contenu conservé. La matrice est dans `docs/MATRICE_DROITS.md`, validée par le dirigeant le 27 octobre 2026 avec l’ajout de `categories.gerer` pour l’administrateur uniquement.
- Fortify 1.41.0 et spatie/laravel-permission 8.3.2, versions stables résolues avant installation. Pas d’inscription publique, pas de contournement global administrateur.
- Sessions SQL ; inactivité dans settings.session_idle_minutes, valeur initiale 15 minutes, modifiable dans l’écran de sécurité des comptes par l’administrateur.
- Journal : empreinte SHA-256 canonique incluant aussi l’identifiant de ligne, verrou transactionnel PostgreSQL pour sérialiser les ajouts, acteur conservé par UUID, aucun mot de passe ou secret TOTP journalisé. Le compte applicatif ne possède pas les tables et reçoit SELECT/INSERT seulement sur audit_logs. Le compte de migration distinct reste privilégié. Le chaînage détecte une altération isolée ; un administrateur de base capable de réécrire toute la chaîne reste hors de cette garantie, sans ancrage externe.
- Correctif PWA : révision de / calculée automatiquement à partir des sources du build ; aucun contenu authentifié ni écran d’administration mis en cache. Un test reconstruit deux contenus et vérifie la mise à jour puis la réouverture hors ligne.


## L2 — modèle métier et administration de base, 27 octobre 2026

- Départ de main après fusion de L0/L1. Source : `docs/reference/Mode_de_fonctionnement_verification_visiteurs.pdf`, sections 4, 7 et 8 ; schéma documenté dans `docs/schema.md`.
- UUID pour toutes les nouvelles tables, clés composites pour empêcher les références entre événements différents. Tables users/sessions/settings/audit_logs, rôles et permissions, service Audit et privilèges L1 inchangés.
- Statuts badges exacts en enum PHP + CHECK SQL, Clos réservé. Index partiel sur les associations actives (sans restitution et sans annulation). Aucune machine à états ni transition automatique.
- Zones d’un visiteur dérivées uniquement de sa catégorie. Photos : référence seulement. Aucun module d’accueil, scan, import ou groupe opérationnel.
- Normalisation de recherche SQL IMMUTABLE avec dictionnaire unaccent explicitement qualifié ; index GIN trigramme. Dictionnaire considéré stable, REINDEX obligatoire après modification. Recherche limitée à un événement et dix résultats minimaux, sans API exposée.
- Jeu de démonstration transactionnel et idempotent : 300 visiteurs fictifs, 400 badges dans un lot, 60 réservés à un poste fictif ; un car de 40 stagiaires. Les comptes L1 ne sont pas réinitialisés.
- Zones et catégories : audit atomique avec identifiant/événement et liste des champs modifiés, sans copie des valeurs inutiles. Badges en lecture seule, QR jamais exposé.
- Le test historique FoundationTest L1 est conservé à l’identique dans un schéma L1 isolé ; la suite L2 vérifie le schéma complet et les privilèges réels. Le temps de migrate-fresh-seed est mesuré en CI, avec sortie des mots de passe fictifs gardée privée.
- Questions non arbitrées : permission d’administration des événements ; éventuelles exceptions de zones individuelles ; réutilisation/usage unique et Clos ; vocabulaire des statuts visiteurs/événements/imports ; contrôle des stagiaires. Aucun droit ni parcours supplémentaire créé.


## Décisions du dirigeant, 27 octobre 2026

Source : consignes L3 et [document de fonctionnement v1.0](reference/Mode_de_fonctionnement_verification_visiteurs.pdf), section 5 « Préparation avant le jour J » (import, doublons, homonymes), section 7 (second critère).

- Statuts de visiteur validés : `attendu`, `present`, `sorti`, `refuse`. L’import crée uniquement `attendu` ; aucune transition métier dans L3. Le CHECK contrôle ces quatre valeurs ; les valeurs nulles historiques de L2 restent tolérées, sans statut supplémentaire inventé.
- Doublon : même nom normalisé, organisation et hôte, dans le fichier ou dans l’événement. Ligne bloquée. La première occurrence du fichier reste importable si elle est valide. Homonyme : même nom mais organisation ou hôte différent ; avertissement non bloquant et second critère à demander à l’accueil. Comparaisons SQL avec `ikoma_normalize`, sans casse ni accents ; valeurs facultatives vides équivalentes à null.
- Hôtes inconnus refusés sauf option explicite « Créer les hôtes manquants », décochée par défaut. Les créations autorisées font partie de la transaction d’import. Plusieurs catégories ou hôtes portant le même nom normalisé bloquent la ligne : pas de choix arbitraire.
- Fichier privé supprimé après confirmation ou abandon ; purge automatique des aperçus de plus d’une heure. Seul le nom du fichier est conservé dans import_batches, jamais son chemin ni son contenu. Les détails nominatifs du rapport restent temporaires, seul le résumé chiffré est conservé.
- **À FAIRE AU LOT L4** : un seul badge actif par personne (contrainte en base) ; un seul service, en une transaction, pour garder statut du badge et historique d’association cohérents ; access_events et identity_checks en ajout seul (aucune modification ni suppression par le compte applicatif, correction par nouvel événement). Aucun de ces changements n’est anticipé dans L3.
- **À CONCEVOIR AVANT LE LOT L5** : authentification d’un agent hors ligne par tablette enrôlée et code PIN local ; recherche de visiteur sur n’importe quel mot du nom. Le service de recherche L2 est réutilisé sans modifier son algorithme.
- **À TRANCHER AVEC LE CLIENT** : badges réutilisables ou à usage unique ; contrôle des stagiaires (pièce d’identité ou confirmation de l’encadreur).
- **À TRANCHER PLUS TARD** : permission qui gouverne les événements (proposition : l’ajouter à l’administrateur). Aucun droit nouveau dans L3.

## L3 — Choix techniques de l’import

- Dépendance expressément autorisée : **OpenSpout 5.12.2**, version stable vérifiée le 27 octobre 2026 sur [Packagist](https://packagist.org/packages/openspout/openspout) et verrouillée dans composer.lock. PHP supporté par cette version : 8.4–8.6 ; le conteneur existant PHP 8.5.12 convient. Aucune autre dépendance majeure. XLSX lu en flux ; CSV UTF-8 lu en flux par fgetcsv (virgule ou point-virgule détecté). Première feuille XLSX uniquement, annoncée dans l’aide. Dates strictes ISO : AAAA-MM-JJ, avec HH:MM ou HH:MM:SS ; ISO avec fuseau accepté. Sans fuseau : UTC. Sans valeur : borne correspondante de l’événement.
- Formats vérifiés par contenu/MIME, structure ZIP/XLSX, UTF-8, limites 5 Mo/5 000 lignes. Décompression plafonnée à 50 Mo et 2 000 entrées ; refus macros, objets embarqués, liens externes, DTD et entités. Aucune évaluation de formule ni appel réseau. Les préfixes `= + - @` sont neutralisés par apostrophe après nettoyage, y compris dans les valeurs facultatives. Les champs supplémentaires du tableur sont ignorés ; seuls les champs du modèle sont importés.
- Aperçu privé dans le disque local, dossier imports ; fichier de travail privé et manifeste chiffré avec APP_KEY, permissions 0700/0600. Aucune route de téléchargement d’aperçu ; service local de fichiers désactivé. L’identité de l’acteur est contrôlée à chaque lecture, confirmation et abandon ; le jeton d’aperçu empêche les confirmations périmées. Aucun visiteur, hôte ni import_batches écrit avant confirmation. Un rechargement refusé laisse l’ancien aperçu intact.
- Verrou transactionnel PostgreSQL par événement (`pg_advisory_xact_lock(hashtextextended(event_id,3))`) et bail privé d’une heure : un seul aperçu actif par événement. Un même administrateur peut remplacer son fichier ; un autre doit attendre confirmation, abandon ou expiration. Confirmation revérifiée contre l’état courant, transaction unique (hôtes éventuels, import_batches, visiteurs, audit), puis suppression après COMMIT. Rejouer une confirmation réussie ne duplique rien. Le stockage partagé Docker contient les baux ; pour plusieurs serveurs, il faudra conserver ce volume partagé privé.
- Les trois actions d’audit contiennent uniquement UUID d’aperçu/import, événement et nombres. Les exceptions techniques de l’import sont converties en message neutre sans recopier les valeurs SQL ni les noms dans les logs. Les autorisations L1 et les droits d’audit SQL restent inchangés.
- Purge `imports:purge` chaque minute par le service Docker scheduler, via Laravel Scheduler ; même volume privé. L’ajustement des permissions de scripts/migrate.sh exclut ce dossier pour préserver sa confidentialité lors d’une migration. scripts/grant-db.php reste inchangé.
- Recette fictive 1 000 lignes : **985 importables, 15 bloquées** (10 doublons, 3 catégories inconnues, 2 dates invalides), **8 avertissements d’homonymie** (quatre paires). Le nombre d’avertissements compte les lignes importables concernées, pas les paires. Le fichier inclut accents et une formule neutralisée. Les tests mesurent réellement aperçu <10 s et confirmation <30 s.

- Confidentialité des erreurs PostgreSQL : verbosité serveur `terse` et paramètres d’erreur désactivés pour éviter de recopier une ligne nominative dans les logs techniques. Les messages applicatifs d’échec d’import restent neutres.

- Le rapport regroupe les fiches homonymes une seule fois, avec renvoi depuis chaque ligne concernée : pas de duplication quadratique des références pour un fichier de 5 000 homonymes. Toutes les fiches concernées restent visibles avec organisation/hôte ou mention d’absence.

## Décisions du 27 octobre 2026 (lot L3b)

Statut : validées par le dirigeant le 27 octobre 2026 ; les points marqués « à confirmer » le sont avec le client.

- Lots de badges constitués par catégorie (hypothèse de travail, à confirmer avec le client).
- Badge à usage unique par défaut (réglage `badge_reutilisable` = non, modifiable) ; règle définitive à confirmer avec le client.
- Contrôle des stagiaires par confirmation de l’encadreur par défaut (réglage `controle_stagiaires` = encadreur, modifiable) ; à confirmer avec le client, qui peut exiger la pièce d’identité.
- Droits dédiés `groupes.gerer`, `postes.gerer`, `evenements.gerer`, administrateur uniquement, en remplacement du rattachement provisoire à `badges.gerer`. Aucun écran créé. Les photos ne sont consultables par aucun rôle.
- Mise en service : après déploiement, relancer le seeder des rôles (`RoleSeeder`) pour synchroniser les permissions sur une base existante.
