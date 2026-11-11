# Zones du site réutilisées par les activités (plan, à exécuter par étapes)

Statut : **projet de travail** à l'exécution. Décisions du dirigeant : (1) « toute zone devient une zone du site que les activités réutilisent » ; (2) l'écran « Accès et zones » est fusionné dans « Site et accès » ; (3) les recettes ne sont pas urgentes. Installation d'essai, aucune donnée réelle.

## Constat (code lu)
Aujourd'hui il existe deux sortes de zones : les zones **d'activité** (`zones.event_id` renseigné, créées par événement) et les zones **du site** (`event_id` vide, depuis le lot 1 de la tablette de site). Chaque sorte a ses propres tables :

| Besoin | Zones d'activité | Zones du site |
|---|---|---|
| Politique d'accès du personnel permanent | `zone_policies` | `site_zone_policies` |
| Poste qui garde la zone | `checkpoint_zones` (avec événement) | `checkpoint_site_zones` |
| Habilitation du personnel permanent | `holder_entitlements` (événement + zone) | `holder_site_entitlements` |
| Habilitation d'un participant | `participation_entitlements` | — |
| Zones autorisées à une catégorie | `category_zone` (clé composite zone + événement) | — |
| Zone d'accueil | `zones.est_accueil`, une obligatoire par événement | — |

Les lecteurs principaux : `ZoneAccess` (zones autorisées, zones jugées par un poste), `BadgeService`, `Poste/Desk`, `Poste/ListSnapshot` (copie hors ligne), `PermanentAccess`, `SiteControl`, `SiteConfiguration`, `HolderEntitlements`, `SiteEntitlements`, `ParticipationEntitlements`, `PersonRegistry`, `Pilotage`, `DataLifecycle`, `DemoLab` et les jeux d'essai. Écrans : Zones, Accès et zones, Habilitations par zone, Catégories, fiche d'une activité, Site et accès.

## Cible
- **Une zone est toujours une zone du site** : `event_id` vide partout. Une activité ne crée plus de zone : elle **choisit** parmi les zones du site, par ses catégories (`category_zone`) et par les habilitations de ses participants.
- **Le poste garde des zones du site** (`checkpoint_site_zones`) : le jugement d'un visiteur ne dépend plus d'une correspondance propre à l'événement.
- **Une seule politique** par zone (`site_zone_policies`), une seule habilitation permanente de site ; l'habilitation « pour une activité » d'une personne permanente garde son événement (la période) mais vise une zone du site.
- **Zone d'accueil** : propriété de la zone du site ; au moins une zone d'accueil doit exister au niveau du site (plus d'« Accueil » créé à chaque événement).
- **Écrans** : les zones se créent et se renomment dans Site et accès uniquement ; l'écran « Zones » disparaît ; « Accès et zones » est fusionné (correspondances poste–zone dans Site et accès, habilitations des participants dans la fiche de l'activité).

## Étapes (une PR chacune, CI verte avant la suivante ; ancien chemin lisible jusqu'à l'étape 3)
**Z1 — Schéma souple et lecteurs doubles (aucun comportement existant ne change).**
- Les clés composites (zone, événement) de `category_zone`, `checkpoint_zones`, `participation_entitlements`, `holder_entitlements` et `zone_policies` sont remplacées par une clé simple vers `zones(id)` plus un déclencheur : la zone est soit du même événement, soit une zone du site.
- Lecteurs : `ZoneAccess::judgedZones` lit les zones gardées par le poste dans les **deux** tables ; `Poste/ListSnapshot` envoie les zones d'activité **et** les zones du site utilisées (catégories, poste) ; `BadgeService`/`Desk` n'ont rien à changer (jointures par identifiant).
- L'écran des catégories propose les zones du site (en plus des zones d'activité existantes).
- Tests : un visiteur dont la catégorie ouvre une zone du site est accepté par un poste qui garde cette zone du site ; refus sinon ; copie hors ligne identique.

**Z2 — Lecteurs et validations ouverts aux zones du site (ajouté après analyse : les lecteurs doivent accepter les zones du site AVANT que les données y passent).**
- `ActivityZones` (zones propres à l'activité + zones du site) sert aux validations d'habilitation (participant et titulaire), à la correspondance poste–zone, au contrôle par zone, aux choix proposés (accès et zones, fiche personne, pilotage, tableau de bord) et à la zone d'accueil du bureau d'accueil.
- Politique d'une zone du site visée par une habilitation d'activité : celle du site (`site_zone_policies`) ; l'écran de politique d'une activité délègue au site pour ces zones ; la copie hors ligne embarque ces politiques.
- Aucune donnée ne change ; tests `ZonesDuSiteHabilitationsTest`.

**Z3 — Reprise des données (ex-Z2) (migration non réversible, testée avec le compte propriétaire comme pour le registre des cartes).**
- Chaque zone d'activité devient une zone du site ; les zones de **même nom exact** sont fusionnées en une seule (nom unique au site). Toutes les références sont redirigées : catégories, correspondances poste–zone (qui restent dans `checkpoint_zones`, repointées vers la zone du site, pour ne pas changer le jugement : le passage vers « le poste juge les zones du site que l'activité réutilise » se fait à l'étape écrans), habilitations, politiques (`zone_policies` vers `site_zone_policies`).
- Politique d'une zone fusionnée : **la plus restrictive** (permanent autorisé seulement si toutes les politiques fusionnées l'autorisaient ; entrée hors ligne idem). Sécurité par défaut.
- Zone d'accueil : la zone fusionnée est d'accueil si l'une des zones fusionnées l'était.
- Doublons créés par la fusion (même poste, même zone) dédoublonnés ; `list_revision` de chaque activité incrémentée (les tablettes rechargent leur copie).

