# Politique opérationnelle de contrôle hors ligne — proposition (AILCT-A02.2, chantier 2)

| Champ | Valeur |
|---|---|
| Statut | **Proposition de politique, à valider.** Aucune règle, aucune durée ni aucune classification de ce document n'est une décision approuvée. |
| Portée | Documentaire. Aucun code, aucune migration, aucune permission, aucune donnée. |
| Contrat de référence (non dupliqué) | `A02-BADGES-INSTITUTIONNELS.md` §16 : invariants **INV-H01 à H10**, motifs de refus §16.7, journalisation §16.8, resynchronisation §16.9, tests **TA-H01 à H12** (PR n° 62, commit `e3af8bf`). Ce document **opérationnalise** ce contrat. |
| Documents frères | `ADR-TITULAIRES-PERMANENTS.md`, `REGISTRE-ARBITRAGES-A02-2.md` |

Légende : **[C]** constat dans le produit actuel (`main`, `9a7729f`), **[H]** hypothèse, **[P]** évolution du produit nécessaire, **[D-nn]** décision à arbitrer.
**Règle de lecture : toute valeur chiffrée marquée [H] est une hypothèse de travail, jamais une décision approuvée.**

## 1. Principe et limites

**Aucune autorisation n'est accordée sur la base de données devenues insuffisamment fiables** (INV-H01, H02). Cette politique précise : où le hors ligne est interdit, où il est toléré et combien de temps, ce qui se passe à l'expiration, comment on détecte une horloge anormale, comment on traite une révocation non reçue, ce que l'on fait en cas de panne, ce qui reste permis sans confiance, et ce que l'on prouve.

Limites assumées :
1. Une révocation non reçue **ne peut pas être connue** d'une tablette déconnectée. La politique **borne** ce risque (zones en ligne, durée de confiance) ; elle ne prétend pas l'éliminer pour les zones tolérant le hors ligne.
2. **La classification réelle des zones de l'AILCT n'est pas connue** et n'est pas inventée ici : la grille de la section 3 est à renseigner par le responsable opérationnel de l'Académie.
3. L'acceptation du risque par le client **ne remplace pas** une mesure : elle ne s'applique qu'au risque résiduel documenté (section 8).

## 2. État actuel du produit [C]

| Élément | Existant | Écart par rapport à la politique |
|---|---|---|
| Limite d'âge des données | `poste_hors_reseau_max_heures` = **12 h** (0 = désactivée) | Évaluée **au déverrouillage seulement** (`runtime.ts:94`, `PosteGate.vue:113`) [P] ; la valeur 0 désactive tout [P : à interdire pour l'AILCT] |
| Indicateur d'ancienneté | Seuil d'**une heure**, affichage seul (`sync.ts:34`) | Sans effet sur la décision [P] |
| Appel du superviseur | `poste_delai_appel_minutes` = **15 min** sans réseau | Réglage existant, avertissement |
| Écart d'horloge | `poste_ecart_horloge_secondes` = **300 s** : conflit et alerte à la synchronisation (`SyncProcessor.php:64-71`) | Détection **a posteriori** ; aucun refus local immédiat [P] |
| Verrouillage | `poste_inactivite_minutes` = 5 ; `poste_echecs_pin_max` = 15 | Existe |
| Recontrôle serveur d'un contrôle hors ligne | Oui : verdict local « valide » contredit → quarantaine, conflit, alerte `conflit_sync` (`SyncProcessor.php:216-222`) | Existe |
| Appareil révoqué / effacement | Oui, conflit critique à la synchronisation | Existe |
| Classe de sensibilité d'une zone | **Aucune** | À créer [P] |
| Évacuation hors ligne | Liste chiffrée disponible (lot L8) | Existe |
| Escalade d'alerte | `alerte_escalade_minutes` = 5 | Existe |

## 3. Grille de classification des zones (à renseigner par le responsable opérationnel)

### 3.1. Classes proposées [H]

La grille propose **trois classes de traitement**, pas une classification des zones de l'AILCT.

| Classe | Nom | Principe |
|---|---|---|
| **A** | Contrôle en ligne obligatoire | Hors ligne : **refus** (`zone_sensible_en_ligne`). Aucune durée de tolérance. |
| **B** | Hors ligne toléré, durée courte | Données fraîches exigées, durée maximale de confiance courte. |
| **C** | Hors ligne toléré, durée standard | Durée maximale de confiance standard. |

