# Inventaire des écrans qui demandent encore de choisir un événement

Statut : **projet de travail**, établi à la demande du dirigeant (priorité). Lecture du code (menus, routes, contrôleurs, pages) ; aucun écran n'a été essayé sur le serveur d'essai pour cet inventaire.
Principe retenu par le dirigeant : « le SITE est permanent (personnel, cartes, zones, postes, tablettes, passages) ; les ACTIVITÉS portent les visiteurs, listes, badges temporaires, groupes ». Un écran qui configure le site ne doit jamais demander un événement.

## Verdict par écran

Légende : **A** légitime (la chose gérée appartient à une activité) · **B** à corriger (la chose gérée est du site, ou l'événement masque le site) · **C** mixte (une partie site existe déjà, l'événement la précède ou la noie).

| Menu | Écran (route) | Où l'événement est demandé | Ce que l'écran gère | Verdict | Proposition |
|---|---|---|---|---|---|
| Site et accès | Vue d'ensemble, Postes de contrôle (`/administration/site`) | jamais | postes, tablettes, zones **du site** | — | **Corrigé dans ce lot** : le résumé et les compteurs comptaient seulement les zones d'activité (« Zones (0) » malgré une zone du site créée) et « Ajouter une zone » renvoyait vers l'écran qui demande un événement. Le résumé liste maintenant les zones du site, crée une zone sans événement, et indique à part le nombre de zones d'activité. |
| Site et accès | Onglet Zones | non pour les zones du site ; oui via « Gérer les zones des activités » | zones du site + lien vers zones d'activité | A | Titre et compteur alignés sur les zones du site (fait) ; le lien est renommé « Gérer les zones des activités ». |
| Site et accès | Fiche d'un poste (association de zones) | non | zones du site **et** zones d'activité d'un poste | C | Deux listes distinctes (site, activités). À terme, ne proposer que les zones du site et ranger les zones d'activité sous « avancé » (cf. Accès et zones ci-dessous). |
| (via Site et accès) | Zones (`/administration/zones`) | **oui, obligatoire** (créer une zone = choisir un événement) | zones d'**activité** | A | Garder pour les zones propres à une activité, mais ne plus l'atteindre comme « le » moyen de créer une zone : renommer l'écran « Zones des activités ». |
| (via Site et accès) | Accès et zones (`/administration/acces`) | **oui, en tête de page** | correspondances poste–zone **d'une activité** et habilitations de ses participants | C | Doublon fonctionnel du panneau « Postes de contrôle » pour la partie poste–zone. Fusionner la partie poste–zone dans Site et accès (zones du site) ; garder seulement les habilitations des **participants d'une activité** ici, sous l'activité. |
| Personnes et habilitations | Habilitations par zone (`/administration/habilitations`) | **oui, en tête de page** (« Événement ») alors que la première section est « Personnel permanent : accès au site » | habilitations du personnel permanent : site (sans événement) puis activités | C | Déplacer le choix d'événement dans la section « Accès liés aux activités », sous la section site. Aucune règle ne change. |
| Personnes et habilitations | Catégories institutionnelles (`/administration/categories-institutionnelles`) | oui, mais seulement pour la section « Association facultative aux catégories d'événement » | catégories de personnel (site), liaison facultative avec les catégories d'une activité | C | Le choix d'événement est déjà limité à la dernière section ; vérifier qu'il n'apparaît pas avant les catégories elles-mêmes. Faible urgence. |
| Personnes et habilitations | Personnes / fiche d'une personne (`/administration/personnes`) | oui, dans « Inscrire à une activité » et « Habilitation permanente par activité » | participations d'activité, habilitations d'activité d'un profil permanent | A / C | L'inscription est légitime. Pour une personne permanente, afficher d'abord les accès **du site** (liés à Habilitations par zone) avant les accès par activité. |
| Personnes et habilitations | Personnel permanent (`/administration/titulaires`) | non | titulaires, cartes (site depuis le retrait du registre) | — | Rien à changer. |
| Personnes et habilitations | Fiches des invités (`/administration/invites`) | oui | invités d'une activité | A | Légitime. |
| Personnes et habilitations | Imports de listes (`/administration/imports`) | oui, obligatoire | listes d'une activité | A | Légitime. |
| Activités | Registre des activités (`/administration/evenements`) | c'est l'activité elle-même | activités | A | Légitime. |
| Activités | Catégories (`/administration/categories`) | oui | catégories de visiteurs d'une activité (avec leurs zones) | A | Légitime. |
| Badges et cartes | Badges, lots, atelier, préparation, mise à disposition (`/administration/badges…`) | oui (lot ou activité de rattachement) | badges temporaires d'une activité | A | Légitime. Les **cartes** permanentes n'y figurent plus (badges du site). |
| Opérations | Supervision (`/administration/operations`) | oui, facultatif (vue par activité) ; la vue globale n'en demande pas | présences et indicateurs du site entier + détail d'une activité | A | Déjà conforme : le site est compté sans événement. |
| Opérations | Postes et tablettes (`/administration/postes`) | oui, mais seulement pour cocher les **activités autorisées** d'une tablette | tablettes du site, activités du jour autorisées | A | Conforme depuis les lots 5 et 6 (plus de choix d'activité courante côté administration). |
| Opérations | Groupes et cars (`/administration/groupes`) | oui, obligatoire (« Événement ») | groupes d'une activité | A | Légitime (groupes liés à une activité). Lisibilité de l'écran : reportée par le dirigeant. |
| Pilotage (superviseur) | Tableau, mouvements, alertes (`/pilotage…`) | oui pour le détail d'une activité ; le site (présences, alertes, mouvements du site) n'en demande pas | supervision | A | Conforme. |
| Administration | Données et conservation, Journal | filtre ou périmètre par événement | exploitation | A | Légitime (périmètre de purge ou de lecture). |
| Poste d'accueil (tablette) | Choix de l'activité du jour | oui, par l'agent, parmi les activités autorisées ; « mode site » sans activité | accueil des visiteurs | A | Conforme (lots 5 et 6). |

## Constat
1. **Un seul écran était réellement en défaut côté zones** : le résumé de Site et accès. C'est corrigé dans ce lot.
2. **Deux écrans à corriger ensuite (priorité 1)**, sans changement de règle métier :
   - *Habilitations par zone* : déplacer le sélecteur d'événement sous la section « accès au site ».
   - *Accès et zones* : ne plus présenter la correspondance poste–zone comme dépendant d'un événement ; la partie site existe déjà dans Site et accès.
3. **Un écran à renommer (priorité 2)** : « Zones » → « Zones des activités », pour lever l'ambiguïté avec les zones du site.
4. **Fiche d'une personne permanente (priorité 2)** : montrer les accès du site avant les accès par activité.
5. Tout le reste est légitimement lié à une activité (visiteurs, listes, badges temporaires, groupes) ou ne demande pas d'événement.

## Points à décider par le dirigeant
- **Zones d'activité** : les garde-t-on comme objet distinct (cas d'une zone qui n'existe que pour une activité), ou toute zone devient-elle une zone du site réutilisée par les activités ? La seconde option est plus simple pour l'agent mais change le modèle (migration des zones existantes). Recommandation : garder les deux le temps de la recette, puis trancher.
- **Écran Accès et zones** : le fusionner dans Site et accès (recommandé), ou le garder pour les seuls participants d'une activité.

## Non vérifié
Le comportement sur le serveur d'essai (aucune capture ni essai manuel) ; la liste des routes appelées depuis des liens internes qui préremplissent un événement ; les messages d'erreur « événement requis » côté serveur.
