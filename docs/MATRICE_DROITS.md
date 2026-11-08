# Matrice des droits L1 — validée le 27 octobre 2026

Source : [document de fonctionnement v1.0](reference/Mode_de_fonctionnement_verification_visiteurs.pdf), sections 2 et 10. Aucun héritage implicite de rôle. Un seul rôle attribué par compte dans L1. Tout droit non listé est refusé.

| Permission | administrateur | superviseur | agent_accueil | hote | encadreur |
|---|---|---|---|---|---|
| alertes.consulter | — | Oui | — | — | — |
| arrivees.recevoir_notification | — | — | — | Oui | — |
| badges.annuler | — | Oui | — | — | — |
| badges.associer | — | — | Oui | — | — |
| badges.controler | — | — | Oui | — | — |
| badges.gerer | Oui | — | — | — | — |
| cars.suivre | — | Oui | — | — | — |
| categories.gerer | Oui | — | — | — | — |
| comptes.gerer | Oui | — | — | — | — |
| evenements.gerer | Oui | — | — | — | — |
| groupes.appel_nominal | — | — | Oui | — | — |
| groupes.confirmer_presence | — | — | — | — | Oui |
| groupes.gerer | Oui | — | — | — | — |
| groupes.remettre_badges | — | — | Oui | — | — |
| groupes.suivre | — | Oui | — | — | — |
| journal.consulter | Oui | Oui | — | — | — |
| journal.exporter | Oui | — | — | — | — |
| listes.importer | Oui | — | — | — | — |
| listes.mettre_a_jour | — | — | — | Oui | — |
| litiges.decider | — | Oui | — | — | — |
| pilotage.consulter | Oui (S02, lecture) | Oui | — | — | — |
| postes.gerer | Oui | — | — | — | — |
| presences.consulter | Oui (S02, lecture) | Oui | — | — | — |
| roles.attribuer | Oui | — | — | — | — |
| visiteurs.enregistrer_mobile | — | — | Oui | — | — |
| visiteurs.enregistrer_non_liste | — | — | Oui | — | — |
| visiteurs.rechercher | — | — | Oui | — | — |
| visiteurs.refuser | — | — | Oui | — | — |
| visiteurs.valider | — | — | Oui | — | — |
| visiteurs.valider_non_liste | — | — | — | Oui | — |
| visiteurs.verifier | — | — | Oui | — | — |
| zones.gerer | Oui | — | — | — | — |

Lot L3b (décision du dirigeant, 27 octobre 2026) : `groupes.gerer`, `postes.gerer` et `evenements.gerer` sont ajoutées, réservées à l’administrateur. Les photos de visiteurs ne sont consultables par aucun rôle ; seule leur prise à l’accueil existera.

Les permissions des futurs modules sont enregistrées mais aucune route ni table de ces modules n’est créée. `journal.exporter` est réservé ; aucun export L1.

Validation du dirigeant : la permission `categories.gerer` est attribuée uniquement à l’administrateur, conformément à la section 10 du document de fonctionnement (« gestion des badges, zones et catégories »). La portée des listes de l’hôte et du groupe de l’encadreur devra être limitée aux objets concernés dans leurs futurs modules.


## S02 — Autorisations et traçabilité (novembre 2026)

- **Administrateur et supervision :** `pilotage.consulter` et `presences.consulter` lui sont accordées pour la **consultation** (tableau, présents, fiche, évacuation). Il n'a toujours ni `alertes.consulter` (qui porte aussi la prise en compte), ni `badges.annuler`, ni `litiges.decider`, ni `groupes.suivre`/`cars.suivre` : il consulte, il n'exerce aucune action du superviseur. Voir les alertes sans pouvoir les prendre en compte demandera de scinder `alertes.consulter` (lot ultérieur).
- **Rôle de session :** un superviseur ou un administrateur ne devient jamais agent : toutes les routes du poste exigent le rôle `agent_accueil` ET chaque service (`SiteControl`, `Desk`) le revérifie.
- **Capacités par poste** (`App\Services\Poste\PostCapabilities`, colonne `devices.point_code`) : le rôle ne suffit plus, la tablette doit tenir un point qui porte la capacité.

| Point | accueil | scan | sortie |
|---|:-:|:-:|:-:|
| poste_1 (entrée principale) | — | — | — |
| poste_2 (accès terrestre) | Oui | Oui | Oui |
| poste_3, poste_4 (procédures « à confirmer ») | — | — | — |
| soute | — | Oui | — |
| non identifié (point_code nul) | — | — | — |

  Postes 3 et 4 : aucune capacité tant que leurs procédures ne sont pas décidées (cahier des charges §13). **Refus par défaut** : un poste sans point de contrôle identifié n'a aucune capacité. Le point est obligatoire à la création du poste, à l'émission du code d'enrôlement, à l'enrôlement et, en base, pour tout appareil muni d'un jeton (contrainte `devices_enrolled_has_point`).
- **Hors ligne :** la copie de la tablette porte `poste.point_code` et `poste.capabilities` (calculées par le serveur, source unique). La tablette applique localement la porte AVANT tout résultat favorable : poste non identifié, capacité absente, ou copie plus ancienne que la limite déjà validée `poste_hors_reseau_max_heures` (0 = désactivée) → refus, rien n'est mis en file. Le serveur revérifie au rejeu : capacités du point COURANT, et péremption de la copie pour toute décision favorable d'un badge temporaire (`politique_perimee`, quarantaine). Les cartes permanentes gardent leur règle de confiance propre.
- **Auteur des opérations :** toujours le compte de la session (403 `auteur_incoherent` sinon). Une tablette garde ses opérations en attente pour l'agent qui les a saisies : un autre compte ne peut ni les effacer, ni les envoyer, ni les reprendre (voir JOURNAL_DECISIONS, S02).
- **Clôture VIP :** indicateur explicite `categories.closure_supervisor_only`. Sortie et restitution refusées à l'agent (`cloture_reservee_superviseur`) pour ces catégories ; jamais déduit du libellé. Le chemin superviseur de la clôture appartient au lot de clôture ultérieur.
- **Clôture globale d'une activité :** `evenements.gerer`, réservée à l'administrateur (inchangé, désormais testé).