**Zone non classée** : traitée comme **classe A** tant que le responsable opérationnel ne l'a pas classée [H, à valider D-21]. Cette règle protège par défaut mais peut bloquer l'exploitation d'une zone oubliée ; elle est volontairement prudente.

### 3.2. Questions d'aide à la classification (à poser, pas à deviner)

1. Quelle est la conséquence d'un accès indu à cette zone (personnes, informations, matériels) ?
2. Cette conséquence est-elle réversible ?
3. La zone abrite-t-elle des personnes vulnérables, des matériels sensibles ou des informations classifiées ?
4. Existe-t-il un autre contrôle physique indépendant (garde, sas, escorte) ?
5. Un arrêt de l'accès pendant une panne est-il acceptable, ou met-il des personnes en difficulté ?
6. Quel est le délai maximal acceptable entre une révocation et son effet à ce point ?

### 3.3. Grille à renseigner

| Site | Zone | Description | Responsable de la zone | Conséquence d'un accès indu | Contrôle physique indépendant | Classe proposée par le responsable (A/B/C) | Durée de confiance de la zone | Justification | Validé par (nom, date) |
|---|---|---|---|---|---|---|---|---|---|
| *(à renseigner)* | | | | | | | | | |
| *(à renseigner)* | | | | | | | | | |
| *(à renseigner)* | | | | | | | | | |

La grille est volontairement **vide**. La zone « accueil » existe dans le produit comme zone obligatoire [C] ; sa classe n'est pas préjugée.

### 3.4. Réalisation dans le produit [P]

Une classe et une durée par zone sont des **réglages métier** de l'administration (INV-H09, règle du dépôt : aucun réglage d'exploitation dans les fichiers techniques). Aucun nom de colonne ni de réglage n'est arrêté ici.

## 4. Durées maximales de confiance — hypothèses

**Toutes les valeurs ci-dessous sont des hypothèses [H] à fixer par D-22.**

| Paramètre | Hypothèse de départ | Plage à discuter | Commentaire |
|---|---|---|---|
| Durée maximale de confiance générale (zones sans durée propre) | **4 h** | 1 à 12 h | Existant : 12 h. **Valeur « illimitée » interdite** pour l'AILCT [H]. |
| Classe A | **Sans objet** (en ligne obligatoire) | — | Refus hors ligne. |
| Classe B | **30 à 60 min** | 15 min à 2 h | À fixer avec la sécurité de l'Académie. |
| Classe C | **2 à 4 h** | 1 à 8 h | Peut coïncider avec la durée générale. |
| Seuil d'avertissement | **50 %** de la durée applicable | 30 à 80 % | Alerte avant refus. |
| Fréquence cible de synchronisation, réseau présent | **5 min** | 1 à 15 min | À vérifier dans le produit [C]. |

**Durée applicable à un contrôle** = la plus courte entre la durée générale et celle de la zone du point de contrôle.

## 5. Refus automatique après expiration

### 5.1. États de confiance (par tablette et par point de contrôle)

| État | Condition | Effet sur un contrôle d'entrée |
|---|---|---|
| **Confiance pleine** | Âge ≤ seuil d'avertissement, horloge saine | Verdict normal |
| **Confiance dégradée** | Seuil d'avertissement < âge ≤ durée applicable | Verdict normal + avertissement visible, alerte au superviseur |
| **Confiance expirée** | Âge > durée applicable | **Refus** `donnees_perimees` |
| **Confiance nulle** | Anomalie d'horloge, instantané invalide, appareil révoqué | **Refus** (motif propre) |

### 5.2. Table de décision (entrée d'une personne, contrôle d'autorisation)

| Classe de la zone | Réseau | Âge des données | Horloge | Décision |
|---|---|---|---|---|
| A | Disponible | — | — | Contrôle **serveur** : verdict du serveur |
| A | Indisponible | — | — | **Refus** `zone_sensible_en_ligne` |
| B ou C | Disponible | — | — | Contrôle serveur (préféré au local) |
| B ou C | Indisponible | ≤ durée applicable | Saine | Contrôle local (avertissement si dégradé) |
| B ou C | Indisponible | > durée applicable | Saine | **Refus** `donnees_perimees` |
| Toutes | Indisponible | Quelconque | Anormale | **Refus** `horloge_anormale` |
| Toutes | Indisponible | Quelconque | Saine, instantané invalide | **Refus** `instantane_invalide` |

Principe : le contrôle **serveur** est toujours préféré quand le réseau est présent ; le contrôle local n'est qu'un repli.

