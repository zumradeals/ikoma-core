# Tablette de site : contrôle du personnel permanent sans activité (analyse et plan par lots, à valider)

Statut : **projet de travail**. Décision du dirigeant : AILCT est un site physique permanent ; il surveille son site sans dépendre d'un événement. Une tablette doit pouvoir être enrôlée et contrôler le personnel permanent sans aucune activité ; l'accueil des visiteurs n'apparaît que lorsqu'une activité est choisie. Rien n'est implémenté par ce document.

## Vision déjà adoptée (rappel)
`V1-INVARIANTS-SITE-ET-CONTROLE.md` : §2 « le site existe indépendamment de toute activité » ; §5 « plus aucun faux événement ne représente le fonctionnement permanent » ; ADR titulaires permanents : « la variante C1 (contexte permanent) est remplacée par un fonctionnement hors événement ». Les postes, tablettes et affectations sont déjà au niveau du site ; l'étape « tablette permanente » (PR #119 à #121) a rendu la création d'une tablette indépendante d'une activité.

## Ce qui reste lié à une activité dans le code (constats)
| # | Constat | Où | Conséquence |
|---|---|---|---|
| S1 | Une zone appartient à un événement (`zones.event_id`, clé composite `(id, event_id)` reprise par `category_zone`, `holder_entitlements`, `participation_entitlements`, `checkpoint_zones`). | migrations `business_schema`, `permanent_access`, `site_and_checkpoints` | Pas de zone du site sans événement. |
| S2 | Les habilitations permanentes sont `(titulaire, événement, zone)` (`holder_entitlements.event_id` obligatoire). | `PermanentAccess::decide`, `HolderEntitlements` | Un titulaire n'est jugé qu'au titre d'une activité. |
| S3 | Chaque mouvement est un `access_events` avec `event_id` obligatoire ; une carte permanente passe par une participation (`visitors.holder_id`) dans l'événement du poste. | `SiteControl::permanentControl`, `PermanentAccess::participation` | Pas de mouvement du site sans événement. |
| S4 | La copie de la tablette, le contrôle, la présence et les alertes sont calculés pour `devices.event_id`. | `ListSnapshot`, `SiteControl`, `Presence`, `ControlAlerts` | Une tablette sans activité n'a rien à charger. |
| S5 | Un « contexte permanent » (événement `is_permanent`) est créé à la demande pour héberger les cartes permanentes : c'est le faux événement que la vision supprime. | `PermanentHolders::createPermanentContext` | À retirer en fin de chantier, avec reprise des données. |

Conséquence : la règle actuelle « pas d'activité, pas d'enrôlement » n'est pas la vision ; c'est la limite du moteur (S1 à S4).

## Modèle cible proposé
- **Zones du site** : une zone peut appartenir au site (sans événement) ; les zones d'activité restent. Les clés composites passent sur un identifiant de contexte (`site` ou `activité`).
- **Habilitations permanentes liées au site** : `(titulaire, zone du site)`, sans événement ; les habilitations de participation restent liées aux activités. Les deux moteurs restent distincts (invariant déjà adopté).
- **Mouvements du site** : un mouvement du site peut exister sans événement (titulaire, point de contrôle, sens, heure, décision) ; la présence est calculée par identité sur le site, avec ou sans activité.
- **Mode de la tablette** : sans activité = « mode site » (cartes permanentes : scan, sortie, présence ; refus par défaut des badges de visiteurs) ; avec activité = mode actuel (accueil, contrôle des visiteurs) **plus** le mode site. Le serveur reste l'autorité ; hors ligne, la copie contient les habilitations permanentes et les cartes.

## Lots proposés (une PR chacun, sans casser l'existant)
1. **Zones du site** : zones sans événement, administration « Site et accès » (création, rattachement aux postes).
2. **Habilitations permanentes du site** : nouvelle table, reprise des habilitations existantes, écran Personnes et habilitations.
3. **Mouvements et présence du site** : mouvements sans événement, présence par identité, tableau de bord (présences confirmées du site).
4. **Contrôle en mode site** : `SiteControl`, copie hors ligne et file d'opérations sans activité ; refus clairs.
5. **Tablette sans activité** : enrôlement sans activité, interface de l'agent (contrôle sur site seul), retrait de la règle « aucune activité ».
6. **Retrait du contexte permanent** : migration des cartes et habilitations, suppression de `createPermanentContext`.

