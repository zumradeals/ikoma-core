# Poste d'accueil — une tablette liée à plusieurs postes et événements (analyse, à valider)

Statut : **projet de travail**, décision du dirigeant : « une tablette pourrait être liée à plusieurs postes ou événements » (sélecteur de l'en-tête de la maquette). Rien n'est implémenté : ce lot demande une validation écrite.

## Situation actuelle (constats dans le code)
- Une tablette = une ligne `devices` portant **un seul** `event_id`, un poste (`station`, `point_code`) et un jeton d'appareil. L'enrôlement (code à usage unique) lie la tablette à ce poste.
- La copie hors ligne (`ListSnapshot::build(Device)`) ne contient que l'événement du poste ; une seule copie chiffrée existe dans IndexedDB, avec un seul PIN et un seul profil d'agent.
- Les badges réservés (`reserved_device_id`) et la file d'opérations sont rattachés à l'appareil ; l'effacement automatique a lieu 24 h après la fin de l'événement (`fin_evenement`).
- Le serveur déduit l'événement du poste à chaque appel (`X-Device-Token` → `Device`) : aucune opération ne transporte son événement.

## Modèle proposé
1. **Appareil** (tablette physique, jeton, PIN, agent) séparé de l'**affectation** (poste × événement, avec son point, ses capacités, son lot de badges réservé). Un appareil porte une ou plusieurs affectations actives ; l'administrateur les gère dans l'écran Administration (réglage métier, pas fichier technique).
2. **Une copie hors ligne par affectation**, chiffrée sous le même PIN, chargée à l'enrôlement et à la synchronisation de chaque affectation ; l'effacement par fin d'événement ne concerne que la copie de cet événement.
3. **Chaque opération de la file porte `assignment_id`** (poste + événement) ; le serveur vérifie que l'appareil détient cette affectation et jamais ailleurs (principe du moindre pouvoir, rejet explicite sinon).
4. **Sélecteur** : liste des seules affectations de l'appareil ; changer d'affectation ne vide pas la file et ne mélange jamais les listes, les badges ou les statistiques. Le changement est tracé au journal.
5. **Conflits** : un badge réservé à un poste n'est jamais proposé à un autre ; si deux affectations d'un même appareil partagent un lot, c'est une alerte superviseur.

## Points à trancher par le dirigeant
- Qui peut ajouter une affectation à une tablette (administrateur seul, ou superviseur) ?
- Deux affectations actives en même temps sur le même événement (Accueil 1 / Accueil 2) : autorisé ?
- Durée de conservation de la copie d'un événement non sélectionné (aujourd'hui : 24 h après sa fin).

## Livrables du lot (après validation)
Migration (affectations), enrôlement et synchronisation par affectation, file locale versionnée, écran Administration, sélecteur, tests Pest + Playwright de coupure réseau avec bascule, mise à jour de `docs/PLAN_CONCEPTION.md` §4.
