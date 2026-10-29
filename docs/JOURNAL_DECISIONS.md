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
- **FAIT AU LOT L4** (voir la rubrique L4 ci-dessous) : un seul badge actif par personne (contrainte en base) ; un seul service, en une transaction, pour garder statut du badge et historique d’association cohérents ; access_events et identity_checks en ajout seul (aucune modification ni suppression par le compte applicatif, correction par nouvel événement). Aucun de ces changements n’est anticipé dans L3.
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

## L4 — vie complète d’un badge (28 octobre 2026)

Statut : projet de travail, en attente de validation du dirigeant. Les points « à confirmer » le sont avec le client.

- **QR** : bacon/bacon-qr-code **3.1.1**, version stable vérifiée (publiée le 5 avril 2026, la plus récente), déjà verrouillée comme dépendance de Fortify ; elle est désormais déclarée directement dans composer.json. Seul l’encodeur est utilisé ; le dessin SVG (une seule forme, sans xmlns ni ressource externe) est écrit par `App\Services\QrSvg`, pour que le fichier soit compact et vérifiable. Correction d’erreur niveau Q. Contenu du QR : `IKOMA1:` + jeton de 32 octets aléatoires en hexadécimal (64 caractères), aucune donnée personnelle.
- **Décodage** : un décodeur PHP essayé (khanamiryan/qrcode-detector-decoder) échouait sur environ 13 % de QR parfaits, alors que jsQR en décode 300 sur 300 ; il n’est donc pas retenu. Dépendance de test : **jsqr 1.4.0** (npm, dev). Le parcours Playwright décode dans le navigateur les 22 QR rendus et les fait reconnaître par la route de démonstration. Pest vérifie que le SVG reproduit exactement la matrice de l’encodeur.
- **Hypothèse appliquée (à confirmer avec le client)** : les lots sont imprimés par catégorie ; un badge ne s’associe qu’à un visiteur de sa catégorie. Les badges du lot hérité du seed n’ont pas de catégorie et sont refusés à l’association (« sans_categorie »).
- **Quantités** : `badge_batches.quantity` = badges fabriqués, `margin` = nombre de badges de marge (attendus = quantité − marge, cohérent avec le seed 400/100). Réglages dans `settings` : `badge_marge_pourcent` = 10 ; `badge_reutilisable` = non (usage unique, décision du dirigeant du 27 octobre 2026, règle définitive à trancher avec le client).
- **Codes** : le code lisible n’est plus unique globalement mais par événement (`unique(event_id, code)`), pour que chaque événement commence à B-001. Conséquence : le contrôle par code exige l’événement dès que le code existe dans plusieurs événements (verdict « evenement_requis ») ; le contrôle par jeton reste global. Numérotation continue par événement sous verrou consultatif PostgreSQL ; l’unicité reste garantie en base.
- **Nouvelles colonnes** : `category_id` (lots et badges, nullable), `badges.reserved_group_id` (réservation par groupe, exclusive avec le poste), `badge_assignments.cancellation_reason` (motif d’annulation, texte libre conservé hors du journal), `access_events.corrige_id`.
- **Garde-fous en base** : index unique partiel `badge_assignments_one_active_visitor` ; déclencheur `badges_status_guard` créé par le compte de migration (le compte applicatif ne peut ni le désactiver ni le supprimer) n’autorisant que Disponible→Associé, Disponible→Annulé (décision du dirigeant, 28 octobre 2026), Associé→Restitué, Associé→Annulé, Restitué→Disponible, Restitué→Clos. Il lève une violation de contrainte (code 23514). Annulé et Clos sont définitifs. UPDATE, DELETE et TRUNCATE retirés au compte applicatif sur access_events et identity_checks (scripts/grant-db.php) ; audit_logs inchangé.
- **Service unique** `App\Services\BadgeService` : une transaction et un verrou de ligne par opération ; ordre de verrous constant (badge puis visiteur), journal d’audit écrit en dernier. Le service contrôle lui-même les permissions (`badges.associer`, `badges.annuler`). Refus indiqués par `BadgeRefused` avec un code stable. Vérifications ajoutées à la liste du prompt, tirées du document de fonctionnement section 7 : visiteur au statut `refuse` sans badge ; badge d’un autre événement ; poste d’un autre événement. Restitution : Associé→Restitué puis Disponible ou Clos dans la même transaction.
- **Contrôle** : ne modifie ni badge ni historique ; seul un refus écrit `badge.controle_refuse` (identifiants et raison, jamais le code saisi ni un jeton). Raisons : non_associe, annule, hors_periode, restitue, inconnu, evenement_requis.
- **Journal** : badge.lot_cree, badge.reserve, badge.associe, badge.restitue, badge.annule, badge.controle_refuse, plus `badge.reglage_modifie` (ajout hors liste du prompt, par cohérence avec session.duree_modifiee : un changement d’usage unique/réutilisable doit être tracé). Identifiants et nombres uniquement.
- **Étiquettes** : page HTML autonome (Blade, styles intégrés, aucune ressource externe, aucun script chargé), A4, grille 1 à 4 colonnes × 1 à 10 lignes (3×5 par défaut), plage par numéros, 1 000 étiquettes au plus par page. Catégorie en toutes lettres, couleur et symbole (★ VIP, ● Visiteur, ▲ Prestataire, ■ Stagiaire, autres selon l’ordre). Les jetons ne sont jamais en clair dans la page ; en-tête `no-store`. Le bouton Imprimer utilise un attribut `onclick` : à revoir avec la politique CSP du lot L11.
- **Route de démonstration** `POST /administration/badges/controle-demo` (badges.gerer) : retourne verdict, catégorie et zones, jamais l’identité. À supprimer en L5.
- **Test existant adapté** : « conserve le vocabulaire des statuts » (BusinessModelTest) faisait passer un badge par tous les statuts par UPDATE direct, ce que le déclencheur interdit désormais ; chaque statut est maintenant stocké sur un badge créé à ce statut. Toutes les autres assertions sont conservées (le code 23514 attendu pour une valeur inconnue l’est aussi).
- **Décisions du dirigeant, 28 octobre 2026** : (1) Disponible→Annulé autorisé : un badge perdu ou abîmé avant toute association peut être annulé par un superviseur, motif obligatoire stocké dans `badges.cancellation_reason` (aussi sur l’association pour un badge associé) ; (2) un badge réservé à un groupe n’impose pas l’appartenance au groupe ; (3) pas de levée de réservation ; (4) recommandations de l’architecte approuvées pour le reste : impression non journalisée, badges du seed sans catégorie refusés, écarts de la PR acceptés.

## L5 — poste d'accueil en ligne (28 octobre 2026)

Statut : projet de travail en attente de validation du dirigeant.

