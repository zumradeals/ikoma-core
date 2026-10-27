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