### 5.3. Après un refus par expiration

Aucune validation manuelle dans l'application. La tablette affiche l'âge des données, l'heure de la dernière synchronisation et oriente vers la procédure de repli (section 8). Une synchronisation **complète** rétablit la confiance immédiatement (INV-H02).

## 6. Anomalie d'horloge

| Situation | Détection | Effet |
|---|---|---|
| Horloge **reculée** (antérieure à la dernière heure connue) | Comparaison locale persistée | Confiance **nulle** (INV-H07) |
| Écart **supérieur au seuil** avec l'heure du serveur | À la synchronisation (existant : 300 s [C]) ; **localement** par différence de l'âge mesuré et de l'âge attendu [P] | Confiance nulle ; conflit et alerte (existant) |
| Horloge **avancée** (âge apparent trop grand) | Âge apparent > durée applicable | Refus par expiration (sécuritaire) |
| Redémarrage de la tablette ou de l'application | Âge **persisté**, jamais remis à zéro | Âge conservé |
| Absence de toute heure de référence | Instantané jamais reçu | Refus `instantane_invalide` |
| Modification manuelle de l'heure de l'appareil | Même détection (recul, écart) | Confiance nulle |

**Rétablissement** : synchronisation réussie avec le serveur, qui fournit l'heure de référence. Aucun réglage manuel de la confiance n'est possible depuis la tablette. Le seuil d'écart (300 s) est un réglage existant à confirmer pour l'AILCT [H].

Limite : sans source de temps fiable embarquée, un appareil dont l'horloge est falsifiée **avant** sa dernière synchronisation peut fausser l'âge ; la durée de confiance et la reprise obligatoire de synchronisation limitent cet effet sans le supprimer.

## 7. Révocations non synchronisées

### 7.1. Objectifs

1. Réduire le **délai de propagation** d'une révocation vers les tablettes en ligne.
2. Borner le **risque** pour les tablettes hors ligne.
3. Détecter et traiter **a posteriori** toute acceptation contredite.

### 7.2. Mesures

| Mesure | Détail | Statut |
|---|---|---|
| Contrôle serveur préféré | Réseau présent → verdict serveur, révocation effective immédiatement | Existe |
| Zones de classe A | Aucune décision locale : risque nul pour ces zones | [P] |
| Durée de confiance | Borne l'âge des données donc l'ancienneté maximale d'une révocation ignorée | [P] vérification à chaque décision |
| Récupération des révocations en priorité | À la reconnexion, avant tout nouveau contrôle (INV-H03, contrat §16.6) | [P] |
| Recontrôle serveur | Toute acceptation locale est recontrôlée ; contradiction → quarantaine, conflit, alerte | Existe |
| Alerte de révocation | Alerte `badge_annule` au superviseur à l'annulation | Existe |
| **Procédure humaine d'urgence** | Le superviseur appelle les points de contrôle concernés pour qu'ils refusent la carte concernée ; consigne sur le registre papier de repli | Procédure (section 8) |

### 7.3. Traitement a posteriori d'une acceptation contredite

1. Conflit créé, alerte `conflit_sync` (priorité haute) [C].
2. Le superviseur examine : la personne est-elle présente ? où ? avec quels accès ?
3. Décision tracée (motif obligatoire) ; **l'historique n'est jamais réécrit**.
4. Si la personne est présente et non autorisée : traitement de sécurité selon la procédure de l'Académie.
5. Retour d'expérience : la révocation a-t-elle touché une zone qui devrait passer en classe A ?

### 7.4. Objectif de délai de propagation

**Hypothèse [H]** : une révocation est effective sur les tablettes en ligne en moins de **5 minutes** ; sur les tablettes hors ligne, à la plus proche synchronisation et au plus tard à l'expiration de la durée de confiance. Valeur à fixer avec D-22.

## 8. Procédure de repli en cas de panne réseau — proposition

**La procédure est un document d'exploitation sous l'autorité du superviseur, pas une fonctionnalité du produit.** Elle sera rédigée et validée avec le responsable opérationnel de l'AILCT (D-23). Squelette proposé :

### 8.1. Déclenchement

| Événement | Détection | Responsable de la décision |
|---|---|---|
| Panne réseau d'un site | Alerte « poste silencieux » [C], constat de l'agent | Superviseur |
| Tablette seule en panne | Constat de l'agent | Agent → superviseur |
| Panne du serveur | Absence de synchronisation sur plusieurs postes | Responsable opérationnel |
| Expiration de confiance | Message de refus `donnees_perimees` | Agent → superviseur |

