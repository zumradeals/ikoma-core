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

## Questions pour le dirigeant
- Les habilitations du personnel permanent se décident-elles **par zone du site** (comme aujourd'hui par zone d'activité) ou par catégorie institutionnelle pour tout le site ?
- Les mouvements du personnel permanent doivent-ils apparaître dans le même journal que ceux des visiteurs (recommandé : oui, un seul journal, avec le contexte « site » ou « activité » indiqué) ?
- Priorité : le mode site avant les maquettes des étapes 2 à 7 du poste d'accueil (choix déjà indiqué), mais faut-il un enrôlement « de secours » dès le lot 4 ?
