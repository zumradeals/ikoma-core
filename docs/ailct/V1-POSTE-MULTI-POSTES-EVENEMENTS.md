# Tablette permanente et activité du jour (analyse et plan par étapes, à valider)

Statut : **projet de travail**. Décisions du dirigeant : (1) « une tablette pourrait être liée à plusieurs postes ou événements » (sélecteur de l'en-tête de la maquette) ; (2) **la tablette est un équipement permanent du site AILCT : on ne choisit pas d'événement pour la créer** ; l'activité du jour se choisit à l'usage (« Tablette permanente + activité du jour »). Rien n'est implémenté : ce document fixe le plan et les questions restantes.

## Écart constaté avec la vision déjà adoptée
`docs/ailct/V1-INVARIANTS-SITE-ET-CONTROLE.md` §2 : le site, ses postes et ses équipements existent indépendamment de toute activité ; une tablette est un équipement distinct du poste, affecté à un poste ou à aucun. Dans le code, l'écran « Créer un poste » (Administration) exige encore un événement : `devices.event_id` est obligatoire et sert de clé à la copie hors ligne, aux badges réservés (`reserved_device_id` + `event_id`), à l'enrôlement et à l'effacement. L'affectation tablette → poste (`checkpoint_id`, `device_assignments`) est déjà faite ; l'indépendance vis-à-vis de l'activité ne l'est pas.

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

## Plan par étapes (une branche et une PR par étape, aucune étape ne casse l'existant)
1. **Modèle** : `devices.event_id` devient facultatif (« tablette de site »). Nouvelle table d'**affectation à une activité** (tablette × activité, unique) qui porte désormais les badges réservés (les clés composites `(device_id, event_id)` pointent vers elle). Reprise des données : chaque tablette actuelle reçoit une affectation à son événement actuel. Tests de migration et de non-régression des réservations.
2. **Administration** : « Créer une tablette » sans événement ; affectation à un poste du site (ou aucun) ; liste des activités autorisées pour cette tablette (facultative : par défaut, toutes les activités ouvertes du site). Journal d'audit.
3. **Enrôlement et copie hors ligne par activité** : le serveur sert `GET /poste/api/liste?activite=…` ; la tablette garde une copie chiffrée par activité sélectionnée ; l'effacement par fin d'événement ne vise que la copie de cette activité.
4. **Sélecteur de la tablette** (en-tête de la maquette) : poste et activité, limités aux affectations de la tablette ; sélection automatique s'il n'y en a qu'une ; chaque opération de la file porte `activity_id`. Tests Playwright de coupure réseau avec bascule d'activité.
5. **Retrait de l'ancien lien** : suppression de la dépendance `devices.event_id` une fois toutes les lectures migrées ; mise à jour de `docs/PLAN_CONCEPTION.md` §4.

## Questions restantes pour le dirigeant
- Qui choisit l'activité du jour : **l'agent sur la tablette** (recommandé, conforme au sélecteur de la maquette), l'administrateur, ou automatiquement quand une seule activité est ouverte ?
- Une tablette peut-elle être limitée à certaines activités (liste autorisée) ou voit-elle toutes les activités ouvertes du site ?