### 8.2. Actions par rôle

| Rôle | Actions |
|---|---|
| **Agent** | Ne contourne pas le refus ; informe le superviseur ; oriente la personne vers l'attente ou l'escorte ; consigne les passages sur le registre de repli |
| **Superviseur** | Évalue l'étendue ; décide des mesures par zone (maintien de l'attente, accès accompagné, fermeture) ; informe le responsable opérationnel ; conserve le registre de repli |
| **Responsable opérationnel** | Autorise ou refuse tout accès exceptionnel pour les zones de classe A ; décide du retour à la normale |
| **Administrateur technique** | Rétablit le réseau ou le serveur ; vérifie la resynchronisation |

### 8.3. Règles

1. **Aucune entrée n'est enregistrée comme « validée »** dans l'outil quand la confiance est expirée (INV-H02).
2. Les zones de classe A restent fermées aux personnes non vérifiables ; tout accès exceptionnel est décidé et signé par le responsable opérationnel.
3. Un **registre papier de repli** (date, heure, personne, pièce, zone, agent, décision) est tenu ; il est **réconcilié** après le retour à la normale par saisie des événements dans l'outil, sans modifier l'historique existant.
4. Les personnes déjà présentes ne sont pas expulsées par la panne ; les **sorties** restent enregistrables (section 9).
5. Retour à la normale : synchronisation complète, vérification de l'absence de conflit critique, clôture du registre de repli, compte rendu au responsable opérationnel.

### 8.4. À compléter (non inventé ici)

Noms des responsables et numéros d'appel ; critères précis par zone ; modèle du registre papier ; durée maximale d'un fonctionnement en repli avant fermeture.

## 9. Opérations de sécurité des personnes maintenues hors connexion

Principe (INV-H08, D-24) : les opérations qui **protègent les personnes** ou **n'accordent aucun accès** restent disponibles quelle que soit la confiance. Celles qui **accordent un accès** sont refusées.

