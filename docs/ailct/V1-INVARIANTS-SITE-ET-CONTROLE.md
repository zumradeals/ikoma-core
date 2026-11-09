# V1 — Invariants du site, des postes, des mouvements, des présences, des habilitations et des supports

Statut : **contrat validé par le propriétaire du projet** (plan V1 consolidé et corrections contractuelles). Il complète et, lorsqu'il est dit, **remplace** des contrats antérieurs (section 9). Chaque lot V1 précise ce qu'il réalise ; cette page fixe ce qui doit rester vrai.

## 1. Mission
IKOMA ACCESS gère les identités, les habilitations, les badges, les contrôles d'accès et la traçabilité des mouvements. Il ne remplace pas les procédures militaires de contrôle des véhicules ni de sécurité physique. Le module véhicules (S09) reste une **information d'accueil** rattachée à une participation, sans extension.

## 2. Site, zones, postes, tablettes
1. Le site existe indépendamment de toute activité. Il possède des zones, des postes de contrôle, des règles d'accès et des équipements affectés.
2. Les **cinq postes** (Poste 1, Poste 2, Poste 3, Poste 4, Point 5 « La Soute ») sont des objets **permanents** du site. Un poste se désactive, il ne se supprime pas. Son code est immuable.
3. Un **poste** est un emplacement ou une fonction de contrôle physique ; une **tablette** est un équipement. Ces deux objets sont distincts.
4. **Un poste peut avoir plusieurs tablettes affectées.** Aucune contrainte « une seule tablette active par poste » n'est posée (aucune nécessité métier démontrée).
5. Chaque tablette a une identité propre et un **historique d'affectation** (poste, début, fin, auteur). Une tablette n'est affectée qu'à un poste à la fois, ou à aucun (tablette mobile : appel nominal, remise de badges de groupe, F06).
6. La **concurrence est maîtrisée côté serveur** : verrou de la tablette lors d'une affectation, une seule affectation ouverte par tablette garantie par la base, version du poste contrôlée à chaque modification (refus 409 si la fiche a changé entre-temps).
7. Les zones d'un poste (relation poste–zone) sont portées par le **poste** : toutes ses tablettes jugent les mêmes zones.
8. La configuration d'un poste est une **donnée** : capacités, sens obligatoire, état actif. Le serveur **refuse** toute configuration qui active une opération non implémentée ou incohérente (capacités du catalogue : `accueil`, `scan`, `sortie` ; sens obligatoire seulement avec `scan`). Les droits de l'agent (rôle et permissions) restent exigés en plus de la capacité du poste.
9. Chaque poste peut disposer d'une capacité de lecture de badges, **activée par configuration** ; aucun scan individuel n'est imposé à tous les postes. Configuration par défaut = contrat V1 antérieur : Poste 2 `accueil`, `scan`, `sortie` ; Point 5 `scan` avec sens obligatoire ; Postes 1, 3 et 4 sans opération. Le Poste 1 n'impose aucune gestion de véhicules ; une éventuelle distribution de badges relève de l'organisation de l'AILCT.
10. Les parcours (terrestre, lagunaire) ne sont pas une source d'autorisation ; l'ordre graphique des postes ne décide d'aucun droit.
11. Toute modification de poste, de zone associée ou d'affectation est **tracée** (journal d'audit).

## 3. Décisions de contrôle et mouvements
1. Un scan produit une **décision de contrôle traçable** (valide ou refusée, motif, horodatage, poste, agent).
2. Une entrée, une sortie ou un passage intérieur n'est enregistré que lorsque l'**opération correspondante est autorisée** au poste.
3. Une entrée déjà active **ne peut pas être dupliquée**.
4. Une sortie sans entrée active déclenche une **anomalie** tracée, jamais une correction silencieuse.
5. La soute reste un contrôle orienté (Entrée / Sortie choisi avant le scan) qui n'est ni une entrée ni une sortie du site (S10).
*Réalisation : lot « contrôle » (PR 2).*

## 4. Présence et zones
1. La **présence globale** est celle du site ; elle est distincte de la **dernière zone connue**.
2. Les mouvements intérieurs ne modifient pas la présence globale.
3. Une dernière zone connue n'est pas une localisation actuelle certaine.
4. Le tableau de bord distingue les **présences confirmées** des **localisations inconnues ou non déterminables**.
*Réalisation : PR 2 (présence) et PR 5 (tableau de bord).*

