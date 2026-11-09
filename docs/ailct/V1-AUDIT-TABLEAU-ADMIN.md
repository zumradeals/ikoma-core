# V1 — Tableau de bord général de l'administrateur : inventaire et décisions

## Existant conservé
La page « Préparer l'événement » (étapes, tuiles, « À surveiller », dernières actions) est conservée telle quelle sous la vue générale : elle sert à la préparation d'UNE activité de référence.

## Ajout : vue institutionnelle transversale (lecture seule)
`AdminOverview` compose les services canoniques ; aucun registre, moteur ni règle nouveau.

| Bloc | Source canonique | Remarque |
|---|---|---|
| A En-tête | heure serveur, `BackupStatus` | périmètre « tout le site » et fraîcheur explicites |
| B Indicateurs | `PersonRegistry::kpis`, `ActivityRegistry::kpis`, `BadgesCartesSummary`, `OperationsOverview` | présents = identités distinctes ; supports actifs = cartes remises + badges associés |
| C Activités | `ActivityRegistry::rows`, `Pilotage::counters` | participants, attendus, arrivées confirmées, présents ; lien vers le registre |
| D Personnes et habilitations | `PersonRegistry::kpis` + comptages | une personne enregistrée n'est pas une habilitation ; dossiers à vérifier = noms identiques, titulaires sans catégorie ou sans photo |
| E Badges et cartes | `holder_cards`, `badge_issuances`, `badge_print_items`, `BadgesCartesSummary` | émis ≠ imprimé ≠ remis ≠ actif ; non restitués ≠ sortis |
| F Situation du site | `OperationsOverview`, `access_events` | synthèse ; le détail reste dans Opérations et le Pilotage |
| G Priorités | alertes escaladées, litiges, appareils silencieux, habilitations à échéance, badges non restitués, supports non imprimés, cartes non remises, dossiers incomplets, sauvegarde | statut réel, nombre et lien (aucun lien quand le droit manque : « relève d'un autre rôle ») |
| H Accès rapides | routes existantes | filtrés par permission |

## Dépendance
Cette PR est empilée sur #108 (Opérations) : elle réutilise `OperationsOverview` pour les présents distincts et les postes. Elle ne modifie aucun moteur.

## Correction d'affichage
Sur grand écran (> 1800 px), le cadre administratif était centré et borné : une bande vide apparaissait à gauche de la barre latérale. Le cadre est maintenant pleine largeur ; seul le contenu est borné (1700 px).

## Limites
- Pas de comparaison temporelle (tendances) ni d'export.
- Les parcours détaillés (supervision, décisions de litige) restent dans le Pilotage ; l'administrateur n'en a pas la permission de décision.
