# Authentification et données hors ligne du poste d'accueil — conception

Statut : **projet de travail de l'architecte, sans code**, livré avec le lot L5. À valider par le dirigeant avant le lot L6 ; rien n'y est décidé tant qu'il ne l'a pas déclaré validé. Références : plan de conception sections 4.1, 4.2 et 9 ; document de fonctionnement v1.0 sections 6, 7, 11 et 12 ; `docs/JOURNAL_DECISIONS.md`.

## 1. Situation à la fin du lot L5

- Le poste d'accueil est une page Inertia unique ; toutes les données passent par l'interface `PosteRepository` (appels JSON au serveur).
- L'agent se connecte avec son compte (mot de passe), session serveur à expiration d'inactivité réglable.
- **Le poste (tablette) est choisi par déclaration** de l'agent (en-tête `X-Poste-Id`) : aucune tablette n'est encore enrôlée ni authentifiée. C'est acceptable en ligne avec un agent connecté, ce ne l'est plus dès que des données restent sur la tablette. Le lot L6 remplace cette déclaration par le jeton d'appareil décrit ci-dessous.
- Chaque écriture porte déjà un identifiant d'opération (`X-Operation-Id`, UUID créé par la tablette) traité de façon idempotente (table `sync_operations`).

## 2. Objectifs et limites

Objectif : qu'un agent habilité continue d'accueillir des visiteurs sans internet pendant l'événement, sans qu'une tablette perdue, volée ou clonée donne accès aux données personnelles, et sans qu'une reprise de connexion perde ou duplique une opération.

