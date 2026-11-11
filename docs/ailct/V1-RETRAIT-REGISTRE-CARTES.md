# Retrait complet du registre technique des cartes permanentes (plan, à exécuter par étapes)

Statut : **projet de travail** (dette du lot 6). Décision du dirigeant : traiter la dette maintenant. Installation de test, aucune donnée réelle.

## Constat (code lu)
Le registre technique (événement `is_permanent`) héberge, pour chaque carte permanente : un badge (`badges.event_id`), un lot d'un exemplaire (`badge_batches`), une catégorie d'événement (`categories`, avec `institutional_code`), une participation (`visitors.holder_id`), la fiche carte (`holder_cards.event_id`, clé composite vers `badges(id, event_id)`), l'ordre d'émission (`badge_issuances.event_id`), et les tirages (`badge_print_runs.event_id`). Les badges sont aussi « inter-activités » (`badges_event_guard`, `badge_transfers`) : leur `event_id` est le rattachement COURANT. Dix fichiers lisent `holder_cards` ; une cinquantaine filtrent `events.is_permanent`.

## Cible
Une carte permanente appartient au **site** : badge sans événement, sans lot, sans catégorie d'événement, sans participation ; la fiche carte pointe directement le badge (clé simple). Plus aucun événement technique, plus de colonne `is_permanent`.

## Étapes (une PR chacune, CI verte avant la suivante)
1. **Schéma additif** : `badges.event_id`, `badges.badge_batch_id`, `badges.category_id`, `holder_cards.event_id`, `badge_issuances.event_id`, `badge_print_runs.event_id`, `retired_badge_identifiers.event_id` deviennent facultatifs ; clé simple `holder_cards.badge_id → badges.id` ; contrainte « un badge du site n'a ni lot ni catégorie ni participation » ; garde `badges_event_guard` adaptée (un badge du site ne devient jamais un badge d'activité). Rien n'est encore utilisé.
2. **Émission** : `BadgeLots::issueSiteCard` (numérotation `B-…` du site sous verrou dédié) ; `HolderCards::issue`/remplacement/annulation sans registre ; registre des émissions et tirages sans événement. Les anciennes cartes du registre restent lisibles.
3. **Lecteurs** : `SupportRegistry`, `BadgeRegistry`, `BadgesCartesSummary`, `AdminOverview`, `OperationsOverview`, `SupportPrinting`, `LabelSheets`, `CardModels`, `PermanentAccess::resolveCard`, `CardLifecycle`, `DemoLab`, jeux d'essai : jointures à gauche, type « carte permanente » déduit de la fiche carte et non de l'événement.
4. **Retrait** : migration (test sans donnée) qui rattache les cartes existantes au site puis supprime l'événement technique, ses catégories, lots et participations ; suppression de `createPermanentContext`, de `events.is_permanent`, des filtres `where('is_permanent', false)` et des gardes associées ; fonctions de remise à zéro de démonstration mises à jour.