| Opération | Maintenue sans confiance ? | Justification | Existe [C] |
|---|---|---|---|
| Enregistrer une **sortie** | **Oui** | Ne donne aucun accès ; nécessaire à la présence et à l'évacuation | Oui (lot L7) |
| Consulter la **liste d'évacuation** locale | **Oui** | Sécurité des personnes | Oui (lot L8) |
| Voir le **nombre de présents** local | **Oui** | Information, sans droit | Partiel |
| Déclencher ou relayer une **alerte** locale | **Oui** [H] | Sécurité | À vérifier |
| Verrouiller la tablette / effacer à distance à la reconnexion | **Oui** | Sécurité de l'appareil | Oui |
| Enregistrer une **entrée** (contrôle d'autorisation) | **Non** | Accorde un accès | Contrôlé par la politique |
| **Associer un badge** à une personne | **Non** | Crée un droit d'accès | À aligner [P] |
| Admettre une personne à l'accueil (parcours en sept étapes) | **Non** | Idem | À aligner [P] |
| Consulter l'identité d'une personne à des fins de contrôle | À arbitrer | Minimisation | — |

Dans tous les cas, l'opération est **journalisée** localement, avec l'âge des données, et synchronisée plus tard.

## 10. Preuves d'audit

### 10.1. Éléments journalisés pour chaque décision hors ligne (acceptation **ou** refus)

| Élément | Origine |
|---|---|
| Identifiant d'opération (idempotent) | Tablette |
| Verdict local et **code de motif** | Tablette |
| **Âge des données** à l'instant de la décision [P] | Tablette |
| **Révision de l'instantané** utilisé [P] | Tablette |
| Heure déclarée de l'appareil et indicateur d'horloge [P] | Tablette |
| Point de contrôle, zone, **classe de la zone** [P] | Tablette |
| Agent, poste | Tablette |
| Heure de réception, verdict du serveur, éventuel conflit | Serveur |

Contraintes : **aucun nom ni photo** dans le journal d'audit ; ajout seul ; aucun effacement.

### 10.2. Rapports exploitables

1. **Rapport des décisions hors ligne** : par poste, période et zone, avec la répartition acceptations / refus et les âges maximaux observés.
2. **Rapport des contradictions** : acceptations locales contredites par le serveur, avec délai de résolution.
3. **Rapport d'écarts d'horloge**.
4. **Revue périodique** par le responsable opérationnel (fréquence à fixer, [H] hebdomadaire pendant la montée en charge).

### 10.3. Conservation

Les preuves suivent la conservation du journal d'audit existant (aucune purge depuis le module de conservation, LOT33). Durée complémentaire éventuelle : D-16.

## 11. Critères de recette

La recette réutilise les tests **TA-H01 à H12** du contrat. Elle y ajoute les critères opérationnels ci-dessous, à vérifier avant toute exploitation.

| # | Scénario | Résultat attendu | Preuve |
|---|---|---|---|
| R1 | Zone A, réseau coupé | Refus `zone_sensible_en_ligne` | Capture, journal (TA-H04) |
| R2 | Zone B, données à durée − 1 min puis + 1 min, sans redéverrouillage | Acceptation puis refus `donnees_perimees` | Journal avec âge (TA-H01) |
| R3 | Zone non classée | Traitée comme classe A | Journal |
| R4 | Seuil d'avertissement dépassé | Avertissement visible + alerte superviseur | Capture |
| R5 | Horloge reculée de plus que le seuil | Refus `horloge_anormale` ; rechargement : âge conservé | Journal (TA-H07) |
| R6 | Carte annulée pendant le hors ligne, zone B | Acceptation locale, puis conflit + alerte à la synchronisation, historique intact | Conflit (TA-H03) |
| R7 | Reconnexion après expiration | Confiance rétablie seulement après synchronisation **complète** | Journal (TA-H02) |
| R8 | Confiance expirée : sortie, évacuation | Possibles, journalisées | Captures (TA-H06) |
| R9 | Confiance expirée : association de badge, admission | Refusées | Journal |
| R10 | Paramètres modifiés par un rôle non autorisé | Refus ; modification autorisée journalisée | Journal (TA-H09) |
| R11 | Exercice de **panne réseau** complet avec registre papier de repli | Procédure suivie, réconciliation faite, aucune entrée « validée » en confiance expirée | Compte rendu signé |
| R12 | Permanent hors ligne : carte expirée refusée, sortie sans restitution | Conforme aux contrats permanents | TA-H12, TA-P01 |

## 12. Réglages du produit nécessaires (indicatifs)

| Réglage | Existe [C] | Valeur actuelle | Hypothèse AILCT [H] |
|---|:-:|---|---|
| Durée de confiance générale | Oui (`poste_hors_reseau_max_heures`) | 12 h | 4 h ; « 0 = illimité » interdit |
| Seuil d'avertissement | Non | — | 50 % |
| Durée de confiance par zone | Non | — | selon la grille |
| Classe de zone | Non | — | selon la grille |
| Écart d'horloge toléré | Oui | 300 s | à confirmer |
| Délai d'appel du superviseur | Oui | 15 min | à confirmer |
| Verrouillage par inactivité, tentatives de code | Oui | 5 min, 15 | à confirmer |
| Fréquence de synchronisation | À vérifier | — | 5 min |

Aucun nom de réglage nouveau n'est arrêté. Aucun profil de sécurité groupé n'est réintroduit (D-26 du plan).

## 13. Responsabilités proposées

| Rôle | Responsabilités |
|---|---|
| Responsable du produit (le dirigeant) | Valide le contrat et les paramètres génériques ; arbitre D-13, D-14, D-22 sur le plan produit |
| **Responsable opérationnel de l'AILCT** | Classe les zones (D-21), valide les durées par zone, la procédure de repli (D-23) et les accès exceptionnels |
| Sécurité de l'AILCT | Fournit les critères de sensibilité, valide les classes A |
| Superviseur | Exécute la procédure, traite les conflits |
| Administrateur technique | Maintient les réglages dans les limites approuvées |

## 14. Risques résiduels et hypothèses

| Réf. | Risque ou hypothèse |
|---|---|
| RS1 | Zones classées B ou C : un badge révoqué reste acceptable jusqu'à la durée de confiance. |
| RS2 | Une classification trop prudente (beaucoup de zones A) peut bloquer l'exploitation lors d'une panne. |
| RS3 | Une classification trop permissive augmente la fenêtre d'exposition. |
| RS4 | L'horloge de la tablette n'est pas une source de temps de confiance. |
| RS5 | Le registre papier de repli est une procédure humaine, donc sujette à l'erreur. |
| H1 | Toutes les durées de la section 4. |
| H2 | « Zone non classée = classe A ». |
| H3 | Maintien de l'alerte locale sans confiance. |
| H4 | Objectif de propagation de 5 minutes. |