- **Lecture de QR** : `@zxing/browser` 0.2.1 avec `@zxing/library` 0.23.0 (versions stables vérifiées sur npm le 28 octobre 2026, JavaScript pur). `BarcodeDetector` est utilisé s'il existe ; sinon zxing, chargé à la demande. Raison du choix : zxing-wasm 3.1.6 télécharge par défaut son module WebAssembly depuis un CDN, contraire à « aucun appel réseau sortant » ; la version JavaScript n'a aucun fichier externe. Un test Playwright lit un vrai QR à travers un flux vidéo simulé et vérifie qu'aucune requête ne sort de l'origine.
- **Architecture** : page Inertia unique `Poste`, automate d'étapes pur (`resources/js/poste/machine.ts`), accès aux données uniquement par `PosteRepository` (implémentation HTTP seule), identifiants d'opération conservés jusqu'à la réponse définitive pour que « réessayer » après une coupure rejoue la même opération.
- **Poste déclaratif** : l'agent choisit son poste (mémorisé dans le navigateur) et la tablette l'envoie dans `X-Poste-Id` ; le serveur en déduit l'événement. Aucun enrôlement avant L6 (voir `docs/AUTH_HORS_LIGNE.md`). Le poste du seed est « Poste fictif A », dont 60 badges sont réservés.
- **Recherche** : tous les mots saisis doivent retrouver un mot du nom (préfixe, contenu ou similarité de mot `<%`, seuil 0,4 pour les mots de 3 lettres et plus ; préfixe seul en dessous), classement par qualité de correspondance puis VIP d'abord à qualité égale. Le « VIP en premier » du prompt est donc appliqué à qualité égale : un VIP approximatif ne passe pas devant une correspondance exacte (sinon le test existant « ko » aurait été affaibli). `search()` garde sa forme de L2 ; `searchDetailed()` ajoute organisation, personne reçue et nombre d'homonymes.
- **Photo** : réduite à 640 px et JPEG par la tablette, revérifiée par le serveur (JPEG réel, 640 px au plus, 1 Mo), chiffrée par `Crypt` avant écriture sur le disque privé. L'identité (case) et la photo sont enregistrées ensemble dans une seule opération ; `identity_checks` reste en ajout seul. Le serveur refuse la validation sans les deux.
- **Hors période** : l'avis (`hote` ou `superviseur`) est enregistré dans `access_events.details` ; l'association du badge lit cet avis côté serveur (jamais celui du client) et le transmet à `BadgeService::associer` (nouveau paramètre `avis`), qui le journalise. Sans avis, la règle L4 refuse comme avant.
- **Entrée** : `access_events` type `entree`, statut visiteur `present`, durée du parcours dans `details`. Le chrono court de la première frappe à l'entrée enregistrée ; il est affiché, comparé à 60 s, et envoyé au serveur.
- **Non listé** : fiche rapide (nom, organisation, personne à voir, catégorie « Visiteur » préremplie). Choix de la catégorie : ajout nécessaire car `visitors.category_id` est obligatoire et le badge doit être de la même catégorie. Validation, entrée et badge refusés tant que l'hôte n'a pas accepté (contrôle serveur).
- **Route de démonstration L4 supprimée** (`/administration/badges/controle-demo`) ; son test Pest est remplacé par un test qui constate qu'elle n'existe plus, et l'étape 5 du parcours Playwright des badges (qui l'appelait) est retirée. Le décodage des QR du parcours badges est conservé.
- **Compte non administrateur** : seul le rôle `agent_accueil` accède à `/poste` (middleware dédié), en plus des permissions de la matrice. Aucun droit ajouté ; l'administrateur n'a pas accès au poste.
- **Journal** : visiteur.identite_verifiee, visiteur.valide, visiteur.refuse, visiteur.entree, visiteur.non_liste_cree, visiteur.hote_decision ; identifiants seulement (ni nom, ni organisation, ni motif, ni chemin de photo).
- **Session expirée** : une requête JSON reçoit 401 `session_expiree` au lieu d'une redirection ; l'écran affiche « reconnectez-vous ».
- **Service worker** : aucune réponse authentifiée n'est mise en cache ; seuls les fichiers compilés (dont le chargeur QR) sont précachés, comme avant.
- **Questions ouvertes** : (1) aucune permission ne couvre la consultation des photos : aucune consultation n'existe ; (2) l'alerte au superviseur lors d'un refus ou d'un non listé arrive aux lots L8 et L10 ; (3) les consignes de la remise sont un texte générique, le client doit fournir les siennes ; (4) un non listé rejeté par l'hôte reste en base avec `host_validation = refuse` ; (5) le choix du poste n'est pas contrôlé avant l'enrôlement (L6) ; (6) les messages de refus rejoués dans `sync_operations` contiennent un nom pour « badge déjà associé » : à purger au lot L11.

## Décisions du dirigeant sur docs/AUTH_HORS_LIGNE.md (28 octobre 2026)

Statut : **validées par le dirigeant avec les recommandations de l'architecte**, sans modification : (1) enrôlement par code à usage unique de 15 minutes et jeton d'appareil haché ; (2) PIN de 6 chiffres, 5 essais puis blocages progressifs, effacement après 15 échecs ; (3) verrouillage après 5 minutes d'inactivité, réglable dans l'administration ; (4) AES-256-GCM, PBKDF2 600 000 itérations, secret d'appareil non exportable, sans nouvelle dépendance cryptographique ; (5) jetons QR jamais en clair sur la tablette (hachage seul) ; (6) liste des actions permises hors ligne de la section 7 ; (7) effacement à la fin de l'événement plus 24 heures, avec export chiffré des opérations non synchronisées lorsqu'une connexion existe ; (8) tolérance d'horloge de 5 minutes ; (9) bouton « Effacer cette tablette » réservé au superviseur ; (10) distinction poste logique / appareil physique à trancher dans le lot L6 ; (11) obligations du client sur les tablettes inscrites au cahier d'exploitation.

Dépôt du site de démonstration : https://ikoma.dgafrique.com (serveur Contabo déjà doté d'autres sites : le script s'intègre à nginx ou Apache en place).

## L6 — mode hors ligne et synchronisation (28 octobre 2026)

Statut : projet de travail en attente de validation du dirigeant. Le document `docs/AUTH_HORS_LIGNE.md` (validé) est suivi ; les écarts sont listés dans sa section 12.

