# Modèle de données L2

Références : PLAN_CONCEPTION.md sections 4.2, 5, 6 et 7 ; [fonctionnement v1.0](reference/Mode_de_fonctionnement_verification_visiteurs.pdf) sections 4, 7 et 8.

Toutes les nouvelles tables ont une clé primaire UUID `id` et les horodatages `created_at` / `updated_at`. Les tables et contrats L1 restent inchangés. Les colonnes temporelles métier utilisent des horodatages avec fuseau ; les dates du seed sont exprimées en UTC.

| Table | Champs métier principaux | Relations |
|---|---|---|
| events | name, starts_at, ends_at, site?, status? | Racine du périmètre d’un événement |
| zones | event_id, name | Événement ; nom unique par événement |
| categories | event_id, name, color, priority_circuit | Événement ; nom unique par événement |
| category_zone | event_id, category_id, zone_id | Catégorie ↔ zone ; paire unique, même événement |
| hosts | event_id, user_id?, name, service? | Événement ; compte L1 facultatif |
| devices | event_id, name, station?, token_hash?, last_synced_at? | Poste réservé d’un badge ; aucun enrôlement dans L2 |
| badge_batches | event_id, name, quantity, margin | Lot appartenant à un événement |
| groups | event_id, name, bus_number?, supervisor_id?, badge_batch_id? | Encadreur (compte L1), lot ; aucun appel nominal opérationnel |
| import_batches | event_id, actor_id?, file_reference?, status?, summary? | Référence structurelle seulement ; aucun import |
| visitors | event_id, full_name, organisation?, host_id?, category_id, group_id?, import_batch_id?, valid_from, valid_until, source, status? | Catégorie obligatoire ; hôte, groupe et import facultatifs du même événement |
| group_members | group_id, visitor_id, boarded_at?, arrived_at? | Groupe ↔ visiteur ; le groupe correspond à visitors.group_id |
| badges | event_id, code, qr_token, status, badge_batch_id, reserved_device_id? | Code et jeton uniques globalement ; lot et poste du même événement |
| badge_assignments | event_id, badge_id, visitor_id, actor_id?, device_id?, assigned_at, returned_at?, cancelled_at? | Historique d’association ; badge, visiteur et appareil du même événement |
| identity_checks | visitor_id, actor_id?, checked_at | Référence du contrôle d’identité, sans parcours opérationnel |
| photos | visitor_id, actor_id?, file_reference, taken_at? | Référence de fichier uniquement ; aucun upload, contenu ni pièce d’identité |
| access_events | event_id, visitor_id, badge_id?, actor_id?, type, checkpoint?, occurred_at | Références du même événement ; aucun enregistrement d’entrée/sortie par l’UI |
| refusals | visitor_id, actor_id?, supervisor_id?, reason, supervisor_decision?, refused_at | Référence structurelle du refus ; aucun traitement opérationnel |

`?` indique une colonne nullable. `supervisor_id` dans groups désigne l’encadreur du groupe ; dans refusals, il désigne le décideur sécurité. Les rôles correspondants seront contrôlés par les futurs modules. Les suppressions de références sont bloquées par les clés étrangères, sans cascade destructive métier.

## Invariants en base

- Statuts exacts des badges : **Disponible, Associé, Restitué, Annulé, Clos**. Enum PHP et CHECK PostgreSQL. `Clos` reste réservé. Aucune transition automatique, réutilisation ou annulation n’est implémentée ; le caractère définitif d’un badge annulé devra être appliqué par les futurs parcours.
- Un seul enregistrement actif de badge_assignments par badge : index unique partiel `badge_assignments_one_active` avec `returned_at IS NULL AND cancelled_at IS NULL`. Une annulation d’association n’est pas une transition du statut du badge.
- Code lisible et jeton QR uniques ; jeton aléatoire de 64 caractères généré au seed. Aucun jeton dans les réponses Inertia des badges ; attribut caché sur le modèle.
- Source des visiteurs : `listé` ou `non listé`. Dates de validité ordonnées. Aucune liste de statuts visiteurs n’est inventée : status reste nullable jusqu’à son cadrage.
- Les clés étrangères composites empêchent de mélanger les événements entre catégories, zones, visiteurs, lots, postes et associations.
- Les zones accessibles à un visiteur sont uniquement `visitor.category.zones`. Aucune table de droits individuels ni duplication de zones sur visitors.

## Recherche

