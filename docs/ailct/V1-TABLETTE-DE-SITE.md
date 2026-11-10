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