- **Dépendances** : `dexie` 4.4.7 (IndexedDB, version stable vérifiée sur npm le 28 octobre 2026) ; `fake-indexeddb` 6.2.6 **uniquement en développement**, pour les tests Vitest. **Fuse.js n'est pas ajouté** : le prompt L6 exige des résultats identiques à ceux du serveur (`VisitorSearch`, trigrammes PostgreSQL) ; une bibliothèque de recherche floue ne donnerait ni le même classement ni les mêmes seuils. La recherche locale (`resources/js/poste/local/search.ts`) reprend donc la règle du serveur : accents ignorés, chaque mot saisi doit correspondre à un mot du nom, seuil de similarité 0,4 (trigrammes à la manière de `pg_trgm`), classement par dixième de qualité puis VIP puis score. Aucune autre dépendance : le chiffrement utilise WebCrypto.
- **Authentification de la tablette** : le jeton d'appareil (64 caractères hexadécimaux) est conservé haché (SHA-256) dans `devices.token_hash` ; l'en-tête `X-Device-Token` remplace `X-Poste-Id`. Le jeton n'apparaît ni dans les journaux ni dans une réponse après l'enrôlement ; côté tablette il est scellé par la clé de données. Le code d'enrôlement (12 caractères sans ambiguïté, affichés par groupes de 4) est à usage unique, valable 15 minutes, haché ; 5 essais par heure et par agent.
- **Chiffrement local** : PBKDF2-SHA-256 600 000 itérations (PIN + sel) → HKDF avec le secret d'appareil renvoyé par le serveur → clé d'enveloppe AES-GCM qui protège la clé de données (AES-256-GCM, non exportable). Une clé d'appareil non exportable scelle le secret d'appareil et le compteur d'échecs. Chaque bloc porte un contexte authentifié (identifiant du bloc) : un bloc ne peut pas être déplacé vers un autre emplacement. Le chiffrement est toujours fait **avant** d'ouvrir une transaction IndexedDB (une transaction qui attend autre chose que ses requêtes se referme).
- **PIN** : 6 chiffres, suites, répétitions et motifs alternés refusés ; 5 échecs → 1 minute, 10 échecs → 5 minutes, 15 échecs → effacement (valeur lue dans `settings.poste_echecs_pin_max`). Un compteur supprimé ou altéré provoque l'effacement (pas d'essais gagnés en le supprimant).
- **Verrouillage** : après `poste_inactivite_minutes` (5 par défaut) sans toucher l'écran, ou au bouton « Verrouiller » ; la clé de données quitte la mémoire, rien n'est lisible.
- **Liste locale** : le serveur envoie la liste de l'événement (`ListSnapshot`), jamais de photo ni de jeton QR en clair : seuls le **hachage SHA-256** du jeton et le code imprimé des badges **réservés au poste** sont copiés. Une lecture de QR est résolue localement (hachage → code imprimé) ; **seul le code imprimé** part dans la file et au serveur, jamais le contenu du QR. La copie est découpée en blocs de 500 fiches, chacun scellé.
- **File d'opérations** : chaque écriture est contrôlée par les règles du poste appliquées à la copie locale (mêmes messages que le serveur), puis écrite dans IndexedDB (chiffrée, avec un numéro de séquence et l'heure de la tablette) **avant** tout envoi. Rejouer le même identifiant d'opération ne crée rien de plus. Les photos en attente sont scellées à part et supprimées dès l'envoi.
- **Envoi** : lots de 50 opérations dans l'ordre de séquence ; un seul envoi à la fois, une demande arrivée pendant un envoi en provoque un second juste après ; reprise avec délai croissant (4 s à 60 s) après une coupure. Une réponse perdue après application côté serveur est sans effet au renvoi (idempotence `sync_operations`, hachage de la charge). Session expirée : la file est conservée et le bandeau propose de se reconnecter.
- **Conflits** : `sync_conflicts` (opération, poste, agent, visiteur, raison, détails en identifiants) ; raisons : agent désactivé ou inconnu, droit manquant, fiche modifiée entre-temps par un autre poste, visiteur déjà entré, badge déjà associé / de catégorie différente / annulé, accord de l'hôte manquant, opération invalide, identifiant d'opération réutilisé avec une autre charge, horloge suspecte. Jamais d'écrasement : l'opération refusée est mise en quarantaine, journalisée (`sync.conflit`) et affichée à l'agent (« Compris » la retire de l'écran, pas de la base). Le code d'un badge n'est conservé dans les détails que s'il a la forme d'un code imprimé (`B-123`). L'écran du superviseur arrive au lot L8 ; en attendant, Administration > Postes liste les conflits ouverts et les compteurs reçues / appliquées / en conflit par poste.
- **Heures** : l'heure de la tablette est envoyée avec chaque lot (`sent_at`) et avec chaque opération (`client_time`) ; le serveur calcule l'écart, le journalise (`sync.recue`) et, au-delà de `poste_ecart_horloge_secondes` (300 s), crée un conflit de type `horloge`. La tablette corrige ses calculs de période de validité avec l'écart mesuré à la dernière synchronisation.
- **Service worker** : `/poste/shell` (page publique sans session ni nom) est précachée avec les fichiers compilés ; `/poste` passe toujours par le serveur (session, droits) et ne retombe sur la coquille précachée que si le réseau est coupé. Aucune réponse authentifiée n'est mise en cache. La mise à jour de l'application (`autoUpdate`) ne touche pas à IndexedDB.
- **Effacement** : après 15 échecs de PIN, à la révocation par le superviseur ou l'administrateur (détectée à la prochaine synchronisation ; **aucun bouton d'effacement pour l'agent**, décision du dirigeant du 28 octobre 2026), et 24 h après la fin de l'événement **si plus rien n'est à envoyer** (sinon la tablette reste utilisable pour envoyer, l'effacement a lieu ensuite). La tablette déclare l'effacement au serveur (`POST /poste/api/efface`, `poste.efface`) quand elle a encore le réseau et son jeton.
- **Cas laissés ouverts** : voir la section 12 de `docs/AUTH_HORS_LIGNE.md`.

## L7 — contrôle sur site, sortie et compteur de présents (28 octobre 2026)

Statut : projet de travail en attente de validation du dirigeant. Réponses du dirigeant aux questions ouvertes de L6 (28 octobre 2026) : effacement manuel réservé au superviseur (appliqué en L6), **export chiffré avant l'effacement de fin d'événement au lot L12**, **détection de tablette clonée au lot L11**.