**Z4 — Écrans (ex-Z3).**
- Création d'une activité : plus de zone « Accueil » propre ; contrôle qu'une zone d'accueil du site existe.
- Site et accès : toutes les zones (création, renommage, accueil, politique, postes qui les gardent) ; écrans Zones et Accès et zones retirés du menu et des routes ; habilitations des participants déplacées dans la fiche de l'activité ; « Habilitations par zone » ne demande l'événement que pour la section « accès liés aux activités ».
- `DemoLab`, jeux d'essai, `DataLifecycle` (suppression d'une activité ne supprime plus de zones), `DemoCleanup`.

**Z5 — Retrait (ex-Z4).** Suppression de `zones.event_id` (colonne, unicités composites), des tables `checkpoint_zones` et `zone_policies`, des filtres et du code de l'ancien chemin ; critères de fin : plus aucune requête `zones` filtrée par événement dans `app/`, CI verte, recette sur le serveur d'essai.

## Choix retenus par défaut (à contester)
1. Fusion par **nom exact** ; deux zones de noms différents restent deux zones.
2. Politique fusionnée **la plus restrictive**.
3. Pas de reprise partielle ni de retour arrière : migration unique et non réversible (installation d'essai).
4. Une activité ne peut pas avoir de zone « privée » ; si le besoin apparaît, on crée une zone du site que seules ses catégories utilisent.

## Risques et parades
- **Contrôle des visiteurs** (cœur du produit) : Z1 ajoute la lecture double avant toute migration ; les tests existants du contrôle ne sont jamais assouplis.
- **Copie hors ligne** : le contenu de la copie change (zones du site utilisées) ; la version de la copie dépend de `list_revision`, incrémentée à la reprise.
- **Fusion par nom** : peut réunir deux lieux différents portant le même nom. Parade : récapitulatif de la reprise (noms fusionnés, nombre d'activités concernées) écrit au journal d'audit.
- **Migration** : testée avec le compte propriétaire sur une base reconstituée (comme `LegacyCardRegistryTest`) ; plafond d'étapes si une boucle apparaissait.

## Avancement
- Plan écrit (ce document).
- **Z1 écrite** (migration `2026_12_13_000000_allow_site_zones_in_activities`, `ZoneAccess::siteZonesJudged`, `ListSnapshot`, catégories) : les clés composites (zone, événement) de `category_zone`, `checkpoint_zones`, `participation_entitlements` et `holder_entitlements` deviennent une clé simple plus un déclencheur « zone de l'activité ou zone du site » ; un poste juge les zones du site qu'il garde **et que l'activité réutilise** (catégorie ou habilitation de participant) ; la copie hors ligne porte ces zones et change de version quand elles changent ; l'écran des catégories propose les zones du site. Aucun comportement existant ne change tant qu'aucune catégorie ne choisit de zone du site. Tests : `ZonesDuSiteActivitesTest` (5 scénarios), Vitest (1).
- **Z2 écrite** : `ActivityZones`, validations et lecteurs ouverts aux zones du site (habilitations, politiques, contrôle par zone, accueil, choix des écrans, copie hors ligne). Les étapes suivantes sont renumérotées : Z3 reprise des données, Z4 écrans, Z5 retrait.
- **Z3 écrite** (migration `2026_12_14_000000_move_activity_zones_to_site`, non réversible) : toute zone d'activité devient une zone du site, les zones de même nom exact sont fusionnées (la zone du site déjà existante, sinon la plus ancienne, est retenue) ; catégories, correspondances poste–zone et habilitations sont redirigées sans doublon ; politique la plus restrictive (zone sans politique = refus) dans `site_zone_policies`, `zone_policies` vidée ; accueil si l'une des zones l'était ; `list_revision` incrémentée ; récapitulatif au journal d'audit (`zones.reprise_vers_site`). Test : `ZonesRepriseTest` (compte propriétaire, transaction annulée). Le code de création de zones d'activité (écran Zones, « Accueil » de chaque activité) reste jusqu'à Z4.
- **Z4a écrite** : l'écran « Zones » et son contrôleur sont retirés (`/administration/zones` redirige vers Site et accès) ; la zone d'accueil se règle sur une zone du site (case « Zone d'accueil », au moins une doit rester) ; une nouvelle activité et l'installation ne créent plus de zone « Accueil » propre mais garantissent une zone d'accueil du site. Reste pour Z4b : habilitations des participants dans la fiche de l'activité (écran « Accès et zones »), sélecteur de « Habilitations par zone », `DemoLab`, jeux d'essai (`DeskFixtures`, `HostFixtures`, `PermanentFixtures`), `DemoSeeder`, `DataLifecycle`.
