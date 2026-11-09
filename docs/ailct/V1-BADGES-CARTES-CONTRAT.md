# V1 — Badges et cartes : inventaire ciblé et contrat canonique

Statut : projet de travail (chantier « Convergence des moteurs »). Lecture du code au 2026-11-09 ; rien n'est déclaré adopté.

## 1. Inventaire (responsabilités → propriétaires actuels)
| Responsabilité | Visiteurs / badges temporaires | Cartes permanentes | Constat |
|---|---|---|---|
| Fabrication (numéro + jeton QR) | `BadgeLots::generate` (lots) puis `BadgePreparation` (plage, réservation poste) ; `BadgeLots::issueSingle` | `HolderCards::issue` → `issueSingle` | Une porte unitaire existe déjà (`issueSingle`), mais le parcours visiteurs passe par des lots. |
| Lot « technique » | `badges.badge_batch_id` NOT NULL (FK simple depuis S05) | idem (lot d'un exemplaire) | Dépendance d'intégrité réelle : voir §3. |
| Protection d'un support imprimé | `badge_batches.print_exposed_at` (purges : `DataLifecycle`) | `HolderCards::recordPrint` marque le lot d'un exemplaire | Porté par le lot, pas par le badge. |
| Modèles graphiques | réglages `badge_atelier_{événement}_*` (`BadgeWorkshopController`, `BadgeArtwork`) | `CardModels` (8 catégories institutionnelles) | Deux référentiels concurrents. |
| Impression / tirages | `Atelier\PrintTrail` + `BadgePrinting` (`badge_print_runs` / `badge_print_events`) | `holder_card_prints` (`recordPrint`) | Deux moteurs de tirage et deux historiques. |
| Associer / restituer / annuler / contrôler | `BadgeService` | — | À conserver. |
| Révoquer / remplacer carte | — | `CardLifecycle` | À conserver. |
| Mise à disposition inter-activités | `BadgeRegistry::transfer` | — | À conserver. |

## 2. Contrat d'émission (précision 1) — idempotence identifiable
Une émission est une opération **identifiée** : `(operation_id, person_id canonique, contexte, type de support)`.
- `operation_id` : identifiant de requête fourni par le client (UUID) ; une même opération rejouée retourne le **même** résultat, sans doublon (reprise après interruption comprise).
- Contexte : participation ouverte (badge temporaire) ou profil permanent actif (carte). Jamais une personne seule, jamais un nom.
- Règle de cycle de vie : un support « en cours » (non restitué, non annulé, non révoqué) pour le même contexte **refuse** une nouvelle émission *distincte* en indiquant les voies légitimes (conserver, réimprimer, remplacer). Une nouvelle émission devient possible quand le cycle précédent est clos (restitution, annulation, révocation/remplacement explicite).
- Le support remplacé n'est jamais réutilisé (numéro et jeton déjà gardés par `retired_badge_identifiers`).
- Aucune habilitation créée ; aucune activation automatique ; aucune réservation.

## 3. Dépendance historique aux lots (précision 2)
- Vérifié : `badges.badge_batch_id` est NOT NULL ; `issueSingle` satisfait cette contrainte en créant un lot d'un exemplaire ; la protection d'exposition à l'impression (`print_exposed_at`) et les purges (`DataLifecycle`) lisent le lot.
- Décision : **structure de compatibilité interne, explicite et temporaire** — « lot technique d'émission » créé par le contrat d'émission, invisible dans le parcours (aucun choix de lot, aucune réservation). Aucune migration destructrice ; aucun nouveau moteur. Sortie cible (hors de ce chantier) : porter l'exposition à l'impression sur le badge, puis rendre `badge_batch_id` facultatif.
- La génération groupée appelle le même contrat en boucle ; aucun lot préalable.

## 4. Modèles et impressions (précision 3)
- `CardModels` est le référentiel institutionnel canonique (8 catégories). Les réglages `badge_atelier_*` ne sont plus lus pour de nouvelles émissions ; ils restent consultables.
- Un seul contrat d'impression pour toute nouvelle émission ou réimpression ; un seul moteur de rendu. Les historiques antérieurs (`badge_print_runs`, `holder_card_prints`) restent consultables, sans migration destructrice.