- **Une seule application, un seul enrôlement** : l'écran « Contrôle » (`/controle`) est une page Inertia autonome qui réutilise l'enrôlement, le PIN, le coffre chiffré et la synchronisation du poste (L6). Une tablette ou un smartphone d'agent doit donc être enrôlé comme un poste (Administration > Postes) ; le même appareil peut faire l'accueil et le contrôle avec un seul PIN. Réservé au rôle `agent_accueil` (permission `badges.controler`), comme `/poste`.
- **Le serveur répond quand il peut, la tablette quand il ne peut pas** : en ligne, contrôle et sortie interrogent le serveur (délai de 4 s) : une copie locale vieille de quelques minutes ne doit pas déclarer valide un badge annulé à l'instant. La file en attente est d'abord envoyée (2,5 s au plus) pour que le serveur connaisse les entrées faites sur cette tablette. Sans réseau (ou si le serveur ne répond pas à temps), la tablette juge sur sa copie, **affiche « situation connue à HH:MM »** et met l'opération dans la file ; le serveur la rejuge au rejeu avec l'heure déclarée. Seul le code imprimé est mis dans la file (jamais le contenu du QR).
- **Copie élargie** : pour contrôler hors ligne, la tablette reçoit désormais **tous les badges de l'événement** (code, hachage SHA-256 du jeton, catégorie, statut), pas seulement ses badges réservés ; `mine` limite l'association de badges hors ligne à ses badges réservés. Les hachages seuls ne permettent pas de fabriquer un badge. Test L6 `SyncTest` adapté en conséquence (la règle « seulement les badges réservés » n'est plus vraie par décision du lot L7, la règle « jamais de jeton en clair » est conservée).
- **Annulation propagée** : une annulation de badge change le numéro de version de la copie ; la tablette la recopie à la prochaine synchronisation (retour du réseau, bouton, ou toutes les 60 s en ligne). La version ignore les événements `controle` pour ne pas recopier toute la liste à chaque scan.
- **Verdicts** : repris de `BadgeService::controler` (inconnu, annulé, restitué, non associé, hors période) sans le contourner. Ajouts : « autre événement » et « zone non autorisée » (le badge est valide, la zone du point de contrôle n'est pas dans les zones du porteur : verdict VALIDE accompagné d'un bandeau « ZONE NON AUTORISÉE », événement et entrée de journal créés). Un refus ne désigne pas de visiteur dans l'événement (le verdict de L4 ne l'expose pas).
- **Journal** : `controle.refuse` (identifiants, raison, point) remplace pour ces appels l'entrée `badge.controle_refuse` de L4 : `BadgeService::controler` reçoit un paramètre `$journaliser` (vrai par défaut, les appels existants sont inchangés) pour éviter les doublons. `sortie.enregistree`, `sortie.refusee`, `presence.corrigee`. Aucun nom de personne dans les journaux (testé sur le journal entier).
- **Sortie** : réservée aux personnes dont la dernière entrée est enregistrée ; une sortie sur badge sans entrée est refusée (`sortie_sans_entree`), une deuxième aussi (`deja_sorti`) ; le badge est restitué selon `badge_reutilisable`. Hors ligne, une personne « attendue » selon la copie est enregistrée avec un avertissement (l'entrée a pu être faite sur une autre tablette) et le serveur tranche (conflit `sortie_sans_entree` si aucune entrée). Une sortie ne dépend pas de la période de validité (on peut toujours sortir).
- **Corrections** : `Presence::corriger` crée un événement `correction` (jamais de modification), recalcule le statut du visiteur et ne touche pas au badge. Il n'y a **ni écran ni droit** pour l'appeler à ce lot (question ouverte : lot L8).
- **Compteur** : calculé par requête sur les événements (pas de compteur stocké), exact sur un scénario de 200 mouvements avec corrections (test Pest). Exposé à l'agent (`/poste/api/presents`, affiché en petit en ligne, « — » hors ligne) et au superviseur (`/api/presences`, affichage au lot L8).
- **Rejeu et conflits** : `controle` et `sortie` sont des opérations de la file (idempotentes). Conflits mis en quarantaine : sortie sans entrée, double sortie, badge annulé entre-temps (un contrôle jugé valide hors ligne que le serveur refuse au rejeu est signalé `annule_entre_temps`, l'événement du contrôle reste enregistré avec les deux verdicts).
- **Un résultat ne se rejoue pas tout seul** : la même lecture de QR n'est pas refaite pendant 5 s après la fermeture du résultat (le badge est encore devant la caméra) ; une saisie manuelle n'est jamais bloquée.
- **Service worker** : `/controle/shell` (page publique, sans donnée) est précachée ; `/controle` passe par le serveur et retombe sur la coquille sans réseau. Aucun contenu authentifié en cache.
- **Questions ouvertes** : (1) qui peut corriger une entrée ou une sortie et par quel écran (L8) ; (2) corriger une sortie ne réassocie pas le badge ; (3) un refus de contrôle sur badge connu n'enregistre pas le visiteur concerné (limite du verdict L4) ; (4) deux contrôles très rapprochés d'un même badge hors ligne sont tous deux enregistrés (pas de limitation de fréquence).
- **Lecture du QR (correction)** : la boucle interne de `@zxing/browser` (`decodeFromStream`) pouvait ne jamais lire un QR bien visible (attente d'un événement vidéo déjà passé) : blocage intermittent constaté en recette (2 parcours complets sur 6). `media.ts` lit désormais l'image de la caméra toutes les 150 ms et la décode (`decodeFromCanvas`), `BarcodeDetector` restant prioritaire quand le navigateur le propose. 4 parcours complets de suite sans échec après correction.

## L8 — pilotage du superviseur, alertes et liste d'évacuation (28 octobre 2026)

Statut : projet de travail en attente de validation du dirigeant.

- **Aucune nouvelle dépendance** ni technologie temps réel. Mise à jour du tableau de bord par **rechargements partiels Inertia** toutes les 3 s (propriétés `counters`, `alerts`, `openAlerts` seulement, calculées à la demande), arrêtés quand l'onglet est caché et repris, avec un rechargement immédiat, au retour. Mesuré en local : une entrée ou un refus fait à l'accueil apparaît sur le smartphone en moins de 5 s après la synchronisation de la tablette (test Playwright à deux contextes).
- **Alertes** : une alerte ne porte jamais de nom (type, gravité, objet par identifiant, phrase courte) ; le nom n'apparaît que sur la fiche, à la consultation. Doublons évités par `dedupe_key`. Trois niveaux de gravité : `critique` (aucune source ne l'utilise encore), `haute` (refus, badge annulé d'une personne, conflit de synchronisation), `normale` (non listé, hors période, badge disponible annulé, écart d'horloge). État « à traiter » / « prise en compte » ; « Prendre en compte » est journalisé une seule fois (un second appui est sans effet) ; les alertes dont la cause disparaît sont « réglées » sans intervention. Les types `car_arrive` et `groupe_incomplet` existent pour le lot L9 mais rien ne les crée à ce lot.
- **Droit « prendre en compte »** : aucune permission n'est dédiée ; `alertes.consulter` (superviseur seulement) la couvre. Aucun droit ajouté.
- **Annulation de badge** : motif obligatoire (côté serveur et côté écran), confirmation en deux temps (la requête sans `confirmation` est refusée), par la fiche d'un présent ou par code (+ événement si plusieurs). `BadgeService::annuler` n'est pas contourné. Le superviseur n'associe rien : la personne est « **à rebadger** » (fiche du superviseur, fiche du poste d'accueil et copie de la tablette) et une alerte `badge_annule` la signale ; l'alerte se règle quand l'agent associe un nouveau badge.
- **Litiges** : décision `maintenu` / `autorise` / `a_revoir` dans `refusals` ; « autoriser » exige des conditions, repasse la personne à « attendu » et pose la mention sur la fiche (`visitors.access_note`) ; `maintenu` et `autorise` règlent l'alerte de refus, `a_revoir` la laisse ouverte. Journal `litige.decide` sans motif ni conditions (identifiants et décision seulement).
- **Demande d'avis « hors période »** : il n'existe pas de demande envoyée au superviseur. L'agent enregistre l'avis obtenu de vive voix (L5) ; le superviseur en est informé par une alerte `hors_periode` (normale) et peut consulter la fiche. Un vrai circuit de demande/réponse n'est pas réalisé à ce lot (question ouverte).
- **Groupes et cars** : lecture seule, structure prête pour le lot L9 (total, badges remis, embarqués, arrivés, absents), affichée même vide.
- **Journal** : l'écran existant (`/administration/journal`, permission `journal.consulter`) est utilisé tel quel ; vérifié à 380 px sans défilement horizontal. Lien depuis le tableau de bord.
- **Évacuation** : présents regroupés par zone avec totaux par zone, imprimables (feuille de style `@media print`, une page A4 par zone, navigation et boutons masqués). Chaque consultation est journalisée. Copie conservée **chiffrée sur l'appareil du superviseur** (IndexedDB `ikoma-pilotage`, même coffre que le poste : PBKDF2 600 000 + AES-256-GCM, clé d'appareil non exportable, PIN de 6 chiffres choisi par le superviseur à la première conservation, 15 échecs = effacement, verrouillage après 5 min d'inactivité) ; effacée à la déconnexion explicite (bouton « Se déconnecter » de l'espace), 24 h après la fin de l'événement. Hors ligne, la coquille publique `/pilotage/evacuation/shell` est servie par le cache de l'application ; la liste est lue après saisie du PIN avec la mention « Situation connue à … (hors ligne) ». « Exporter pour impression » ouvre l'impression du navigateur : aucun fichier n'est créé ni envoyé.
- **Limites** : la déconnexion automatique par expiration de session n'efface pas la copie locale (seule la déconnexion explicite le fait ; l'accès reste protégé par le PIN et l'effacement à J+1 de l'événement) ; la zone d'une personne n'est connue que par ses contrôles (une personne jamais contrôlée est à « Accueil ») ; un seul événement est consulté à la fois (sélecteur si plusieurs) ; les alertes ne sont pas poussées (le superviseur doit avoir l'écran ouvert).
- **Corrections de l'usine** : la recherche d'un présent en attente de son délai ne doit pas annuler l'ouverture d'une fiche (corrigé) ; la navigation fixe ne masque plus les boutons atteints par défilement (`scroll-padding-bottom`).
- **Page d'accueil publique** (demande du dirigeant, 28 octobre 2026) : la page « en construction » est remplacée par une page opérationnelle et sobre (présentation, trois points forts, parcours en sept étapes, accès par rôle vers le poste, le contrôle, le pilotage et l'administration, bouton de connexion). Elle ne contient aucune donnée, aucun nom et aucune promesse non tenue ; les liens exigent une connexion (la session les redirige vers l'écran de connexion). Son titre et son pied de page ne mentionnent plus la construction ; le pied de page commun des écrans d'administration non plus. Tests e2e `offline` et `update` adaptés au nouveau titre.

## L9 — groupes de stagiaires transportés en car (28 octobre 2026)

- **Source de vérité** : `visitors.group_id` ; `group_members` porte l'avancement (voir docs/schema.md). Un stagiaire est dans un seul groupe (contrainte d'unicité). Retirer un stagiaire est refusé dès qu'un badge lui a été remis.
- **Règle de contrôle** : réglage `controle_stagiaires`, valeur initiale `encadreur` (décision du dirigeant du 27 octobre 2026, **à confirmer avec le client**), modifiable dans Administration > Groupes (journalisé). Mode `encadreur` : l'agent ne peut pas valider la correspondance tant que l'encadreur n'a pas confirmé la personne. Mode `identite` : case « pièce contrôlée » et ligne dans `identity_checks`.
- **Remise des badges** : toujours par `BadgeService::associer`, sans contournement. Un badge hors du lot réservé au groupe est refusé (`hors_lot_groupe`) ; à l'inverse, `BadgeService` refuse un badge réservé à un groupe pour une personne qui n'en fait pas partie (`autre_groupe`).
- **Encadreur** : contrôle d'accès par objet (le groupe est toujours cherché parmi ceux dont il est l'encadreur ; un autre groupe donne 403). Sa confirmation est enregistrée (qui, quand) et journalisée.
- **Hors ligne** : les actions de l'agent passent toutes par la file locale (même en ligne : écriture locale puis envoi). **Limite connue** : la confirmation de l'encadreur n'arrive sur la tablette que par la synchronisation ; en mode `encadreur`, un agent sans réseau ne peut contrôler que les personnes déjà confirmées à sa dernière synchronisation.
- **Arrivée** : un badge de stagiaire scanné passe la personne à « arrivé », statut visiteur `present`, événement d'entrée (`details.arrivee_groupe`). Un badge scanné non embarqué est accepté (la personne est bien là) mais signalé (`groupe_ecart`). À la fin du contrôle, les embarqués non scannés sont signalés.
- **Alertes et journal** : sans nom de personne (numéro de car et nombres seulement).
- **Contrat de la copie tablette** : `tests/Feature/SyncTest.php` attend désormais `group_id` sur les visiteurs et `controle_stagiaires` dans les réglages (ajouts, aucune attente retirée).
- **Tests de l'intégration continue (L8)** : le chronométrage du test de présence (500 ms) prend le meilleur de trois mesures ; le test de lecture QR par caméra simulée rouvre la caméra si le flux reste muet à la première ouverture.

