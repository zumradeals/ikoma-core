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
| groupes.appel_nominal | — | — | Oui | — | — |
| groupes.confirmer_presence | — | — | — | — | Oui |
| groupes.remettre_badges | — | — | Oui | — | — |
| groupes.suivre | — | Oui | — | — | — |
| journal.consulter | Oui | Oui | — | — | — |
| journal.exporter | Oui | — | — | — | — |
| listes.importer | Oui | — | — | — | — |
| listes.mettre_a_jour | — | — | — | Oui | — |
| litiges.decider | — | Oui | — | — | — |
| pilotage.consulter | — | Oui | — | — | — |
| presences.consulter | — | Oui | — | — | — |
| roles.attribuer | Oui | — | — | — | — |
| visiteurs.enregistrer_mobile | — | — | Oui | — | — |
| visiteurs.enregistrer_non_liste | — | — | Oui | — | — |
| visiteurs.rechercher | — | — | Oui | — | — |
| visiteurs.refuser | — | — | Oui | — | — |
| visiteurs.valider | — | — | Oui | — | — |
| visiteurs.valider_non_liste | — | — | — | Oui | — |
| visiteurs.verifier | — | — | Oui | — | — |
| zones.gerer | Oui | — | — | — | — |

Les permissions des futurs modules sont enregistrées mais aucune route ni table de ces modules n’est créée. `journal.exporter` est réservé ; aucun export L1.

Validation du dirigeant : la permission `categories.gerer` est attribuée uniquement à l’administrateur, conformément à la section 10 du document de fonctionnement (« gestion des badges, zones et catégories »). La portée des listes de l’hôte et du groupe de l’encadreur devra être limitée aux objets concernés dans leurs futurs modules.