Index GIN trigramme sur `public.ikoma_normalize(full_name)`. La fonction SQL est déclarée IMMUTABLE et utilise explicitement le dictionnaire `public.unaccent`, puis les minuscules. Cette déclaration suppose que le dictionnaire reste stable ; tout changement de dictionnaire ou de règles de normalisation impose une reconstruction de l’index (`REINDEX INDEX visitors_name_trgm`).

Le service `App\Services\VisitorSearch` exige l’UUID d’un événement, lie ses paramètres SQL, accepte les préfixes à partir de deux caractères et les correspondances trigrammes, classe par similarité puis nom et identifiant, limite à 10 et retourne uniquement id, full_name et la catégorie (id, name, color, priority_circuit). Aucun jeton, photo, détail d’identité, hôte, statut ou journal n’est transmis. Aucune route de recherche n’est ouverte dans L2. Le contrôle du rôle du futur appelant et de son événement autorisé devra être appliqué dans le futur module.

Référence technique : [PostgreSQL 18 — pg_trgm](https://www.postgresql.org/docs/18/pgtrgm.html).

## Jeu fictif et reproductibilité

`DemoSeeder` crée transactionnellement un événement de démonstration, six zones, quatre catégories et leurs accès, trois hôtes, 300 visiteurs, un lot de 400 badges (60 réservés au poste fictif A), une tablette fictive et un groupe/car de 40 stagiaires. VIP a un circuit prioritaire. Les noms demandés et plusieurs homonymes sont présents ; les autres entrées sont explicitement nommées « Visiteur fictif ».

Les UUID du jeu sont dérivés de clés de démonstration stables ; les jetons QR restent aléatoires. Un second seed ne duplique ni n’écrase le jeu existant. Les comptes fictifs et leur comportement de génération unique de mots de passe L1 sont conservés. Aucun contrôle, photo, refus, import, entrée ou association de badge n’est simulé comme une opération réelle.

## Sécurité et non-régression

Les trois écrans exigent auth, le contrôle TOTP L1 et leur permission propre dans la route et le contrôleur. Créations et modifications de zones/catégories sont atomiques avec le service Audit L1, avec identifiant, événement et noms des champs modifiés seulement. Les noms et coordonnées de visiteurs ne sont pas copiés au journal.

`scripts/migrate.sh` et `scripts/grant-db.php` restent inchangés : les nouvelles tables reçoivent les droits applicatifs existants ; audit_logs reste SELECT/INSERT uniquement, sans UPDATE/DELETE/TRUNCATE. Un test interroge les privilèges réels. Aucun secret ajouté.

FoundationTest de L1 conserve ses assertions d’absence de tables métier. Un support de test rejoue les deux migrations d’identité/permissions L1 dans un schéma temporaire isolé, avec les mêmes restrictions d’audit, puis le détruit. Les autres tests L1 et les tests L2 utilisent le schéma complet courant. Aucun fichier de test L1 n’est supprimé ou affaibli.

## Questions ouvertes

1. Quelle permission gouvernera l’administration des événements ? Aucun écran ni droit ajouté dans L2.
2. La fiche du document liste des « zones autorisées » sans préciser des exceptions individuelles. L2 les dérive de la catégorie, conformément à la consigne ; aucune exception par visiteur n’est implémentée.
3. Réutilisation ou usage unique des badges : décision client attendue. Aucune transition vers Clos.
4. Quel vocabulaire et quelles transitions pour les statuts des visiteurs, événements et imports ? Colonnes nullable, sans règle inventée ; les modules correspondants viendront ensuite.
5. Pièce individuelle ou confirmation de l’encadreur pour les stagiaires : règle de contrôle toujours en attente, aucun traitement de groupe activé.


## Évolution L3 — Import nominatif

Aucune nouvelle table. Migration additive `2026_10_30_000000_validate_visitor_status` : CHECK sur visitors.status (`attendu`, `present`, `sorti`, `refuse`), les nulls historiques L2 restant tolérés. Toutes les lignes créées par l’import ont source `listé`, statut `attendu`, catégorie de l’événement, hôte éventuel du même événement, dates explicites ou bornes de l’événement, import_batch_id renseigné. Aucun groupe ni badge n’est attribué.

import_batches reçoit uniquement à la confirmation : UUID (identique au jeton d’aperçu), event_id, actor_id, file_reference = **nom du fichier seulement**, status = `termine`, summary JSONB : `read`, `importable`, `blocked`, `warnings`, et horodatages. Les champs existants ne changent pas. Hôtes créés uniquement sur option et dans la même transaction ; toutes les écritures et l’audit sont annulés ensemble en cas d’erreur.

Les aperçus ne sont pas des données métier persistantes : disque privé local, bail par événement, propriétaire UUID, expiration d’une heure, jeton et manifeste chiffré. Le contenu du rapport et le fichier disparaissent après usage ; import_batches ne conserve que les nombres. Le service de recherche L2 est exposé uniquement dans l’écran d’import, sous permission listes.importer, événement validé, maximum dix résultats et projection minimale existante.

Le journal conserve uniquement les identifiants et nombres pour liste.import_apercu, liste.import_confirme et liste.import_abandonne. Les privilèges SQL audit_logs et grant-db restent inchangés. Les questions des statuts visiteurs sont désormais tranchées par les décisions du 27 octobre 2026 ; celles des événements/imports futurs et du contrôle des stagiaires restent ouvertes.


## Évolution L4 — vie d’un badge

Migration `2026_10_31_000000_create_badge_lifecycle`. Ajouts : `badge_batches.category_id`, `badges.category_id`, `badges.reserved_group_id` (CHECK : jamais poste et groupe à la fois), `badges.cancellation_reason`, `badge_assignments.cancellation_reason`, `access_events.corrige_id` (référence vers l’événement corrigé : une correction est un nouvel événement). Clés étrangères composites (id, event_id) comme en L2. Unicité du code : `(event_id, code)` au lieu de globale ; jeton QR toujours unique globalement.

Invariants : index unique partiel `badge_assignments_one_active_visitor` (un seul badge actif par visiteur) en plus de `badge_assignments_one_active` ; déclencheur `badges_status_guard` (transitions Disponible→Associé, Associé→Restitué, Disponible→Annulé, Associé→Annulé, Restitué→Disponible, Restitué→Clos ; Annulé et Clos définitifs). `access_events` et `identity_checks` : le compte applicatif n’a plus UPDATE, DELETE ni TRUNCATE.

Réglages ajoutés dans `settings` : `badge_marge_pourcent` (10), `badge_reutilisable` (non). Lot : `quantity` = fabriqués, `margin` = nombre de badges de marge. Les lots hérités du seed ont `category_id` nul.

Services : `BadgeLots` (génération, réservation), `BadgeService` (associer, restituer, annuler, controler), `QrSvg`, `CategorySymbol`. Détails et décisions : JOURNAL_DECISIONS.md, rubrique L4.


## Évolution L5 — poste d'accueil en ligne

Migration `2026_11_01_000000_create_reception_desk`.

- **sync_operations** (nouvelle) : `id` UUID = identifiant d'opération créé par la tablette (en-tête `X-Operation-Id`), `device_id` (poste), `user_id`, `type` (identite, valider, refuser, badge, entree, non_liste), `payload_hash` (SHA-256 du type et de la charge), `status_code`, `result` (jsonb, réponse rejouée telle quelle), `received_at`, `processed_at`. Insertion `ON CONFLICT DO NOTHING` : rejouer un identifiant avec la même charge renvoie le résultat enregistré sans écriture ; une autre charge ou un autre compte donne 409. Un refus métier est enregistré aussi (ses écritures sont annulées par un point de sauvegarde). Les noms de personnes n'y sont pas copiés, hormis dans le message de refus « badge déjà associé à … », conservé comme réponse rejouable (purge au lot L11).
- **visitors.host_validation** : `en_attente` / `accepte` / `refuse` (CHECK), nul pour un visiteur listé. Visiteur non listé : `source = non listé`, `status = attendu`, catégorie choisie à la création (« Visiteur » par défaut), validité de l'instant de création à la fin de l'événement. L'accès limité à la zone d'accueil avec accompagnement est **déduit** de `source = non listé` (aucune colonne).
- **zones.est_accueil** : booléen, case dans l'écran Zones ; ce sont les zones affichées à un non listé.
- **access_events.details** (jsonb) : `validation` → `{hors_periode, avis}` ; `entree` → `{duree_ms}`. Types utilisés : `validation`, `entree`.
- Photos : `photos.file_reference` pointe un fichier `photos/{uuid}.enc` du disque privé `local` (serve désactivé), contenu chiffré par `Crypt` (clé APP_KEY). Aucune route ni permission de consultation.
- Statuts visiteur utilisés : `attendu` → `present` (entrée) ou `refuse`. `present` ne devient pas `sorti` avant le lot L7.

Routes JSON (rôle `agent_accueil` seulement, session + CSRF + permission) : `GET /poste`, `GET /poste/api/postes|contexte|recherche|visiteurs/{id}`, `POST /poste/api/identite|valider|refuser|badge|entree|non-listes`. Hors `postes`, chaque requête porte `X-Poste-Id` ; l'événement est toujours celui du poste.

Services : `Poste\Desk` (règles du parcours), `Poste\Operations` (idempotence), `VisitorSearch` (recherche par mot). La décision de l'hôte sur un non listé est la méthode `Desk::decideHost` (écran de l'hôte au lot L10).