## L10 — Espace de l’hôte, 28 octobre 2026

Source : prompt docs/prompts/L10.md, plan sections 3/7, fonctionnement v1.0 sections 2/7/10. Départ de main après fusion L9 (#16, 1be551b).

**Décisions explicites du dirigeant du 28 octobre 2026** : l’hôte attribue uniquement la catégorie **Visiteur** ; le délai initial sans réponse avant remontée au superviseur est de **2 minutes**, réglable ensuite dans l’administration. Aucun nouveau droit de catégorie, de zone, de badge ni de compte pour l’hôte.

Choix techniques :

- Aucun ajout de dépendance. Notifications internes PostgreSQL, trois types, dédoublonnage ; rechargements partiels Inertia toutes les 2,5 s en premier plan. Pas d’e-mail, de SMS ou de push. L’étude sans code est dans docs/NOTIFICATIONS.md.
- Contrôle d’objet : hosts.user_id est revérifié sur toute lecture, écriture, décision ou marquage d’une notification. Un compte sans fiche reste vide et reçoit une explication. Rattachement des fiches et réglage du délai dans l’administration des comptes, sous comptes.gerer.
- Décisions sous verrou, une seule fois ; host_validation L5 conservé. Le non listé accepté conserve la restriction à l’accueil accompagné. Refus visible par l’agent et transmis au superviseur par son service d’alertes ; la réponse n’est pas une entrée automatique.
- Avis hors période enregistré dans la notification, lié à l’empreinte des dates : un changement de période invalide l’ancien accord. Le circuit téléphonique d’avis hôte/superviseur existant reste disponible.
- L’hôte ne peut plus modifier ou retirer une fiche après la moindre entrée enregistrée, même si elle est sortie depuis. Retrait logique, sans supprimer les historiques en ajout seul ; la copie tablette, la recherche et les services d’admission en tiennent compte. Un badge déjà remis n’est pas annulé par l’hôte (aucun droit d’annulation) : sa restitution relève de l’accueil ou du superviseur.
- Révision technique sur l’événement pour détecter plusieurs modifications de la liste dans la même seconde, incluse dans la version L6 ; aucune donnée authentifiée ajoutée au cache PWA. Les réponses de l’hôte rejoignent la copie chiffrée du poste ; les demandes d’avis exigent le réseau.
- Doublons/homonymes : même normalisation SQL et même nettoyage des valeurs que L3. L’avertissement reste générique quand il concerne l’invité d’un autre hôte ; aucun identifiant ou second critère de cet autre invité ne fuit. Le verrou d’événement est partagé avec les imports.
- Journal : notification.creee, hote.decision_non_liste, hote.avis_hors_periode, liste.invite_ajoute/modifie/retire, hote.rattache, hote.delai_modifie ; identifiants, noms de champs ou nombres uniquement, jamais de valeur nominative ni de motif libre. Les privilèges de audit_logs, access_events et identity_checks restent inchangés.

- Les demandes non listées sans compte hôte lié remontent aussi après le délai ; un rattachement ultérieur rattrape les demandes en attente. Une alerte normale L8 existante est élevée à « haute » sans duplication. Les changements de rattachement et les décisions/saisies verrouillent la fiche hôte pour éviter une course d’autorisation. Le retrait se propage aussi aux effectifs actifs des groupes, sans effacer group_members.

## Tableau de bord de l’administrateur, 28 octobre 2026

Demande du dirigeant (« implémente-le maintenant »), à partir de la maquette publiée. Page `/administration`, permission `evenements.gerer`, double authentification comme le reste de l’administration.

- **Lecture seule, aucun nom de personne** : `app/Services/AdminDashboard.php` ne renvoie que des nombres, des noms de postes et des libellés d’actions. Aucune table nouvelle, aucun réglage nouveau.
- **Six étapes** (zones, liste, badges, tablettes, groupes, comptes) avec trois états en toutes lettres et en icône (« ✓ Fait », « ● En cours », « ○ À faire ») : jamais la couleur seule. Le bouton principal mène à la première étape non terminée.
- **« À surveiller »** : tablette sans synchronisation depuis plus de 60 minutes, code d’enrôlement qui expire sous 15 minutes, noms identiques dans la liste, badges sans catégorie. Seuils en constantes du service (pas de réglage d’exploitation dans les fichiers techniques : **à déplacer dans Administration si le dirigeant veut les régler**).
- **Mise en page** : nouveau gabarit `AdminLayout` (menu latéral filtré par permission, menu repliable sur téléphone). Les autres écrans d’administration gardent leur gabarit actuel tant que le dirigeant ne demande pas leur refonte.
- **À valider par le dirigeant** : `jsqr` est passé de dépendance de développement à dépendance d’exécution en L9 (lecteur de secours des QR des badges, `resources/js/poste/media.ts`). Aucune autre dépendance ajoutée ici.

## Accueil du superviseur (pilotage), 28 octobre 2026

Refonte de l'écran `/pilotage` à la demande du dirigeant. Aucune route, permission ni table nouvelle ; les écrans Alertes, Présents, Litiges, Groupes et Évacuation ne changent pas.

- **Ordre de lecture** : la situation en une phrase (« Tout est calme », « N alertes à traiter », « N cas à décider »), puis les alertes, puis les compteurs avec la part des attendus déjà arrivés, puis « Sur le terrain » (cas à décider, cars, tablettes). Gravité et état toujours en mots et en symbole, jamais la couleur seule.
- **Nouvelle lecture** `Pilotage::overview` : nombres seulement (alertes critiques et hautes, cas à décider, cars partis/arrivés/en écart, tablettes sans nouvelles), rechargée par la même interrogation automatique de 3 s (`overview` ajouté aux propriétés partielles).
- **Tablettes sans nouvelles** : le superviseur ne voit qu'un nombre, sans lien vers l'administration des postes (droit réservé à l'administrateur). Le seuil de 60 minutes est partagé avec le tableau de bord de l'administrateur (`Pilotage::SILENT_AFTER_MINUTES`), toujours en constante : à déplacer dans Administration si le dirigeant veut le régler.
- Les identifiants de test des parcours existants (`compteur-*`, `valeur`, `aucune-alerte`, `alerte-*`, `mis-a-jour`) sont conservés.

## Accueil de l'agent (poste d'accueil), 28 octobre 2026

Refonte de l'écran de recherche du poste d'accueil, d'après la maquette validée par le dirigeant le 28 octobre 2026. Aucune route, table ni permission nouvelle ; la logique d'accueil (recherche, identité, décision, badge, entrée) est inchangée.

- **Panneau « Mon poste » à droite de la recherche** (`DeskPanel.vue`) : accueillis, refusés, badges restants du poste, visiteurs non listés qui attendent leur hôte (bouton « Reprendre »), derniers passages, et, quand des opérations attendent d'être envoyées, leur détail par type avec un bouton « Envoyer maintenant ».
- **Tout est calculé sur la tablette** (`LocalPosteRepository.deskSummary`), donc disponible sans réseau. « Accueillis » et « Refusés » comptent les opérations faites **par cette tablette** (entrées, refus enregistrés dans sa file), pas l'ensemble de l'événement : le total de l'événement reste au pilotage du superviseur. Les badges restants sont ceux réservés à ce poste, hors lots de cars. Sans réseau, le panneau indique que les chiffres viennent de la copie de la tablette et l'heure de la liste.
- **Compter sans déchiffrer** : le type et l'état d'une opération sont en clair dans IndexedDB ; seules les trois dernières lignes de « Derniers passages » sont déchiffrées. Aucune donnée personnelle nouvelle n'est écrite en clair.
- **Bandeau hors réseau** « Vous continuez à travailler… » et mention « Liste des visiteurs à jour » dans la barre d'état. Les textes « En ligne » / « Hors ligne », « Opérations en attente : N » et les identifiants de test existants sont conservés.
- **À valider par le dirigeant** : l'alerte « il reste peu de badges » (moins de 10 % du lot du poste, ou 5 badges) est une proposition de ma part ; le seuil est en constante dans le composant. Le renvoi vers le superviseur après 15 minutes sans réseau, montré dans la maquette, n'est **pas** implémenté (aucune règle dans le plan).

## Audit global d'aptitude militaire, 28 octobre 2026

À la demande du dirigeant (le client final est l'armée de Côte d'Ivoire), audit global rédigé dans docs/AUDIT_GLOBAL.md : verdict, écarts classés P0/P1/P2, feuille de route proposée (lots L14 à L19 entre L12 et L13), et onze décisions à obtenir. **Projet de travail : aucune décision n'est prise** tant que le dirigeant ne l'a pas validée. Le dirigeant a approuvé le 28 octobre 2026 les propositions du lot « réglages » (délai hors réseau, seuil de badges bas, seuil de tablette silencieuse rendus réglables dans Administration) ; leurs valeurs de départ restent à confirmer.

## L14a — alertes du contrôle d'accès (audit global B2 et B3), 28 octobre 2026

Priorité n°1 de docs/AUDIT_GLOBAL.md, lancée sur « go » du dirigeant. Un badge refusé ou copié ne reste plus seulement dans le journal : le superviseur est prévenu.

- **Quand une alerte est créée** (`app/Services/ControlAlerts.php`, appelé par `SiteControl`) :
  - **Badge refusé à un contrôle ou à une sortie** : critique pour un badge **annulé** (perdu ou volé qu'on essaie encore) ; haute pour restitué, non associé, invité retiré, autre événement, et QR **scanné** inconnu ; normale pour hors période et code **tapé** inconnu (souvent une faute de frappe).
  - **Zone interdite** : badge valide présenté à un point dont la zone n'est pas dans sa catégorie (haute).
  - **Double présentation** : le même badge validé (contrôle valide, entrée ou sortie) à deux points **différents** en moins de N secondes (haute, « copie possible »). Un second passage au même point ne compte pas.
  - **Rafale** : au moins N refus ou zones interdites au même point en M minutes (haute).
- **Contrôle fait hors ligne** : l'alerte est créée au rejeu par la synchronisation, avec l'heure déclarée par la tablette (jamais dans le futur) et la mention « Contrôle fait hors ligne à HH:MM ».
- **Sans nom de personne** : code du badge, nom du point de contrôle et nombres seulement (même règle que les autres alertes).
- **Dédoublonnage** : une même cause au même point dans la même tranche de cinq minutes ne crée qu'une alerte ; la gravité fait partie de la clé (un QR inconnu ne peut pas être masqué par un code tapé).
- **Réglages d'Administration > Postes** (jamais en constante) : `controle_delai_min_points` (**15 s**), `controle_rafale_nombre` (**5**), `controle_rafale_minutes` (**5**). **Valeurs de départ proposées, à confirmer avec le client** : 15 s suppose deux points distants d'au moins quelques mètres ; un site où l'accueil touche la première porte peut demander moins. Chaque modification est journalisée (`poste.reglage_modifie`).
- **Migration** `2026_11_07_000000_create_control_alerts` : quatre types d'alerte ajoutés au CHECK `alerts_type_valid` (`controle_refuse`, `zone_interdite`, `badge_double`, `controle_rafale`) et les trois réglages.
- **Pas fait dans ce lot** (reste dans L14) : alertes de dépassement et de non-restitution (B4), comptage par zone (B5), gel d'urgence d'un poste, d'une zone ou d'un car (C5). Alerte sonore et escalade : lot L16 (C1).
- **Limite connue** : la détection de double présentation compare les événements **déjà reçus par le serveur**. Deux contrôles faits hors ligne sur deux tablettes ne sont comparés qu'au moment où les deux sont synchronisés ; l'alerte arrive donc en retard, pas jamais.

## L14b — hors réseau et seuils réglables (audit global F2 et C1 en partie), 28 octobre 2026

Cinq réglages d'exploitation quittent le code et deviennent des réglages d'Administration > Postes (règle 6 de AGENTS.md). Migration `2026_11_08_000000_add_offline_and_threshold_settings`. **Valeurs de départ proposées, à confirmer avec le client.**

| Réglage (clé) | Départ | Effet |
|---|---|---|
| `poste_delai_appel_minutes` | 15 | Sans réseau depuis N minutes, la barre d'état de la tablette affiche « Sans réseau depuis N minutes : prévenez le superviseur ». C'est un message à l'agent, pas une alerte envoyée : sans réseau, la tablette ne peut prévenir personne. |
| `poste_hors_reseau_max_heures` | 12 (0 = jamais) | **Audit F2.** Sans synchronisation réussie depuis N heures, la tablette se verrouille et n'accepte plus le PIN qu'avec le réseau : à l'ouverture, elle tente une synchronisation, et si elle échoue la tablette reste verrouillée avec le message « connectez-la au réseau pour reprendre. Rien n'est perdu. » Le contrôle est refait chaque minute. **Rien n'est effacé** : le PIN reste valable et les opérations en attente restent intactes. |
| `poste_badges_bas_pourcent` / `poste_badges_bas_minimum` | 10 % / 5 | Avertissement « Il reste peu de badges » au poste d'accueil : badges restants ≤ le plus grand des deux seuils. |
| `pilotage_poste_silencieux_minutes` | 60 | Une tablette enrôlée sans synchronisation depuis N minutes est signalée au superviseur et à l'administrateur. Remplace la constante `Pilotage::SILENT_AFTER_MINUTES`. |

- **Compromis à trancher avec le client (F2)** : la règle n°7 du plan (« le poste ne s'arrête jamais ») s'oppose à la sécurité d'une tablette volée. Avec 12 heures, un événement d'une journée n'est jamais bloqué par une coupure ordinaire, mais une panne réseau de plus de 12 heures arrête le poste jusqu'au retour du réseau. Le client peut monter la valeur ou mettre 0.
- **Les tablettes reçoivent les seuils par la copie de travail** (`settings.offline_call_minutes`, `offline_max_hours`, `low_badges_percent`, `low_badges_min`). La version de la copie tient maintenant compte de ces réglages : sans cela, une tablette n'aurait jamais reçu un seuil modifié.
- **Pas fait** : alerte sonore et escalade des alertes du superviseur (C1, lot suivant), code de contrainte (C2).

## L14c — alerte sonore et escalade (audit global C1), 28 octobre 2026

Deux volets, dans les limites déjà décidées en L10 (aucun e-mail, SMS ni notification poussée avant l'avis du client).

- **Escalade** : une alerte **critique ou haute** encore ouverte après `alerte_escalade_minutes` (**5 minutes au départ, à confirmer avec le client**) est marquée une seule fois comme escaladée (`alerts.escalated_at`, migration `2026_11_09_000000_add_alert_escalation`) par la tâche planifiée `alertes:escalader` (toutes les 30 secondes). Elle est alors **visible** : carte du superviseur marquée « escaladée à l'administrateur » (en mots et avec ⏰), et ligne « N alerte(s) sans prise en compte » en tête de « À surveiller » du tableau de l'administrateur. L'escalade disparaît quand l'alerte est prise en compte ou réglée. Journalisée (`alerte.escaladee`, identifiants et types seulement). **Limite** : l'administrateur doit regarder son tableau ; sans canal externe, rien ne le sonne.
- **Alerte sonore et vibrante** (application du superviseur, `useAlertSound`) : un bouton « Son des alertes » dans l'en-tête du pilotage. Le navigateur interdit un son sans geste : le superviseur l'active une fois, son choix est conservé sur sa tablette. Un nouveau cas sonne (un bip) ; un cas critique sonne trois fois et vibre ; tant qu'une alerte critique reste ouverte, un rappel sonne chaque minute. **Limite** : le son ne joue que **dans l'application ouverte** et seulement tant que la page est visible (l'interrogation s'arrête sur un onglet caché). Un téléphone en poche, écran éteint, ne sonne pas : c'est la raison d'être d'un canal externe, à décider avec le client.
- Réglage ajouté à Administration > Postes (« Une alerte critique ou haute sans prise en compte depuis… »).
- **Pas fait (reste de C1)** : canal externe (SMS, radio, notification poussée) : décision du client. **Pas fait** : code de contrainte (C2).

## Retour à la simplicité, 28 octobre 2026

Décision du dirigeant : l'application revient à l'état décrit dans le document de présentation destiné au client. **Retirés du code** : les deux profils de sécurité (standard / renforcé), le cinquième rôle (officier de sécurité), la double validation par un second administrateur, l'approbation des invités, la liste d'interdits, le code de contrainte, et le 2FA pour tous les rôles. Le 2FA reste obligatoire pour l'administrateur et le superviseur (PLAN_CONCEPTION, section sécurité).

- **Conservés** : alertes du contrôle d'accès (L14a), seuils hors réseau réglables (L14b), alerte sonore et escalade (L14c).
- **En réserve** : les fonctions retirées restent décrites dans docs/AUDIT_GLOBAL.md et dans l'historique git (commits L15a, L15b, L16, L17, L14d, annulés par des « revert »). Elles ne reviennent que sur demande explicite du client.
- **Base de données** : les migrations de ces fonctions sont supprimées du dépôt. Une base de démonstration déjà migrée avec elles doit être reconstruite (`make migrate-fresh-seed`) ; elle ne contient que des données fictives.
- **Décision confirmée** : seule la pièce d'identité (A1) est approuvée parmi les points de l'audit ; les autres sont suspendus.

## Pièce d'identité photographiée : option de l'administrateur (audit global A1), 28 octobre 2026

Décision du dirigeant : seule fonction retenue de l'audit, **sous forme d'option désactivée par défaut**. Migration `2026_11_10_000000_add_identity_document`, réglage `identite_piece_active` (Administration > Postes, case « Photographier la pièce d'identité »).

- **Par défaut (option désactivée)** : l'accueil reste celui du plan, une case « pièce d'identité contrôlée » et la photo du visiteur. Aucune pièce n'est demandée ni conservée, même si une tablette en envoie une.
- **Option activée** : l'agent choisit le **type de pièce** (carte nationale, passeport, permis, carte militaire, carte professionnelle, autre) et la **photographie** (1 280 pixels au plus, JPEG, 1,5 Mo au plus, métadonnées retirées). Le **numéro est facultatif** (4 à 30 lettres, chiffres, tirets ou barres obliques). Valable en ligne et hors ligne ; les tablettes connaissent le réglage par la copie de liste.
- **Chiffrement** : l'image est chiffrée sur le disque comme les photos (`identity-documents/*.enc`), le numéro est chiffré en base. Le journal d'audit ne contient que le **type**. Aucun écran ne montre l'image ni le numéro : elle sert de preuve a posteriori, consultable par l'application sur décision ultérieure.
- **Conservation** : la photo de la pièce et le numéro sont effacés par la purge des photos (même échéance que les photos de visiteurs) ; le type reste. Durée à fixer avec le client, base légale à vérifier par un juriste.
- **Pas fait** : lecture automatique du numéro (nouvelle bibliothèque interdite sans accord), rapprochement avec une liste, pièce lors de l'appel nominal d'un car.

## L12 — production : déploiement durci, installation guidée, sauvegardes (28 octobre 2026)

Documents : [DEPLOIEMENT.md](DEPLOIEMENT.md), [DURCISSEMENT_SERVEUR.md](DURCISSEMENT_SERVEUR.md), [HEBERGEMENT.md](HEBERGEMENT.md). Code : `docker/prod/`, `docker-compose.prod.yml`, `deploy/prod/`, migrations `2026_11_11` (état des sauvegardes) et `2026_11_12` (drapeau d'installation).

- **Deux cibles distinctes** : la **démonstration** (serveur partagé, données fictives, `APP_ENV=demo`, bandeau « Démonstration : données fictives ») et la **production** (`APP_ENV=production`, aucune donnée de démonstration : `db:seed` et `DemoSeeder` refusent de s'exécuter). La démonstration ne passait plus `APP_ENV=production` : `deploy/demo/deploy.sh` la fait passer à `demo`.
- **Images** : trois étapes (interface Node, dépendances PHP sans outils de test, images finales) ; le code appartient à root, l'application ne peut écrire que dans `storage/` et `bootstrap/cache/` (tmpfs) ; PHP-FPM et Nginx (`nginx-unprivileged`, ports 8080/8443 publiés en 80/443) sans privilèges ; tag = version Git, jamais « latest ».
- **Réseau** : la base et PHP sont sur un réseau Docker `internal` (aucun accès à Internet), aucun port publié hors nginx.
- **Secrets** : fichiers `prod-data/secrets/*` (0400, propriétaire 10001) montés comme secrets Docker ; les scripts de base de données lisent désormais variables et fichiers de secrets (`DB_OWNER_PASSWORD_FILE`, `IKOMA_DB_APP_PASSWORD`, `DB_HOST`) en plus de `.env`/`.secrets` (développement inchangé). Séparation migrateur / compte applicatif conservée (`provision-db`, `grant-db`, `migrate`).
- **TLS** : Nginx termine HTTPS ; trois modes : Let's Encrypt (webroot, certbot de l'hôte, `renew-cert.sh`), certificat fourni, certificat interne auto-signé (réseau fermé). Redirection 80 → 443, HSTS. Choix de Nginx plutôt que Caddy pour respecter « une seule porte d'entrée (nginx) ».
- **Assistant d'installation** `/installation` : n'existe que si `install.sh` a déposé un jeton, que l'installation n'est pas terminée et qu'aucun administrateur n'existe ; crée administrateur (14 caractères, 2FA imposé dès la première connexion), événement et zone d'accueil ; se verrouille (404) ; accès refusés journalisés par empreinte d'adresse. Le jeton n'apparaît ni dans le journal ni dans la base.
- **Sauvegarde** : GPG asymétrique (clé **publique** sur le serveur, privée hors serveur), base au format PostgreSQL compressé + fichiers + effectifs + SHA-256 ; rétention réglable (14 jours) ; copie externe par commande configurable ; résultat enregistré (`sauvegarde.reussie` / `sauvegarde.echouee` au journal d'audit, sans donnée) ; alerte administrateur au-delà de 26 h (réglage `sauvegarde_alerte_heures`) ou en cas d'échec, seulement quand `sauvegarde_active` = 1 (activé par l'assistant en production).
- **Restauration** : par défaut dans une base séparée ; `audit:verify` + comparaison aux effectifs relevés à la sauvegarde ; remplacement de la production seulement avec `--remplacer-la-production` et confirmation. Test automatisé en CI.
- **Mise à jour** : sauvegarde obligatoire avant toute migration, test de fumée, retour arrière automatique en cas d'échec. Règle : migrations additives (le code précédent fonctionne sur la base migrée).
- **Santé** : `/sante` sans connexion, sans donnée ni version ; 503 si la base ne répond pas.
- **Exceptions assumées** : PostgreSQL démarre en root pour initialiser son volume puis abandonne ses droits (comportement de l'image officielle) avec capacités réduites ; les failles élevées et critiques des paquets Debian des images sans correctif publié restent un **risque résiduel connu** (décision du dirigeant, option B, voir REVUE_SECURITE).
- **Non fait / à décider** : lieu d'hébergement (HEBERGEMENT.md), centralisation des journaux et détection d'intrusion (audit F3), SBOM et signature des images (audit G2), gestion des appareils (tablettes), test d'intrusion indépendant, PSSI du client, politique de conservation des sauvegardes alignée sur la purge. Les **images, le compose et les scripts sont exécutés par la CI seulement** ; aucun serveur réel n'a été touché.

## Administration unifiée — 29 octobre 2026

Mission et deux maquettes validées par le dirigeant : un administrateur, un espace, une navigation. Les pages des modules rejoignent `AdminLayout` ; `/espace` redirige uniquement le rôle administrateur après le contrôle 2FA existant. Journal et sécurité gardent leur cadre antérieur pour les autres rôles. Aucun droit ni service métier modifié.

- L'événement de référence du tableau est mémorisé en session et identifié comme tel dans les modules multiévénements, dont la portée et les sélecteurs restent inchangés.
- Comptes : synthèse calculée sur les données du contrôleur, liste tabulaire avec recherche locale, formulaires par ligne dépliable, rattachement des fiches par compte hôte, réglages initialisés depuis les valeurs serveur. Le seul champ serveur ajouté est le booléen `two_factor_confirmed`, sans secret. Les actions sensibles gardent leurs contrôles et la réinitialisation 2FA en deux étapes.
- Cadre et styles limités à l'administration. Menu orange avec texte marine contrasté ; cibles tactiles agrandies ; défilement horizontal limité à la table des comptes. Aucun ajout de dépendance, migration, mécanisme hors ligne ou nouvelle fonctionnalité.
- Contrat, écarts intentionnels, recette et limites de déploiement : `docs/refonte-administration/README.md`. Le lot reste à revoir avant toute fusion.


## PR #26 — recette de la refonte administrative (29 octobre 2026)

- Les deux scénarios navigateur qui échouaient avant le formulaire TOTP ont désormais une gestion limitée et explicite des refus temporaires de connexion : un seul nouvel essai après le délai du serveur. Les limites de connexion, le TOTP et les rôles de l'application restent inchangés. Les tests complets doivent être relancés.
- L'observation ZAP 10112 de niveau informatif est acceptée uniquement pour `laravel-session` et `XSRF-TOKEN` sur la racine, avec la preuve exacte du nom du cookie ; les propriétés de sécurité des cookies restent surveillées et toutes les autres instances bloquent.
- Trivy reste actif et bloquant dans la CI. Le risque résiduel du lot L12 avait été accepté provisoirement ; toute nouvelle faille ou évolution de correctif demande un réexamen avant le déploiement chez le client.


### PR #26 — diagnostic de la CI #143

La CI #143 présente 36 parcours navigateurs réussis et un refus de connexion sur le compte fictif du scénario Zones/Catégories. La réponse HTTP 302 revient à `/login` sans atteindre le formulaire TOTP. La cause précise de cette redirection n'est pas encore établie : elle ne doit pas être qualifiée de régression métier sans preuve.

La commande `test:screen-account`, strictement réservée à `APP_ENV=testing`, utilise désormais un mot de passe aléatoire long à encodage simple et vérifie côté serveur le hachage, l'authentification des identifiants et le rôle avant de transmettre les données à Playwright. Les diagnostics E2E peuvent afficher la route de redirection et la présence d'une erreur de formulaire, jamais les identifiants, le mot de passe ou le secret TOTP. Les contrôles de sécurité de l'application sont inchangés. Une nouvelle CI est obligatoire pour confirmer la correction.


### PR #26 — CI #144 : preuve de la redirection normale du login

La [CI #144](https://github.com/zumradeals/ikoma-core/actions/runs/40871410125) obtient 36 succès E2E et un échec lors d'un scénario distinct (Conservation/Journaux), avant d'afficher la 2FA. La réponse POST /login est bien HTTP 302 vers /two-factor-challenge : il n'est pas justifié de suspecter les identifiants ou de modifier la 2FA. La page reste pourtant /login après 15 secondes. Une sonde de recette supplémentaire, exécutée uniquement en cas d'échec et sans suivre les redirections, relève le statut HTTP du GET /two-factor-challenge depuis la session du navigateur ; elle permettra de départager une perte de session et un problème de transition côté Inertia. Aucune tentative n'est faite pour contourner la 2FA. Trivy demeure inchangé et le contrôle ZAP est passé.

## 29 octobre 2026 — Pilotage superviseur V2

GO du dirigeant après présentation des maquettes ordinateur, tablette et téléphone. Menu repliable en tablette paysage ; annulation de badge dans Autres actions, avec accès depuis la fiche conservé. Cadre visuel partagé avec les écrans opérationnels et le journal suivant les permissions, redirection du seul superviseur depuis `/espace` après le middleware 2FA. Logique métier, fréquences d’actualisation, droits et évacuation hors ligne conservés. Contrat et références : `docs/refonte-pilotage/README.md`. Aucune fusion ni déploiement autorisés par ce GO d’implémentation.