## 5. Activités
Stages, formations, événements et visites organisées sont des contextes temporaires indépendants. Ils utilisent le site sans en devenir propriétaires. Plus aucun faux événement ne représente le fonctionnement permanent. *Réalisation : PR 2 et PR 3.*

## 6. Identités, catégories, habilitations
Une identité par personne, sans fusion automatique sur ressemblance de noms. Identité, statut institutionnel, catégorie, habilitation, participation et support sont des notions distinctes. Deux moteurs d'habilitation (permanents, participants) ; refus par défaut ; autorité du serveur. *Réalisation : PR 2 et PR 3.*

## 7. Supports physiques (badges et cartes)
1. Un support physique appartient au **stock permanent du site**.
2. Une **attribution peut couvrir plusieurs participations simultanées** de la même personne.
3. La **clôture d'une activité retire uniquement les droits qui en découlent** ; le badge **reste attribué** si une autre participation active nécessite son utilisation.
4. La **disponibilité** d'un badge exige sa **restitution physique et sa vérification**.
5. Distinction claire entre badge nominatif et support réutilisable associé temporairement à une personne.
*Réalisation : PR 4.*

## 8. Navigation administrative
Sept entrées, sans accordéons : Tableau de bord ; Site et accès ; Personnes et habilitations ; Activités ; Badges et cartes ; Opérations ; Administration. *Réalisation : PR 5.* L'écran « Site et accès » fonctionne **sans plan géographique** et ne présente que les données réellement disponibles.

## 9. Suppressions de contrats (supersessions)
- ADR-TITULAIRES-PERMANENTS : la variante C1 (contexte permanent) est remplacée par un fonctionnement hors événement.
- AILCT-BADGE-001 : la règle « la catégorie d'événement décide des zones » est conservée ; les zones deviennent des zones du site.
- S02 : les capacités par poste passent du code aux **données**, avec la même configuration par défaut ; le contrat de refus par défaut est inchangé.
- Contrat « Navigation unifiée / Badges et cartes » (PR #101) : remplacé par les sept entrées (PR 5).
- Contrat S06 : la correspondance poste–zone est portée par le poste (checkpoint) et non plus par la tablette.

## 10. Ce qui ne change pas
Journaux d'audit, registres d'impression et cartes en ajout seul ; politique de fonctionnement hors réseau ; permissions par rôle ; rôle requis en plus du poste ; F01 à F09 ; clôture VIP réservée au superviseur ; restitution distincte de la sortie.


## Addendum — navigation réalisée et distinction sortie / restitution (PR 1)
- Les sept menus sont réalisés dès la PR 1 (à plat, sans accordéon) ; les pages existantes s'y rangent en sous-menus. Le contrat de navigation de la PR #101 (cinq rubriques, accordéons) est remplacé.
- La sortie du site et la restitution du badge restent deux opérations distinctes. Elles partagent aujourd'hui la capacité de poste « sortie » ; leur séparation en deux capacités est une décision ouverte (voir le journal des décisions).

## Addendum — identités et titulaires permanents (lot P2)
- `persons` est le référentiel canonique de l'identité (nom, prénom, organisation). `permanent_holders` est un rattachement permanent d'une personne, jamais une seconde identité : une personne a au plus un profil permanent (index unique) et un profil se rattache toujours à une personne existante.
- Deux parcours : (A) une personne existante, choisie explicitement, reçoit un profil permanent (`person_id`) sans création d'identité ; (B) une nouvelle personne est créée avec son profil dans la même transaction. Aucun rapprochement ni fusion automatique sur le nom ; un nom déjà connu (profil permanent ou non) exige une confirmation explicite.
- Les colonnes de nom du profil permanent sont un miroir de `persons` : un nom ne s'écrit plus sur le profil, il se corrige dans la personne puis se propage aux profils ACTIFS. Un profil clos garde le nom du moment de sa clôture ; participations, journaux, cartes et badges déjà émis ne sont pas réécrits. La correction est journalisée (`identite.corrigee`, champs seulement).
- Imports : un import de titulaires n'identifie jamais une personne par son nom ; une ligne dont le nom existe déjà est signalée « homonyme » et n'est créée (comme nouvelle identité distincte) que sur confirmation explicite. Aucune règle de rapprochement n'est inventée.
- Habilitations : les deux moteurs (permanentes, liées aux activités) restent distincts et inchangés ; ajouter un profil permanent ne crée ni ne modifie aucune habilitation.