## Décisions du dirigeant (questions tranchées : recommandations approuvées)
- Les habilitations du personnel permanent se décident **par zone du site** (même mécanisme que par zone d'activité, avec les catégories et les politiques de zone existantes).
- Les mouvements du personnel permanent vont dans le **même journal** que ceux des visiteurs, avec le contexte « site » ou « activité » indiqué.
- **Pas d'enrôlement de secours** avec un faux événement : une tablette s'enrôle sans activité au lot 5 (mode site) ; d'ici là, une vraie activité est nécessaire.

## Avancement
- Lot 1 (zones du site) : `zones.event_id` facultatif ; création et renommage de zones du site dans « Site et accès > Zones » (`postes.gerer`) ; nom unique dans le site ; les zones d'activité sont inchangées. Le rattachement des zones du site aux postes de contrôle vient avec le lot 2 (habilitations), qui en a besoin pour juger une zone.
- Lot 2 (habilitations du site) : table `holder_site_entitlements` (titulaire, zone du site, période, sans événement ; non modifiable, retrait motivé, jamais supprimée ; seule une zone sans événement est acceptée, garde-fou en base) et table `checkpoint_site_zones` (zones du site gardées par un poste). Écran « Qui peut entrer où ? » : section « Personnel permanent : accès au site » (droits `listes.importer` + `zones.gerer`, comme les accès d'activité) ; fiche d'un poste dans « Site et accès » : « Zones du site gardées » (droit `postes.gerer`). Les habilitations d'activité (`holder_entitlements`) ne sont ni lues ni déplacées : les deux moteurs restent distincts. Le jugement d'une carte au contrôle (politique de zone du site, hors ligne) vient au lot 4 ; rien n'est donc encore refusé ou accepté sur la base de ces habilitations.

## Lot 3 : mouvements et présence du site (analyse, à valider)

Statut : **projet de travail**, rien n'est implémenté.

### Constats (code lu)
| # | Constat | Où |
|---|---|---|
| M1 | `access_events` : `event_id` obligatoire ; le sujet est une participation (`visitor_id`) liée à l'événement par clé composite ; une carte permanente n'a de mouvement que par une participation (`visitors.holder_id`) dans l'événement du poste. | `create_business_schema`, `SiteControl::permanentControl` |
| M2 | Le journal est en ajout seul (le compte applicatif n'a pas UPDATE) ; une erreur se corrige par un événement `correction` (`corrige_id`, une seule fois, garanti par index unique). | `create_site_control`, `Presence::corriger` |
| M3 | La présence est calculée, jamais stockée : dernier événement `entree`/`sortie` non corrigé, par `visitor_id` et par événement (`Presence::count`, `Pilotage`). | `Presence`, `Pilotage` |
| M4 | Les lecteurs du journal filtrent par `event_id` ou font une jointure interne sur `visitors`/`events` (Pilotage, AdminOverview, OperationsOverview, Maintenance, DataRetention, SupervisorClosure) : un mouvement sans événement leur serait invisible, sauf deux comptages horaires d'OperationsOverview qui ne filtrent pas. | `app/Services/*` |
| M5 | Rétention et effacement partent de l'événement (`DataRetention` supprime par `event_id`) : un mouvement du site n'aurait aucune règle de conservation. | `DataRetention`, `DataLifecycle` |

### Proposition (même journal, contexte « site »)
1. **Schéma** (migration additive) : `access_events.event_id` devient facultatif ; nouvelle colonne `holder_id` (titulaire permanent, FK simple) ; contrainte : un mouvement est SOIT d'activité (`event_id` + `visitor_id`, `holder_id` vide), SOIT du site (`event_id` vide, `holder_id` renseigné, `visitor_id` vide, type `entree`, `sortie`, `controle` ou `correction`). Index de présence du site `(holder_id, occurred_at DESC)`. Les lignes existantes ne changent pas.
2. **Service `SiteMovements`** : enregistre un mouvement du site (titulaire, point, sens, décision, heure) ; aucune lecture de `visitors`. La tablette ne l'appelle qu'au lot 4 : au lot 3 il n'est exercé que par les tests et l'écran de lecture.
3. **Présence du site** : `Presence::siteCount()` et `Presence::siteList()` par titulaire (dernier mouvement non corrigé), sans compteur stocké ; correction d'un mouvement du site par le même mécanisme `correction`.
4. **Lecture** : encart « Présents sur le site » (nombre, liste par zone/point, dernière heure) dans « Site et accès » pour le droit `presences.consulter`, plus la mention du contexte (« Site » ou nom de l'activité) dans les journaux qui listent des mouvements. Les tableaux de bord d'activité ne changent pas.
5. **Garde-fous** : remise à zéro de démonstration (`DELETE`/`TRUNCATE` déjà par table ; vérifier la liste), route inventaire, `DataLifecycle` (un titulaire dont des mouvements du site existent n'est pas effaçable).

### Ce que le lot 3 ne fait pas
Pas de contrôle à l'entrée (lot 4), pas d'alertes du site (`alerts.event_id` reste obligatoire : lot 4), pas de retrait du contexte permanent ni de reprise des mouvements existants (lot 6).

### Questions à trancher (dirigeant)
1. **Présence du site et visiteurs** : « Présents sur le site » compte-t-il seulement le personnel permanent (recommandé : les visiteurs restent comptés par activité), ou aussi les visiteurs présents dans une activité en cours ?
2. **Conservation** : durée de conservation des mouvements du site. Recommandation : même durée que celle déjà réglée pour les mouvements d'activité (réglage dans l'écran Administration, pas dans le code) ; sans votre accord, aucun mouvement du site n'est supprimé automatiquement.
3. **Personne présente dans deux contextes** : un titulaire entré via une activité (ancien mode) et un mouvement du site : en attendant le lot 6, chaque contexte a sa présence ; la fusion par identité se fait à la reprise du lot 6. Acceptable ?

### Critères de validation
Tests Pest : mouvement du site sans événement accepté, mouvement mixte refusé en base, présence recalculée après entrée/sortie/correction, correction unique, lecture réservée à `presences.consulter`, tableaux d'activité inchangés (mêmes chiffres qu'avant), remise à zéro de démonstration. CI complète verte.

### Décisions du dirigeant sur le lot 3 (recommandations approuvées)
1. « Présents sur le site » compte **uniquement le personnel permanent** ; les visiteurs restent comptés par activité.
2. Conservation des mouvements du site : **même durée que les mouvements d'activité**, réglée dans l'écran Administration (jamais dans le code) ; sans réglage, aucune suppression automatique.
3. Avant le lot 6, un titulaire entré via une activité (ancien mode) et un mouvement du site ont **chacun leur présence** ; la fusion par identité se fait à la reprise du lot 6.
