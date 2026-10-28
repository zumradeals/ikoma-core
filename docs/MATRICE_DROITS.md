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
| pilotage.consulter | — | Oui | — | — | — |
| postes.gerer | Oui | — | — | — | — |
| presences.consulter | — | Oui | — | — | — |
| roles.attribuer | Oui | — | — | — | — |
| securite.gerer | Oui | — | — | — | — |
| approbations.decider | Oui | — | — | — | — |
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

Lot L15a (décision du dirigeant, 28 octobre 2026) : `securite.gerer` est ajoutée, réservée à l'administrateur (choix du profil de sécurité). Dans le profil renforcé, le 2FA devient obligatoire pour tous les rôles. Lot L15b : `approbations.decider` (valider ou refuser la demande d'un autre administrateur), réservée à l'administrateur.

Lot L16 : nouveau rôle `officier_securite` (hors tableau ci-dessus, un seul rôle par compte) avec `listes.approuver` (approuver ou refuser les invités en attente) et `interdits.gerer` (réservée à la future liste d'interdits, A2). L'administrateur n'a pas `listes.approuver`.

Lot L17 : `interdits.gerer` (ajouter ou lever une personne de la liste d'interdits) est effectivement utilisée ; réservée à `officier_securite`. Lever en profil renforcé demande la validation d'un administrateur (`approbations.decider`).