## Évolution L6 — hors ligne et synchronisation

Migration `2026_11_02_000000_create_offline_sync`.

- **devices** (déjà `token_hash`, créé en L2) : ajout de `enrollment_hash` (SHA-256 du code d'enrôlement, unique, vidé après usage), `enrollment_expires_at` (15 minutes), `enrolled_at`, `revoked_at` (révocation immédiate), `wipe_acknowledged_at` (l'ordre d'effacement a été donné une fois). `last_synced_at` est mis à jour à chaque lot reçu. L'état « non enrôlé / enrôlé / révoqué » se déduit de `token_hash` et `revoked_at`.
- **sync_operations** (créée en L5) : ajout de `seq` (numéro de séquence de la tablette), `client_time` (heure déclarée de la tablette, `received_at` reste l'heure du serveur) et `visitor_id` (pour reconnaître une fiche modifiée entre-temps). `status_code` et `result` restent ceux de L5 : une opération rejouée renvoie le résultat enregistré.
- **sync_conflicts** (nouvelle) : une ligne par opération mise en quarantaine (`kind = conflit`, `operation_id` unique, `reason`, `details` en identifiants et nombres) ou par écart d'horloge (`kind = horloge`, sans opération). `status` : `ouvert` / `resolu` (CHECK) ; l'écran de traitement arrive au lot L8, la liste des conflits ouverts est déjà visible dans Administration > Postes. Les clés étrangères sont en `RESTRICT` : un conflit n'est jamais supprimé avec son poste.
- **settings** : `poste_inactivite_minutes` (5), `poste_ecart_horloge_secondes` (300), `poste_echecs_pin_max` (15) ; modifiables dans Administration > Postes.

Routes (agent connecté + jeton d'appareil `X-Device-Token`, sauf `enroler`) : `POST /poste/api/enroler`, `GET /poste/api/liste`, `GET /poste/api/liste/version`, `POST /poste/api/sync` (50 opérations au plus), `POST /poste/api/efface`. Publique : `GET /poste/shell` (coquille précachée, sans donnée). Administration (`postes.gerer`) : `GET|POST /administration/postes`, `POST .../{poste}/code`, `POST .../{poste}/revoquer`, `PUT .../reglages`.

Services : `Poste\Devices` (création, code, enrôlement, révocation), `Poste\ListSnapshot` (copie de travail et numéro de version), `Poste\SyncProcessor` (rejeu, conflits, écart d'horloge). Journal : `poste.cree`, `poste.code_emis`, `poste.enrole`, `poste.enrolement_refuse`, `poste.revoque`, `poste.efface`, `poste.reglage_modifie`, `sync.recue`, `sync.conflit`.

Côté tablette (IndexedDB `ikoma-poste`, Dexie) : `meta` (clés d'appareil non exportables, enregistrement du coffre, compteurs en clair : version de liste, dernière synchronisation), `chunks` (liste de travail, secrets, compteur d'échecs : blocs scellés), `ops` (file : identifiant, séquence, type et état en clair ; contenu scellé), `photos` (scellées, supprimées à l'envoi).

## Évolution L7 — contrôle sur site, sortie, présents

Migration `2026_11_03_000000_create_site_control`.

- **access_events** : `visitor_id` devient facultatif (un contrôle refusé sur un badge inconnu ou non associé n'a pas de visiteur ; CHECK : seul le type `controle` peut être sans visiteur). Types admis (CHECK) : `validation`, `entree` (L5), `controle`, `sortie`, `correction` (L7). Le compte applicatif n'a toujours que INSERT et SELECT sur cette table (aucun UPDATE, aucun DELETE, aucun verrou `FOR UPDATE`).
  - `controle` : `checkpoint` = nom du point (zone ou poste), `details` = `verdict` (`valide`/`refuse`), `raison`, `point_type`, `point_id`, `zone_ok`, `poste_id` et, pour un contrôle fait hors ligne, `heure_declaree`, `differe`, `verdict_tablette`. Aucun nom de personne.
  - `sortie` : `details` = point, poste, `heure_declaree` éventuelle.
  - `correction` : `corrige_id` pointe l'événement d'entrée ou de sortie corrigé (jamais modifié) ; `details` = motif et type corrigé. Un événement ne peut être corrigé qu'une fois (index unique partiel sur `corrige_id`).
  - Index partiel `access_events_presence` (événement, visiteur, heure) sur les entrées et sorties pour le compteur.
- **Compteur de présents** : jamais stocké. Une personne est présente si son dernier événement non corrigé parmi `entree` et `sortie` est une entrée (service `Presence`). `visitors.status` (`present` / `sorti`) reste tenu à jour pour la lecture des fiches.
- **Statuts de badge à la sortie** : `Associé` → `Restitué` → `Disponible` (réglage `badge_reutilisable` = oui) ou `Clos` (non), par `BadgeService::restituer` (L4).
- **Copie de la tablette** (`GET /poste/api/liste`) : tous les badges de l'événement (code, hachage du jeton, catégorie, statut, `mine` = réservé à ce poste) et la liste des postes de l'événement. Le numéro de version tient compte du statut de chaque badge (une annulation change la version) et ignore les événements `controle` (un contrôle ne force pas une nouvelle copie).

Routes : page `GET /controle` (rôle `agent_accueil`, permission `badges.controler`), coquille publique `GET /controle/shell` ; JSON (jeton d'appareil + session) `POST /poste/api/controle`, `POST /poste/api/sortie` (idempotents, `X-Operation-Id`), `GET /poste/api/presents` ; superviseur : `GET /api/presences?event_id=` (permission `presences.consulter`).

Services : `Poste\SiteControl` (contrôle, sortie), `Presence` (compteur, correction), `BadgeService::controler` (paramètre `$journaliser`). Opérations de la file hors ligne : `controle` et `sortie` (permission `badges.controler`). Journal : `controle.refuse`, `sortie.enregistree`, `sortie.refusee`, `presence.corrigee` (identifiants et nombres seulement).

Conflits à la synchronisation (`sync_conflicts`) : `sortie_sans_entree`, `deja_sorti`, `annule`, `non_associe`, `restitue`, `visiteur_refuse` (sortie), et `annule_entre_temps` (contrôle jugé valide hors ligne, refusé par le serveur au rejeu).

## Évolution L8 — pilotage du superviseur

Migration `2026_11_04_000000_create_supervisor_dashboard`.

- **alerts** (nouvelle) : `event_id`, `type` (CHECK : `refus`, `non_liste_attente`, `badge_annule`, `conflit_sync`, `hors_periode`, `car_arrive`, `groupe_incomplet` ; les deux derniers sont réservés au lot L9), `severity` (CHECK : `critique`, `haute`, `normale`), objet concerné (`subject_type` : `visiteur`, `badge`, `conflit`, `groupe` ; `subject_id`), `message` (200 caractères, **sans nom**), `dedupe_key` (unique : une cause ne crée qu'une alerte), `created_at`, `acknowledged_by` / `acknowledged_at` (prise en compte, CHECK : les deux ou aucun), `resolved_at` (alerte réglée d'elle-même : le non listé a reçu sa réponse, le badge annulé a été remplacé, le refus a reçu une décision). Index sur (événement, prise en compte, réglée).
- **refusals** : ajout `decision` (CHECK : `maintenu`, `autorise`, `a_revoir`) et `decided_at` ; `supervisor_id` et `supervisor_decision` (déjà créés en L2) portent le superviseur et ses conditions.
- **visitors.access_note** : mention de l'autorisation donnée par le superviseur après un refus ; affichée à l'agent d'accueil (fiche du poste et copie de la tablette). Avec `a_rebadger` (calculé : un badge a été annulé et aucun n'est actif), elle est ajoutée à la fiche du poste et à la copie de la tablette.

Sources des alertes : refus d'accès (`Desk::refuse`), non listé en attente (`Desk::createUnlisted`, réglée par `Desk::decideHost`), badge annulé (`BadgeService::annuler`, réglée par `associer`), conflit de synchronisation et écart d'horloge (`SyncProcessor`), visiteur validé hors période (`Desk::validate`).

Compteurs (`Pilotage::counters`) : **attendus** = personnes attendues, refusées exclues ; **arrivés** = personnes ayant une entrée non corrigée (même sorties depuis) ; **présents** = compteur exact du lot L7 ; **refusés** = personnes dont l'accès est refusé (décision « autorisé » exclue). **Zone d'un présent** = nom du dernier point de contrôle de type zone depuis son entrée, sinon « Accueil ».

Routes (session, 2FA, permission) : `GET /pilotage` (`pilotage.consulter`), `GET /pilotage/alertes` et `POST /pilotage/alertes/{alerte}/prendre-en-compte` (`alertes.consulter`), `GET /pilotage/presents` (`presences.consulter`), `GET /pilotage/visiteurs/{visiteur}` (`pilotage.consulter`), `POST /pilotage/badges/annuler` (`badges.annuler`), `GET /pilotage/litiges` et `POST /pilotage/litiges/{refus}/decision` (`litiges.decider`), `GET /pilotage/groupes` (`groupes.suivre` et `cars.suivre`), `GET /pilotage/evacuation` et `GET /pilotage/api/evacuation` (`presences.consulter`) ; publique : `GET /pilotage/evacuation/shell` (coquille sans donnée, précachée). Journal : `alerte.prise_en_compte`, `litige.decide`, `badge.annule` (existant), `evacuation.consultee` (identifiants et nombres).

Côté appareil du superviseur (IndexedDB `ikoma-pilotage`, même coffre que le poste) : la dernière liste d'évacuation, chiffrée par un PIN de 6 chiffres choisi par le superviseur ; effacée à la déconnexion explicite, après 15 PIN faux et 24 h après la fin de l'événement.

## Évolution L9 — groupes et cars de stagiaires

- **Source de vérité de l'appartenance** : `visitors.group_id`. `group_members` ne porte que l'avancement de chaque stagiaire dans son groupe ; une ligne ne peut exister que pour le groupe auquel le stagiaire appartient (clé étrangère `(visitor_id, group_id)` vers `visitors`) et `visitor_id` est unique : **un stagiaire, un seul groupe**. Les lignes manquantes (stagiaires rattachés par une importation) sont créées à la première ouverture du groupe (`Groups::ensureMembers`).
- **group_members** : ajout `called_at` (appel), `checked_at` + `check_mode` (`identite` | `encadreur`) + `checked_by` (contrôle de correspondance fait par l'agent), `confirmed_at` + `confirmed_by` (confirmation de l'encadreur ; les deux ensemble ou aucun), `absent_at` (absent à la fermeture du car). Existants : `boarded_at`, `arrived_at`.
- **groups** : ajout `opened_at`, `departed_at` + `departed_by` (car fermé, embarquement enregistré), `arrival_closed_at` (contrôle collectif d'arrivée terminé). `supervisor_id` est l'encadreur.
- **settings** : `controle_stagiaires` = `encadreur` (valeur initiale, décision du dirigeant du 27 octobre 2026, **à confirmer avec le client**) ou `identite`.
- **alerts.type** : ajout `groupe_ecart` (embarqué non scanné à l'arrivée, badge scanné non embarqué). `groupe_incomplet` (absents au départ) et `car_arrive` sont désormais émis.
- **Copie de la tablette** (`ListSnapshot`) : `visitors[].group_id`, `badges[].group_id` (badge réservé à un groupe), `groups[]`, `members[]` (booléens d'avancement, aucun horodatage nominatif) et `settings.controle_stagiaires`. Le hachage de version couvre groupes, membres, réservations de badges et règle de contrôle.
- **Journal** : `groupe.cree`, `groupe.ouvert`, `groupe.badge_remis`, `groupe.absent`, `groupe.embarque`, `groupe.arrive`, `groupe.confirme_par_encadreur`, ainsi que `groupe.encadreur_designe`, `groupe.stagiaire_rattache`, `groupe.stagiaire_retire`, `groupe.reglage_modifie`. Identifiants et nombres uniquement, jamais de nom.
- **Opérations de synchronisation** : `groupe_controle`, `groupe_badge`, `groupe_depart`, `groupe_arrivee`, `groupe_arrivee_fin` (permissions `groupes.appel_nominal`, `groupes.remettre_badges`, `badges.controler`), rejouables sans effet, mises en quarantaine comme au lot L6.

## Évolution L10 — Espace hôte

Migration `2026_11_06_000000_create_host_notifications` :

- notifications : UUID id, recipient_id (users), event_id, visitor_id, requester_id facultatif (agent), device_id facultatif, type (`arrivee`, `non_liste`, `hors_periode`), message court générique, dedupe_key unique, read_at, decision (`autorise`/`refuse`, nullable), reason facultatif, decided_at, escalated_at, horodatages. Les clés étrangères sont restrictives. Le nom de l’invité et celui de l’agent ne sont pas recopiés dans le message : ils sont joints au moment de la lecture autorisée.
- visitors.withdrawn_at : retrait logique avant toute entrée ; les historiques restent référencés. La recherche et la copie tablette excluent ces fiches ; l’entrée et l’association/contrôle d’un badge les refusent. Aucun droit de suppression sur access_events, identity_checks ou audit_logs n’est ajouté.
- events.list_revision : compteur technique inclus dans la version calculée L6, incrémenté dans la transaction d’un ajout, d’une modification, d’un retrait ou d’une réponse hôte. Il distingue deux changements dans la même seconde. updated_at de visitors gagne la précision microseconde pour le contrôle de concurrence des fiches.
- settings.hote_delai_reponse_minutes : 2 initialement, modifiable de 1 à 120 dans Administration > Comptes.

Le rattachement réutilise hosts.user_id. Un compte peut être rattaché à ses fiches de plusieurs événements ; le serveur vérifie à chaque accès que la fiche hôte appartient au compte courant. L’administration peut rattacher les fiches existantes ; elle ne peut pas voler implicitement une fiche déjà rattachée à un autre compte.

L’hôte peut attribuer la catégorie **Visiteur uniquement**. Une catégorie initialement fixée par l’administration peut être conservée ; un changement ne contredit pas un groupe ou un badge déjà remis. Doublons et homonymes utilisent ikoma_normalize et le nettoyage L3 ; un avertissement d’homonymie ne divulgue pas les fiches des autres hôtes.

## Évolution L14a — alertes du contrôle d'accès

Migration `2026_11_07_000000_create_control_alerts`. Aucune table nouvelle. `alerts_type_valid` accepte en plus `controle_refuse`, `zone_interdite`, `badge_double`, `controle_rafale` (sujet `badge` ou aucun ; message sans nom de personne). Réglages ajoutés dans `settings` : `controle_delai_min_points` (15, secondes), `controle_rafale_nombre` (5), `controle_rafale_minutes` (5). Service : `ControlAlerts`, appelé par `SiteControl::control` et `SiteControl::exit`. Détails et décisions : JOURNAL_DECISIONS.md, rubrique L14a.

## Évolution L14b — réglages hors réseau et seuils

Migration `2026_11_08_000000_add_offline_and_threshold_settings`. Aucune table nouvelle. Réglages ajoutés dans `settings` : `poste_delai_appel_minutes` (15), `poste_hors_reseau_max_heures` (12, 0 = désactivé), `poste_badges_bas_pourcent` (10), `poste_badges_bas_minimum` (5), `pilotage_poste_silencieux_minutes` (60). La copie de travail (`ListSnapshot`) porte `settings.offline_call_minutes`, `offline_max_hours`, `low_badges_percent`, `low_badges_min`, et sa version dépend de ces réglages. Décisions : JOURNAL_DECISIONS.md, rubrique L14b.

## Évolution L14c — escalade des alertes

Migration `2026_11_09_000000_add_alert_escalation` : `alerts.escalated_at` (timestamptz, nul tant que l'alerte n'a pas été escaladée) et le réglage `alerte_escalade_minutes` (5). Tâche planifiée `alertes:escalader` (30 s). Journal : `alerte.escaladee`. Décisions : JOURNAL_DECISIONS.md, rubrique L14c.

## Évolution L14d — code de contrainte

Migration `2026_11_10_000000_add_duress_alert` : `alerts_type_valid` accepte `contrainte` et `alerts_subject_valid` accepte le sujet `poste`. Nouvelle opération de synchronisation `contrainte` (droit `visiteurs.rechercher`, rôle agent d'accueil). Journal : `poste.contrainte`. Côté tablette : `VaultRecord.duress` (second enveloppement du coffre). Décisions : JOURNAL_DECISIONS.md, rubrique L14d.

