# V1 — Opérations : inventaire ciblé et décisions

Inventaire limité aux composants nécessaires. Les trois espaces existent déjà ; ce chantier consolide, il ne reconstruit pas.

| Espace | Existant (dépôt) | Constat | Action |
|---|---|---|---|
| Administration › Opérations | Menu « Opérations » : Postes et tablettes, Groupes et cars ; aucune vue d'ensemble | Pas de vue globale des accès, présences, incidents ni journaux | Nouvelle page **lecture seule** `/administration/operations` (`pilotage.consulter`) composant `Pilotage`, `SupervisionCentre` et la vue globale `OperationsOverview` ; renvois vers Pilotage, Postes, Journal |
| Superviseur › Pilotage | Tableau de bord, alertes, présents, mouvements, accès et badges, attendus, litiges, groupes et cars, évacuation (hors ligne sécurisé), journal ; centre de supervision | Tout est rattaché à UNE activité ; `Pilotage::event()` excluait le contexte permanent même demandé explicitement → les passages des titulaires permanents n'étaient jamais consultables | Le contexte permanent peut être choisi explicitement (jamais par défaut) ; bloc « Le site, toutes activités confondues » sur le tableau de bord |
| Agent | Mon espace, Poste d'accueil (7 étapes), Groupes et cars, Contrôle sur site, Sécurité ; barre d'état | La barre d'état distingue déjà réseau (En ligne / Hors ligne), dernière synchronisation, « liste à jour » / « liste ancienne », opérations en attente | Non modifié (déjà conforme) ; couvert par les tests e2e existants |

## Décisions
- Aucune table, aucun moteur, aucune règle : `OperationsOverview` est un calcul en lecture sur `access_events`, `refusals`, `alerts`, `checkpoints`, `devices` et `Presence` (présents déduits des entrées et sorties, jamais d'un badge).
- Fenêtre « 24 h » : 24 tranches horaires alignées sur les heures pleines ; entrées et sorties corrigées non comptées ; un contrôle intérieur n'est jamais une seconde entrée.
- Les noms de personnes ne sont communiqués qu'aux comptes qui consultent les présences (même règle que le Pilotage) ; les droits sont vérifiés côté serveur, les boutons masqués ne sont pas la barrière.
- Clôtures, décisions de litige et alertes restent dans le Pilotage : l'écran d'administration n'en simule aucune.

## Qualification (revue de #108)
- **Présences du site** : le total compte des **identités physiques distinctes** (personne canonique, dernier mouvement confirmé et non corrigé, tous contextes confondus). Les compteurs par contexte restent affichés comme indicateurs distincts, jamais additionnés ; l'écart (`multi_contextes`) est signalé. Une sortie ou une entrée corrigée n'est pas comptée.
- **Postes** : « administrativement actif » (état du poste) ≠ appareil « synchronisé récemment » (preuve de connexion, délai existant de `Pilotage`) ≠ appareil « silencieux ». Aucun libellé « En ligne » sans preuve.
- **Périmètres** : indicateurs du **site entier** (indépendants de l'activité choisie, titulaires permanents inclus) séparés du **détail par activité** ; chaque panneau précise son périmètre. Les permissions sur les données personnelles sont inchangées.

## Limites (non traitées, signalées)
- Pas de graphique de comparaison entre activités ni d'export propre à Opérations.
- La carte « Activité en cours » consulte une seule activité à la fois (sélecteur), la vue globale couvre le site entier.
- Les parcours de l'agent (accueil en sept étapes, groupes et cars, contrôle) n'ont pas été remaniés.