## Risques et parades
- Émission et impression des cartes (cœur du produit) : chaque étape garde l'ancien chemin lisible jusqu'à la suivante ; tests Pest existants adaptés, jamais assouplis.
- Gardes de base (`ikoma_holder_card_guard`, `ikoma_badge_event_guard`, `retired_badge_guard`, journal d'émission immuable) : la migration de l'étape 4 les désactive le temps de la reprise, puis les réactive (même schéma que la remise à zéro de démonstration).
- Aucun déploiement entre les étapes sans test sur le serveur d'essai.

## Critères de fin
Plus aucune référence à `createPermanentContext` ni `is_permanent` dans `app/` ; émission, remplacement, annulation, impression et contrôle d'une carte fonctionnent sans événement ; CI verte ; recette sur le serveur d'essai.

## Avancement
- Étape 1 (schéma additif) : migration `2026_12_11_000000_allow_site_badges.php` + `SiteBadgesSchemaTest` (badge du site accepté ; catégorie, personne imprimée ou réservation refusées ; jamais de passage site ↔ activité ; identifiants retirés du site non réattribuables). Garde `ikoma_retired_badge_guard` réécrit avec `IS NOT DISTINCT FROM`.
- Étapes 2 et 3 (émission et lecteurs, regroupées dans une seule PR : sans cela, les nouvelles cartes disparaîtraient des écrans) :
  - `BadgeLots::issueSiteCard` : badge du site sans événement, lot, catégorie ni participation ; numérotation `B-nnn` sous verrou dédié `badge-codes:site`, au-delà des codes du site, des identifiants retirés du site **et** des cartes de l'ancien registre (aucun doublon au rattachement de l'étape 4) ; index unique `badges_site_code` (migration `2026_12_11_000100`).
  - `HolderCards::issue` (donc émission, remplacement, `prepareAll`) n'appelle plus `createPermanentContext` ni ne crée catégorie/participation ; `holder_cards.event_id` et `badge_issuances.event_id` restent vides ; l'exposition du lot à l'impression ne vise plus que les badges qui ont un lot (`recordPrint`, `SupportPrinting`).
  - Lecteurs : `PermanentAccess::resolveCard` (une carte permanente = un badge lié à une fiche `holder_cards`, quel que soit son rattachement ; ne dépend plus du registre) et `snapshot` ; `SupportRegistry` (jointure à gauche, rattachement « Site », type déduit de la fiche carte) ; `SupportPrinting` (`event_id` vide admis).
  - Lecteurs vérifiés sans changement : `BadgeRegistry`, `BadgesCartesSummary`, `AdminOverview` (jointures internes avec `is_permanent = false` : les badges du site n'y entrent pas, voulu), `CardLifecycle` (jointure fiche carte → badge), contrôle `BadgeService` et `SiteControl` (les cartes passent par `resolveCard`).
  - Tests : `SiteCardIssueTest` (4 scénarios) ; `HolderCardsTest` et `PermanentAccessTest` adaptés (carte du site, jamais assouplis).
  - **Reste (étape 4)** : rattacher les anciennes cartes au site, supprimer l'événement technique, `createPermanentContext`, `events.is_permanent`, filtres associés, `DemoLab` et jeux d'essai.
- Étape 4 (retrait du conteneur, décision du dirigeant : l'ancien registre est **effacé**, installation d'essai sans donnée réelle) :
  - Migration `2026_12_12_000000_remove_card_registry.php` (non réversible) : `LegacyCardRegistry::detach` rattache les cartes existantes au site (badge sans événement ni lot, catégorie, participation ; fiche carte, émissions, tirages et identifiants retirés sans événement), puis efface le reste de l'ancien événement protégé (participations, passages, lots, catégories, l'événement) en suivant les clés étrangères lues dans le catalogue ; protections « ajout seul » suspendues table par table puis rétablies dans la même transaction.
  - Schéma : suppression de `events.is_permanent` (index, déclencheurs de protection et de nature « permanent » compris) ; `events.kind` n'admet plus « permanent » ; `holder_cards.event_id` et sa clé composite disparaissent (garde-fou `ikoma_holder_card_guard` inchangé sinon).
  - Code : plus de `createPermanentContext`, `permanentContext`, route « contexte permanent » ni filtre `is_permanent` (une cinquantaine de retraits) ; la supervision générale compte la présence du site à partir des passages des titulaires (`SiteMovements`) et une personne présente sur le site et en activité n'est comptée qu'une fois ; la numérotation `B-nnn` du site ne regarde plus que le site.
  - Tests : `LegacyCardRegistryTest` (compte propriétaire, transaction annulée) ; tests existants adaptés (contexte permanent retiré, supervision par passages du site).
  - **Critères de fin atteints** : plus aucune référence à `createPermanentContext` ni `is_permanent` dans `app/` hors la migration et `LegacyCardRegistry`. Reste : recette sur le serveur d'essai après redéploiement.
