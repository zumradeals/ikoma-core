# V1 — Audit ciblé « Activités »

Audit limité aux composants nécessaires au chantier. Aucune fonction n'a été créée pour combler la maquette.

| Fonction existante | Contrat (service / route) | Écran cible | Écart réel |
|---|---|---|---|
| Créer / modifier une activité (événement, stage, visite) | `EventsController::store/update`, `evenements.gerer` ; zone d'accueil créée | Registre : « Nouvelle activité », « Modifier » | Aucun. La nature n'est plus modifiable après création (règle existante). |
| Clôturer / archiver une activité | `DataManagementController::transition` → `DataLifecycle` | Fiche › Historique et clôture | Aucun. Les habilitations permanentes ne sont pas touchées (testé). |
| Inscrire une personne existante | `Participations::enrol`, `POST /administration/personnes/{person}/participations`, `listes.importer` | Fiche › Participants | Retour à la fiche d'activité (`retour=activites`, seule valeur admise). |
| Créer une personne | `Persons::create` (recherche et homonymes avant création) | Renvoi vers « Personnes » | Création explicite conservée dans Personnes. |
| Clôture individuelle d'une participation | `Participations::close` (motif obligatoire ; refus si présente, badge attribué ou véhicule présent) | Fiche › Participants | Aucun. |
| Clôture groupée / VIP / globale par l'administrateur | `SupervisorClosure`, `Pilotage` (`cloture.superviseur`), `bulkClose` (permanents) | Non repris dans l'écran | Hors écran d'activité : relève de Pilotage et de « Personnel permanent ». Signalé, non dupliqué. |
| Import de listes | `ListImportsController` (`/administration/imports`) | Lien « Importer (CSV) » | Aucun. |
| Fiches d'invités | `AdminVisitorsController` (`/administration/invites`) | Conservé (route inchangée) | Aucun. |
| Groupes et cars | `GroupesController` (`groupes.gerer`, module F06) | Onglet affiché seulement s'il y a des groupes ou véhicules ; lien vers Groupes et cars | Aucun. |
| Véhicules, visites annoncées | `VisitsController` (`/administration/participations/{visitor}/annonce|vehicules`) | Depuis la fiche de la personne / de l'invité | Aucun contrôle militaire des véhicules ajouté. |
| Habilitations de participation | `ParticipationEntitlements` (`/administration/acces/habilitations…`) | Fiche › Accès et badges (synthèse) ; gestion dans la fiche de la personne | Synthèse seulement, pas de nouveau moteur. |
| Badges | `BadgeService`, `badge_assignments` | Fiche › Accès et badges (compteurs) et colonne Badge | Gestion physique reste dans « Badges et cartes ». |
| Journal | `audit_logs` (`participation.creee`, `participation.cloturee`, `evenement.cree`…) | Fiche › Historique | Lecture limitée aux comptes `journal.consulter`. |

## Fonctions de la maquette absentes du code (signalées, non créées)
Référence d'activité (code ST-/FO-/EV-), photo d'activité, onglet Documents, export CSV des participants, colonne « Statut institutionnel » dans la liste des participants, présence par jour, nombre de « Cars » comme compteur propre (les cars sont le numéro de car d'un groupe).