Limites assumées : une tablette déverrouillée, PIN connu et en main d'un agent malveillant donne accès à ce que cet agent peut faire hors ligne (liste de l'événement, photos en attente). On réduit ce risque (données minimales, durée limitée, effacement, traçabilité) mais on ne peut pas l'annuler. La sécurité du système d'exploitation de la tablette (verrouillage, chiffrement du stockage, mode dédié, effacement à distance par la gestion d'appareils du client) reste une condition, à vérifier avec le client.

## 3. Enrôlement d'une tablette

1. L'administrateur (permission `postes.gerer`) crée ou ouvre un poste (table `devices`) et demande un **code d'enrôlement** : 12 caractères alphanumériques sans ambiguïté (60 bits d'entropie), **à usage unique**, valable **15 minutes**, affiché une seule fois (jamais stocké en clair : seul son hachage SHA-256 l'est, avec la date limite).
2. Sur la tablette (connectée, agent identifié), l'agent saisit le code. Le serveur vérifie hachage, validité, usage unique et limite de **5 essais par code et par heure** (verrouillage et journalisation au-delà).
3. Le serveur répond avec un **jeton d'appareil** : 32 octets aléatoires cryptographiques. Il stocke uniquement son hachage SHA-256 dans `devices.token_hash` (colonne existante) ; la tablette le garde chiffré (section 5). Le code d'enrôlement est détruit.
4. Toutes les requêtes du poste portent ensuite le jeton d'appareil en plus de la session de l'agent ; une tablette non enrôlée ne peut ni lire la liste ni synchroniser (plan, section 9).
5. **Révocation** : l'administrateur révoque le poste (le hachage est supprimé). Effets : refus immédiat de toute synchronisation et de tout téléchargement ; effacement local dès que la tablette parle au serveur (réponse « appareil révoqué ») ; journal d'audit (identifiant du poste et de l'administrateur). Un poste révoqué peut être réenrôlé avec un nouveau code, jamais avec l'ancien jeton.
6. Une tablette de secours (un appareil par poste, document de fonctionnement section 3) s'enrôle sur le même poste logique avec son propre jeton : la table devra distinguer le poste (lot de badges réservé) de l'appareil physique. À arbitrer au lot L6.

## 4. Code PIN local de l'agent

- Premier usage : l'agent, connecté en ligne avec son mot de passe, choisit un **PIN de 6 chiffres** sur cette tablette. Le PIN n'est jamais envoyé au serveur ni stocké : il sert uniquement à dériver la clé locale (section 5). Refus des PIN triviaux (suite, répétition, date de naissance connue non vérifiable : liste de 100 PIN interdits).
- Déverrouillage hors ligne : PIN seul. En ligne : le mot de passe reste exigé après expiration de session ; le PIN ne le remplace pas côté serveur.
- **Essais** : 5 essais, puis blocage de 1 minute ; 10 essais cumulés, blocage de 5 minutes ; **15 échecs cumulés depuis le dernier succès : effacement local complet** (section 6). Le compteur est conservé hors du stockage chiffré mais protégé contre la remise à zéro simple (valeur signée par la clé d'appareil).
- **Verrouillage** après **5 minutes d'inactivité** (valeur proposée, réglable par l'administrateur dans l'écran Administration) et à chaque mise en veille. Le verrouillage chiffre les données en mémoire (la clé de données quitte la mémoire).
- Un PIN par agent et par tablette ; deux agents sur la même tablette : chacun son PIN, son espace de clé, sa file d'opérations attribuée à son compte.
- Oubli du PIN : l'agent se reconnecte en ligne (mot de passe) et en définit un nouveau ; si la tablette est hors ligne, il appelle le superviseur (la file non synchronisée du PIN oublié est perdue : motif de ne pas rester hors ligne sans nécessité).

## 5. Chiffrement des données conservées sur la tablette

| Élément | Proposition |
|---|---|
| Algorithme | AES-256-GCM (WebCrypto), nonce unique de 96 bits par enregistrement, données associées : identifiant de l'enregistrement, poste, événement |
| Clé de données (DEK) | 256 bits aléatoires, créée à l'enrôlement, jamais stockée en clair |
| Clé d'enveloppe (KEK) | PBKDF2-HMAC-SHA256, 600 000 itérations, sel de 16 octets, appliquée au PIN ; puis HKDF-SHA256 avec le **secret d'appareil** pour obtenir la KEK finale |
| Secret d'appareil | clé WebCrypto **non exportable** conservée dans IndexedDB, plus un secret de 32 octets remis par le serveur à l'enrôlement et lié au jeton : copier la base sans le jeton, ou le jeton sans la clé non exportable, ne suffit pas |
| Ce qui est stocké | liste du poste restreinte aux champs nécessaires (nom, organisation, personne reçue, catégorie, période, zones), lot de badges du poste, opérations en attente, photos en attente |
| Jetons QR | **jamais en clair sur la tablette** : seul le hachage SHA-256 du jeton est conservé avec le code lisible ; le scan calcule le hachage et compare |
| Photos | chiffrées dès la prise, conservées seulement jusqu'à l'acquittement de la synchronisation, puis supprimées |
| Hors du chiffrement | état d'interface sans donnée personnelle (poste choisi, compteur d'essais signé, version) |

Remarques : Argon2id n'existe pas dans WebCrypto ; PBKDF2 avec itérations élevées est retenu faute de dépendance. Un module WASM Argon2id serait une dépendance supplémentaire à valider séparément. Le service worker ne met jamais en cache de réponse authentifiée (règle L0 maintenue) : les données hors ligne vivent dans IndexedDB chiffré, jamais dans le cache HTTP. Une politique de sécurité du contenu stricte (lot L11) limite le risque de vol de clé par script injecté.

## 6. Effacement local

L'effacement supprime IndexedDB, caches, clé d'appareil et compteur ; il ne supprime rien sur le serveur.

- **Trop d'échecs** de PIN (15 cumulés).
- **Révocation** du poste ou de l'agent, dès le premier échange avec le serveur.
- **Fin d'événement** : à la date de fin de l'événement plus un délai de grâce de 24 heures, effacement au prochain démarrage de l'application, même hors ligne ; une opération non synchronisée à cette échéance est exportée chiffrée vers le superviseur avant effacement si une connexion existe, sinon effacée avec journalisation à la reconnexion suivante (perte à signaler au dirigeant : voir décisions).
- **Après synchronisation complète** : purge des opérations et photos acquittées ; la liste reste jusqu'à la fin de l'événement.
- **Manuel** : bouton « Effacer cette tablette » réservé au superviseur (code d'accès propre), pour une tablette qu'on rend ou qu'on remplace.
- Effacement à distance du système (gestion d'appareils du client) : complémentaire, hors périmètre de l'application.

## 7. Ce que l'agent peut et ne peut pas faire hors ligne

| Peut | Ne peut pas |
|---|---|
| Rechercher dans la liste du poste (accents, fautes, n'importe quel mot) | Voir un autre événement ou une liste qui n'est pas téléchargée |
| Afficher la fiche et les zones autorisées | Modifier une fiche existante ; consulter une photo |
| Contrôler l'identité, prendre la photo (file chiffrée) | Voir le journal, les comptes, les alertes du superviseur |
| Valider ou refuser avec motif ; indiquer l'avis pour un visiteur hors période | Annuler un badge (superviseur, en ligne) |
| Associer un badge **du lot réservé à ce poste** et enregistrer l'entrée | Associer un badge hors du lot réservé (règle anti-conflit, plan 4.2) |
| Créer la fiche rapide d'un visiteur non listé | Obtenir l'accord de l'hôte (en ligne seulement) : validation et entrée restent impossibles tant que l'hôte n'a pas accepté |
| Voir le nombre d'opérations en attente | Restituer ou clore un badge, créer un compte, changer de poste |

Toute opération hors ligne est marquée « faite hors ligne » avec l'heure de la tablette. À la synchronisation, le serveur rejoue chaque opération avec les mêmes règles qu'en ligne ; une opération qui ne passe plus (badge annulé entre-temps, visiteur refusé, hôte refusé) n'est jamais écrasée : elle devient une **alerte au superviseur** à traiter (plan 4.2).

## 8. Horloges et ordre des opérations

- Chaque opération enregistre : l'identifiant d'opération, un **numéro de séquence local croissant** par appareil, l'heure de la tablette (UTC) et, à la réception, l'heure du serveur (`received_at`, déjà présente dans `sync_operations`).
- Le rejeu suit le numéro de séquence (pas l'heure) pour garder l'ordre réel des actions d'un appareil.
- À chaque contact serveur, la tablette calcule son **décalage d'horloge** (heure serveur moins heure tablette, corrigée du temps de réponse) et l'enregistre. Le serveur conserve les deux heures ; les rapports (durée de parcours, heure d'entrée) utilisent l'heure corrigée.
- Décalage supérieur à **5 minutes** : l'application l'affiche dans le bandeau et le serveur crée une alerte « horloge suspecte » ; une heure de tablette antérieure à la dernière heure serveur connue ou postérieure de plus de 5 minutes à la réception est signalée, jamais corrigée en silence.
- Les périodes de validité sont contrôlées avec l'heure corrigée ; hors ligne prolongé, avec l'heure de la tablette et mention « à revérifier ».

## 9. Menaces considérées

| Menace | Réponses proposées |
|---|---|
| Tablette volée ou perdue | Stockage chiffré par PIN et secret d'appareil ; blocage progressif puis effacement après 15 échecs ; révocation par l'administrateur ; effacement à distance par la gestion d'appareils du client ; verrouillage après inactivité |
| Agent révoqué ou parti | Désactivation du compte (déjà en L1) ; révocation du poste ; effacement local à la première connexion ; opérations postérieures à la révocation refusées et signalées |
| Rejeu d'opérations (réseau ou copie de la file) | Identifiants d'opération uniques, traitement idempotent, hachage de la charge, lien avec l'utilisateur et le poste (L5) ; refus d'un identifiant réutilisé avec une autre charge |
| Tablette clonée ou jeton copié | Jeton d'appareil lié à une clé non exportable ; détection de deux appareils actifs avec le même jeton (séquences incohérentes) → alerte et révocation |
| Force brute du PIN | 6 chiffres seulement : la protection repose sur le secret d'appareil (clé non exportable), les délais et l'effacement, pas sur l'entropie du PIN |
| Manipulation d'horloge | Séquence locale, comparaison avec l'heure serveur, alerte au-delà de 5 minutes |
| Script injecté (XSS) | Politique de sécurité du contenu (L11), aucune ressource externe, composants sans HTML brut, clé non exportable |
| Fuite de photos | Chiffrement à la prise, suppression à l'acquittement, aucune consultation de photo prévue (aucune permission ne la couvre) |
| Perte de données non synchronisées | Compteur visible, rappel au superviseur avant la fin de l'événement, export chiffré avant effacement de fin d'événement |

## 10. Impact sur le code des lots suivants

- L6 : implémentation locale de `PosteRepository` (IndexedDB chiffré), file d'opérations, synchronisation, enrôlement par code, jeton d'appareil, PIN, effacement. Aucun écran du lot L5 n'est modifié.
- Tables à prévoir : code d'enrôlement (hachage, expiration, essais), séquence et heure tablette dans `sync_operations`, alerte de conflit ; à cadrer par un prompt L6 validé.
- L11 : politique de sécurité du contenu, revue de ce document avec l'audit de sécurité, avis du juriste local sur les données conservées sur la tablette.

## 11. Décisions que le dirigeant doit valider

1. Principe de l'enrôlement par code à usage unique de 15 minutes et jeton d'appareil haché (section 3). Recommandation : oui.
2. PIN de 6 chiffres, 5 essais puis blocages progressifs, **effacement après 15 échecs** (section 4). Recommandation : oui ; l'effacement est irréversible pour la file non synchronisée.
3. Verrouillage après 5 minutes d'inactivité (réglable dans l'administration). Recommandation : 5 minutes.
4. Algorithmes (AES-256-GCM, PBKDF2 600 000 itérations, secret d'appareil non exportable) sans nouvelle dépendance. Recommandation : oui ; Argon2id en WASM seulement si le client l'exige.
5. Jetons QR jamais en clair sur la tablette (hachage seul). Recommandation : oui.
6. Liste de ce qui est permis hors ligne (section 7), en particulier : fiche rapide d'un non listé permise mais sans entrée avant l'accord de l'hôte. Recommandation : oui.
7. Effacement à la fin de l'événement plus 24 heures, et traitement des opérations non synchronisées à cette échéance (export chiffré puis effacement). Recommandation : oui.
8. Tolérance d'horloge de 5 minutes avant alerte. Recommandation : oui.
9. Bouton « Effacer cette tablette » réservé au superviseur. Recommandation : oui.
10. Distinction poste logique / appareil physique pour la tablette de secours (section 3, point 6). Recommandation : à trancher avec le prompt L6.
11. Obligations du client sur les tablettes (verrouillage système, chiffrement du stockage, mode dédié, effacement à distance) à inscrire dans le cahier d'exploitation. Recommandation : oui.
