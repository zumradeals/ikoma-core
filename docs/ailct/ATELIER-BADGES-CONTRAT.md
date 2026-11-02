# ATELIER-BADGES-CONTRAT — Atelier des badges unique : contrat de conception

**Statut : CONTRAT DE CONCEPTION — révision 2, intégrant les décisions du dirigeant sur l'examen du commit `e86ce46`. Aucun développement fonctionnel, aucune migration, aucune fusion, aucun déploiement n'est autorisé par ce document. R5a et R5b sont en HOLD jusqu'à nouvelle autorisation.**
Date de rédaction : 2 novembre 2026 (révision 2 le même jour). Base de code examinée : `main` au commit `65c7e2e` (A07 fusionnée). La PR #80 (R1, registre des personnes) n'est **pas** fusionnée : tout ce qui en dépend est signalé « dépend de R1 ». La PR #60 n'est pas concernée.

Ce document ne présente aucune couleur, maquette, finition ou texte comme officiellement approuvé par l'AILCT.

## 0. Légende

- **[C]** constaté dans le code ou dans le cahier des charges (vérifiable).
- **[D]** décision déjà prise par le dirigeant.
- **[P]** proposition de ce document, à valider.
- **[V]** validation institutionnelle (AILCT) nécessaire.
- **DC-n** décision complémentaire du dirigeant (révision 2) ; **INV-nn** invariant de sécurité ; **TR-nn** exigence de traçabilité ; **I-nn** incompatibilité signalée.

## 1. Décisions validées et principes

### 1.1. Décisions du dirigeant [D]

1. **Étiquettes A4** : conservées comme format de l'Atelier, avec traçabilité.
2. **Modèle générique** : conservé pour les installations non institutionnelles.
3. **Visiteurs institutionnels** : application du modèle **et du format** validés pour leur catégorie ; le portrait n'est pas imposé systématiquement.
4. **Historique des tirages visiteurs** : journal en ajout seul, sans modification des traces antérieures.
5. **Réimpression** : motif obligatoire pour les badges **nominatifs** ; facultatif mais journalisé pour les badges **anonymes réutilisables**.
6. **Cycle de vie des cartes permanentes** : conservé dans la fiche du titulaire. **Toute impression passe par l'Atelier.**
7. **Impression groupée des cartes permanentes** : autorisée dans l'Atelier, avec **vérification individuelle** des cartes.

### 1.1 bis. Décisions complémentaires (examen du commit `e86ce46`) [D]

| N° | Décision |
|---|---|
| DC-1 | **Position du QR configurable par modèle**, avec **conservation des comportements existants par défaut** (QR au recto des badges visiteurs, au verso des cartes permanentes). Pour les modèles AILCT, la position est **soumise à la validation institutionnelle** [V]. |
| DC-2 | **Marquage de l'exposition avant tout aperçu d'un vrai QR**, quel que soit l'écran (atelier, fiche de carte, impression). |
| DC-3 | **Motif obligatoire à chaque nouvelle génération** d'une carte permanente ou d'un badge nominatif, **même si le tirage précédent n'est pas confirmé**. |
| DC-4 | **Activation d'une nouvelle carte permanente uniquement après confirmation humaine de l'impression officielle ET remise explicite.** |
| DC-5 | **Modèle non validé : usage limité à un essai marqué**, jamais une émission officielle. |
| DC-6 | **Architecture générale de l'Atelier unique approuvée**, sous réserve des précisions de ce document. |

### 1.1 ter. Corrections demandées par le dirigeant

1. Les **états de traçabilité** sont séparés sans ambiguïté des **états métier** (§1.4).
2. L'**unicité de l'Atelier** est une **expérience et une interface contractuelle communes**, sans moteur de rendu unique imposé (§3.1 bis).
3. Les **dépendances de R5a et de R5b** sont distinctes (§9.1 et §11).

Les validations de l'AILCT encore nécessaires sont **conservées** (§14).

### 1.2. Principe directeur

Une seule expérience de production des badges, **sans fusion artificielle des règles métier**.

### 1.3. Séparation des notions

| Notion | Contenu | N'est pas |
|---|---|---|
| Modèle graphique | présentation d'une catégorie (couleurs, mise en page, mentions, format), versionnée et validée | une identité, un droit |
| Identité du badge | personne (registre) ou destination nominative ; identifiant imprimé ; jeton QR opaque `IKOMA1:<64 hex>` | une couleur, un droit |
| Cycle de vie | visiteur : disponible → associé → restitué ; carte : active, perdue, volée, révoquée, remplacée [C] ; avec R2 : préparée → remise → activée (« imprimée » n'est **pas** un état métier, §1.4) | une impression |
| Droit d'accès | habilitation explicite (personne × zone × période), politique de zone | une catégorie, une couleur, une impression, une activation de carte |
| Impression | production d'un support ; trois états de trace (§7) | une remise, une activation, une habilitation |

### 1.4. États métier et états de traçabilité : deux familles disjointes

Les deux familles n'ont **aucun état, aucun mot et aucune table en commun**. Un état de traçabilité décrit **ce qui s'est passé lors d'un tirage** ; un état métier décrit **la situation d'un badge ou d'une carte**.

| Famille | États | Où ils vivent | Qui peut les écrire |
|---|---|---|---|
| **Métier — badge visiteur** [C] | Disponible, Associé, Restitué, Annulé, Clos (`BadgeStatus`) ; destination nominative préparée ; réservation à un poste ou à un groupe | `badges`, `badge_assignments` | page Badges, poste d'accueil (`BadgeService`) — **jamais l'Atelier** |
| **Métier — carte permanente** [C] | active, retirée, perdue, volée, révoquée, remplacée (`holder_cards.status`, rang) | `holder_cards` | fiche du titulaire (`CardLifecycle`) — **jamais l'Atelier** |
| **Métier — carte permanente, ajouts de R2** [D, à contractualiser en R2] | préparée, remise, activée, avec marqueur « ancienne carte » pour l'existant | tables de R2 | fiche du titulaire — **jamais l'Atelier** |
| **Métier — droit d'accès** [C] | habilitation (personne × zone × période), politique de zone | `holder_entitlements`, `zone_policies` | administration des habilitations |
| **Traçabilité — tirage** [P] | **document généré → impression demandée → impression confirmée**, avec la nature **officielle** ou **essai** | `badge_print_runs`, `badge_print_events` (ajout seul) | **l'Atelier uniquement** |

Règles :

- **Aucun état de traçabilité n'est un état métier.** « Imprimée » n'est donc **pas** un statut de carte : c'est un fait de traçabilité (« impression confirmée »), consulté mais jamais copié dans un statut.
- **L'Atelier n'écrit que des événements de traçabilité.** Il ne modifie jamais un état métier (INV-04, INV-18).
- **Les états métier peuvent exiger un fait de traçabilité**, sans jamais le produire : l'activation d'une nouvelle carte exige **une impression officielle confirmée** et **une remise explicite** (DC-4) ; ce contrôle est fait **par le service de la fiche**, pas par l'Atelier.
- Un fait de traçabilité n'ouvre **aucun droit d'accès** ; un état métier « activée » n'en ouvre pas non plus sans habilitation.
- Un tirage « essai » ne satisfait **aucun préalable** métier (activation, émission officielle).

## 2. Responsabilités de chaque espace

| Espace | Responsable de | Ne fait jamais |
|---|---|---|
| **Registre des personnes** (R1) | identité (nom, prénom, fonction, qualité, catégorie, engagement, photo) ; import ; clôture | émettre une carte, accorder un accès, imprimer |
| **Fiche du titulaire / Carte** | cycle de vie de la carte : préparer, (R2) remettre/activer, perdre, voler, remplacer, révoquer ; historique | imprimer (l'impression se lance dans l'Atelier) |
| **Badges visiteurs (stock)** | fabriquer les lots, réserver aux postes et groupes, réglages, liste et détail des badges | imprimer, choisir un modèle |
| **Personnalisation des cartes** (R3) | configurer, versionner et valider les modèles par catégorie ; paramètres communs de l'institution | produire des supports |
| **Atelier des badges** | choisir le modèle appliqué, sélectionner, prévisualiser, contrôler avant impression, aligner, imprimer, tracer | fabriquer un lot, réserver un badge, changer un état, activer une carte, accorder un accès |
| **Contrôle au poste** | décider l'accès (QR, habilitation, hors ligne) | lire un modèle graphique |
| **Journal d'audit** | chaîne de traçabilité vérifiable | contenir des noms ou des organisations |

## 3. Parcours

### 3.1. Vue d'ensemble

```
                                   ATELIER DES BADGES  (point d'entrée unique)
          ┌───────────────────────────────────┴───────────────────────────────────┐
     Badges visiteurs                                                      Cartes permanentes
 (lot de stock : plage ou personnes)                                   (une ou plusieurs personnes)
          └───────────────  TRONC COMMUN (§3.4)  ──────────────────────────────┘
   1 Modèle ─ 2 Sélection ─ 3 Aperçu ─ 4 Contrôles ─ 5 Alignement/Format ─ 6 Impression ─ 7 Suivi
```

### 3.1 bis. Ce que signifie « unique »

L'Atelier est **unique comme expérience et comme interface contractuelle**, pas comme moteur de rendu.

- **Unique pour l'utilisateur** : un seul point d'entrée, un seul enchaînement (modèle, sélection, aperçu, contrôles, alignement, impression, suivi), un seul vocabulaire (§7, TR-10).
- **Unique pour le code appelant** : un seul contrat (`ModelResolver`, `PrintSource`, `PrintTrail`, §4.2) que chaque type de badge respecte.
- **Non imposé** : un moteur de rendu commun. Les deux moteurs actuels (`card.blade.php` + `cards.css` pour les badges visiteurs ; `holder-card-recto/verso` + `holder-cards.css` pour les cartes) **peuvent rester distincts**. Leur rapprochement éventuel est une décision de R4, jamais une condition de R5.
- Chaque moteur doit satisfaire le **contrat de rendu** du §4.2 ; la conformité est vérifiée par des tests **par moteur** (§12.2).

### 3.2. Parcours « Badges visiteurs »

```
Page Badges (stock)          Atelier                                         Poste d'accueil
 1. Fabriquer un lot   ─►  A1. Modèle : institutionnel (si la catégorie d'événement est liée à une
 2. Réserver au poste           catégorie AILCT) sinon générique (visiteur / VIP / prestataire)
                            A2. Sélection : événement, lot, plage  [ + personnes si nominatif ]
                            A3. Aperçu fictif (modèle)  ou  aperçu du badge réel (expose le QR)
                            A4. Contrôles : lot ouvert, plage ≤ 1000, nominatif : destinations
                                préparées, postes à synchroniser, autorisation de la politique du client
                            A5. Format (PVC, planche A4, étiquettes A4) + page d'essai d'alignement
                            A6. Document généré → impression demandée → impression confirmée
                            A7. Suivi : historique des tirages du lot (ajout seul)  ─► remise physique
                                                                           association au poste par scan
```
Règles propres [C/D] : le mode standard est anonyme ; le mode nominatif exige les permissions Badges **et** Liste d'invités, l'autorisation de la politique du client et une destination préparée (`BadgePreparation`) ; imprimer n'associe, ne valide et ne fait entrer personne ; le badge se restitue à la sortie ; le même QR sort à chaque réimpression. Pour un badge **nominatif**, chaque génération après la première exige un **motif** (DC-3) ; pour un badge **anonyme réutilisable**, le motif est facultatif mais journalisé.

### 3.3. Parcours « Cartes permanentes »

```
Registre            Fiche du titulaire (cycle de vie)                 Atelier (impression)
 R1 enregistrer ─►  Préparer la carte  ───────────────────────────►  P1. Sélection : une ou plusieurs
 (aucune carte)     (R2) photo obligatoire, catégorie, modèle              cartes préparées (vérification individuelle)
                          validé disponible                          P2. Aperçu fictif / aperçu réel
                                                                     P3. Contrôles par carte (§5.3)
                                                                     P4. Alignement CR80
                                                                     P5. Document généré → impression
                                                                         demandée → confirmée
                    ◄─────────────────────────────────────────────  P6. Suivi (historique des tirages)
                    (R2) Remise explicite, puis activation — seulement après une impression
                         OFFICIELLE CONFIRMÉE (actions de la fiche, jamais de l'Atelier)
                    perte · vol · remplacement · révocation (fiche)
```
Règles propres [C/D] : une personne peut exister sans carte ; une carte peut être préparée sans qu'une impression officielle ait été confirmée, avoir son impression confirmée sans être remise, être remise sans être activée (l'activation exigeant impression confirmée **et** remise, DC-4) ; la catégorie et la couleur ne confèrent aucun droit ; la photo est obligatoire pour une **nouvelle émission** (R2) ; un identifiant, un QR et une version de modèle ne changent jamais en réimpression ; chaque génération après la première du même support exige un **motif**, que le tirage précédent ait été confirmé ou non (DC-3) ; l'activation d'une nouvelle carte exige une impression officielle confirmée puis une remise explicite (DC-4).

### 3.4. Tronc commun

| Étape | Contenu commun | Spécifique visiteurs | Spécifique permanents |
|---|---|---|---|
| Modèle | résolveur de modèle, version, statut | repli générique | modèle validé requis pour une émission officielle |
| Sélection | filtres, plafond, récapitulatif | lot, plage, personnes (nominatif) | personnes du registre, état de la carte |
| Aperçu | recto/verso agrandis, rendu identique à l'impression | — | — |
| Contrôles | rapport de pré-contrôle, blocages affichés | destinations, postes à synchroniser | photo, catégorie, carte préparée, modèle validé |
| Alignement | page d'essai sans QR ni donnée personnelle | formats PVC/A4 | CR80 portrait |
| Impression | page d'impression unique, contrôle de débordement | A4 : planches, étiquettes | CR80 |
| Traçabilité | trois états de trace (§7), distincts des états métier (§1.4) | motif à chaque génération après la première pour un badge nominatif | motif à chaque génération après la première, tirage précédent confirmé ou non |

## 4. Interfaces entre les services existants

### 4.1. Ce qui existe et reste le moteur [C]

| Service / classe | Rôle conservé | Appelé par l'Atelier pour |
|---|---|---|
| `BadgeLots::generate`, `issueSingle`, `reserve*` | fabriquer et réserver | **jamais** (reste dans la page Badges et la fiche) |
| `BadgePreparation::prepare` | destination nominative immuable, incrément `list_revision` | étape « contrôles/sélection » du nominatif |
| `BadgeArtwork` (`validate`, `select`, `card`) | sélection d'une plage, rendu d'un badge visiteur, **marquage `print_exposed_at` avant lecture des jetons** | aperçu réel, impression visiteurs |
| `HolderCards` (`cardData`, `specimen`, `activeCard`, `recordPrint`, `recordPreview`, `savePortrait`) | rendu et historique des cartes | aperçu fictif (`specimen`), aperçu réel, impression |
| `CardLifecycle`, `HolderEntitlements` | perte, vol, remplacement, révocation ; habilitations | **jamais** depuis l'Atelier |
| `QrSvg` | rendu du QR | via les moteurs |
| `Institution` | nom, logo, couleurs | via les moteurs |
| `Audit` | chaîne de traçabilité | traçabilité (§7) |

### 4.2. Nouvelles interfaces [P] (toutes additives, aucune ne remplace un service existant)

```
BadgeKind            : enum { visiteur, permanent }
ModelResolver        : resolve(BadgeKind, sujet) → ResolvedModel { modele_id|null, version|null, statut,
                                                   officiel:bool, format, qr_cote, generique:bool }
PrintSource (par type): candidates(sélection) → liste
                        preflight(sélection) → PreflightReport { bloquants[], avertissements[] }
                        render(élément, mode: modele_fictif | badge_reel, face) → RenderedBadge
                        expose(éléments)  → marque l'exposition (réutilise le marquage existant)
PrintTrail           : generated(sélection, modèle, format, nature: officiel|essai, motif?) → run_id
                        requested(run_id) ; confirmed(run_id, imprimés, gâchés, note?)
RenderedBadge        : fragment HTML aux dimensions physiques fixes ; QR noir sur blanc avec zone calme
                        intacte ; position du QR issue du modèle ; mention « EXEMPLE » ou « ESSAI » ou
                        « PROVISOIRE » quand elle s'applique ; aucune ressource externe
```
- `VisitorPrintSource` enveloppe `BadgeArtwork` + `BadgePreparation` ; `PermanentPrintSource` enveloppe `HolderCards` + l'état de carte de R2.
- `ModelResolver` : pour un badge visiteur, la **catégorie d'événement liée** à une catégorie institutionnelle (`categories.institutional_code`, lien explicite [C], écran « Catégories institutionnelles ») donne le modèle de cette catégorie ; sans lien, le **modèle générique** de l'installation. Pour une carte permanente, la catégorie institutionnelle de la personne donne son modèle. Un modèle institutionnel donne aussi **son format** (décision 3).
- **Contrat de rendu commun** : chaque moteur (visiteurs, cartes) expose `render(élément, mode, face)` et produit un `RenderedBadge` conforme. Les moteurs ne sont ni fusionnés ni remplacés (§3.1 bis).
- **Position du QR (DC-1)** : champ `qr_cote` (recto ou verso) du modèle. Valeur par défaut = comportement existant : **recto** pour le modèle générique visiteur, **verso** pour les cartes permanentes. Pour un modèle AILCT, la valeur validée par l'institution prévaut [V].
- L'Atelier **ne dépend d'aucune décision d'accès** : il ne lit ni habilitation, ni politique de zone, ni état de présence.

### 4.3. Interfaces interdites

L'Atelier n'appelle jamais : `BadgeService` (association, restitution), `SiteControl`, `PermanentAccess`, les services d'habilitation, les routes `/poste/api`. Un test d'architecture le garantit (§12).

## 5. Invariants de sécurité

| N° | Invariant |
|---|---|
| INV-01 | Le format du QR (`IKOMA1:` + 64 hexadécimaux), les codes `B-nnn`, les identifiants imprimés et les jetons ne sont jamais modifiés par l'Atelier. Une réimpression produit le même QR. |
| INV-02 | **Aperçu fictif d'un modèle : aucun véritable QR n'est exposé** ; le QR d'exemple ne correspond à aucun badge (comme `HolderCards::specimen`). Un **essai** (DC-5) utilise lui aussi le QR d'exemple par défaut. |
| INV-03 | **Marquage de l'exposition avant tout rendu d'un vrai QR (DC-2)** : aperçu de l'Atelier, aperçu de la fiche de carte, page d'impression, pour les deux types. Le lot est marqué (`print_exposed_at`) **avant** la lecture des jetons. Aucun chemin de code ne rend un vrai QR sans ce marquage (I-03). |
| INV-04 | Imprimer ne modifie ni statut, ni association, ni réservation, ni habilitation, ni activation, ni catégorie. |
| INV-05 | Toutes les autorisations sont revérifiées **côté serveur** à chaque requête ; l'écran n'est qu'une commodité. Permissions : `badges.gerer` pour l'Atelier ; `listes.importer` en plus pour le nominatif, les personnes et les cartes ; `categories.gerer` pour les modèles (R3). |
| INV-06 | Les pages contenant des QR ou des données personnelles sont servies `no-store, private`, `Referrer-Policy: no-referrer`, avec nonce CSP, jamais mises en cache ni précachées par le service worker. |
| INV-07 | Les données personnelles viennent du registre ou de la fiche au moment du rendu ; **aucune copie supplémentaire** n'est stockée par l'Atelier. |
| INV-08 | Le journal d'audit ne contient ni nom, ni organisation, ni motif libre ; il contient identifiants, numéros de lot, plages, nombres, version de modèle. |
| INV-09 | Les traces (§7) sont en **ajout seul**, imposées par la base (déclencheur), pas seulement par l'application. |
| INV-10 | Une version de modèle utilisée par un tirage est **immuable** ; toute modification crée une nouvelle version à valider (R3). |
| INV-11 | **Modèle non validé : essai marqué uniquement (DC-5).** Le support porte une mention visible (« ESSAI — SANS VALEUR » ou « MODÈLE PROVISOIRE »), le tirage est enregistré avec la nature `essai`, et **jamais** il ne peut servir d'émission officielle, de préalable d'activation ni d'impression « confirmée » au sens du DC-4. Les modèles génériques intégrés des badges visiteurs gardent leur comportement actuel (non institutionnels, officiels par défaut). |
| INV-12 | La photographie n'est imprimée que si son autorisation propre est cochée ; l'accès au portrait est authentifié ; aucun portrait dans un cache public ni dans un snapshot de tablette. |
| INV-13 | Les nombres affichés (imprimés, gâchés, restants) sont **recalculés côté serveur** ; le navigateur ne fait pas foi. |
| INV-14 | Un document généré ou un PDF **n'est jamais présenté comme preuve d'impression physique** (§7). |
| INV-15 | Plafond de 1 000 badges par tirage ; limitation de débit sur les routes d'impression et d'aperçu ; transaction unique par préparation nominative. |
| INV-16 | L'avertissement de synchronisation des tablettes après une préparation nominative reste **bloquant** avant la remise. |
| INV-17 | Les anciennes URL redirigent sans contourner l'authentification ni les permissions de la destination. |
| INV-18 | **L'Atelier n'écrit que de la traçabilité** (§1.4) : aucune écriture dans `badges`, `badge_assignments`, `holder_cards`, `holder_entitlements`, ni dans les tables d'états de R2. |
| INV-19 | **Les préalables d'activation sont contrôlés par le service de la fiche** (impression officielle confirmée + remise explicite), côté serveur, dans la même transaction que l'activation ; l'interface de l'Atelier ne peut pas les contourner. |
| INV-20 | Un tirage `essai` ne peut pas être confirmé comme « officiel » ; sa confirmation ne satisfait aucun préalable. |

## 6. Aperçus

| Aperçu | Contenu | QR | Exposition | Trace |
|---|---|---|---|---|
| **Fictif (modèle)** | données d'exemple, mention « EXEMPLE · SANS VALEUR » | QR d'exemple sans valeur | aucune | aucune |
| **Badge réel** | première ou chaque carte/badge sélectionné, vraies données du registre | vrai QR | **marquage obligatoire avant rendu** | `atelier.apercu_reel` (ajout) |

**Essai marqué (DC-5)** : tirage d'un modèle non validé, avec la mention « ESSAI — SANS VALEUR », données et QR d'exemple (aucun vrai jeton, aucune exposition). Un essai avec de vraies données ou un vrai QR n'est **pas prévu** par ce contrat ; s'il devient nécessaire (test de lecture matériel), il fera l'objet d'une décision séparée et le marquage d'exposition (INV-03) s'y appliquerait.

Même rendu, mêmes feuilles de style et mêmes dimensions que l'impression. Le zoom d'écran ne mesure pas le tirage.

## 7. Exigences de traçabilité

### 7.1. Les trois états de traçabilité (distincts des états métier, §1.4)

| État | Sens | Preuve apportée | Ce que ce n'est PAS |
|---|---|---|---|
| **Document généré** | le serveur a produit la page ou le PDF d'impression pour une sélection | le document existe | une impression |
| **Impression demandée** | un opérateur a lancé l'impression depuis le navigateur | la demande a été faite | un support sorti de l'imprimante (le navigateur ne confirme pas) |
| **Impression physique confirmée** | un opérateur déclare avoir contrôlé les supports : nombre imprimé, nombre gâché | une déclaration humaine tracée | une preuve technique |

### 7.2. Exigences

- **TR-01** Chaque tirage reçoit un `run_id`. Chaque changement d'état est un **événement distinct en ajout seul** : jamais de mise à jour d'une ligne existante.
- **TR-02** Chaque événement porte : type de badge, événement (visiteurs) ou aucun, lot et plage ou personnes, modèle et version, **nature `officiel` ou `essai`**, format, face, nombre, opérateur, horodatage, et le motif quand il est exigé. Aucune donnée personnelle dans le journal d'audit (le motif libre reste dans la table d'historique, comme `holder_card_prints.reason`).
- **TR-03** La confirmation enregistre `imprimés` et `gâchés` (0 ≤ gâchés ≤ générés), l'opérateur et une note facultative. Elle ne peut pas dépasser le nombre généré. Une confirmation ne peut intervenir qu'après une demande.
- **TR-04** Un tirage resté sans confirmation reste affiché comme **« non confirmé »** ; il n'est jamais promu automatiquement.
- **TR-05** **Motif à chaque génération après la première** (DC-3) : pour un badge nominatif (même destination) et pour une carte permanente (même carte), le motif est **obligatoire dès la deuxième génération, que le tirage précédent ait été confirmé ou non** ; la première génération d'un support n'en exige pas. Pour un badge **anonyme réutilisable**, le motif est facultatif mais journalisé. En impression groupée, un motif commun est accepté mais **enregistré pour chaque élément concerné**. Un remplacement de carte crée un nouveau support (sa première génération n'exige pas de motif ; le motif du remplacement est celui de la fiche).
- **TR-06** Les étiquettes A4 suivent les mêmes trois états (elles n'ont aujourd'hui aucune trace, voir I-14).
- **TR-07** Les actions de journal existantes sont conservées telles quelles ; les nouvelles actions s'ajoutent : `atelier.document_genere`, `atelier.impression_demandee`, `atelier.impression_confirmee`, `atelier.apercu_reel`.
- **TR-08** Table d'historique des tirages en ajout seul, verrouillée par déclencheur (aucune mise à jour, aucune suppression, aucun `TRUNCATE` tant qu'elle contient des lignes) sur le modèle de `holder_card_prints`.
- **TR-09** Correspondance avec l'existant [C] : `badge.impression_preparee` (visiteurs), `carte.imprimee` et `carte.reimprimee` (permanents) sont journalisées **à la génération de la page**, avant toute impression réelle. Elles correspondent donc à l'état « document généré » et non à une impression physique ; leur nom est conservé pour la chaîne d'audit, leur sens est précisé ici.
- **TR-10** Vocabulaire à l'écran : « Document prêt », « Impression lancée », « Impression confirmée ». Les mots « imprimé » et « émis » ne s'emploient que pour l'état confirmé.
- **TR-11** Un tirage porte sa **nature** (`officiel` ou `essai`) dès la génération ; elle ne change jamais. Un tirage `essai` n'est jamais promu en officiel.
- **TR-12** Les états de traçabilité ne sont **jamais copiés** dans un état métier ; les services métier **interrogent** la traçabilité (par exemple « existe-t-il une impression officielle confirmée pour cette carte ? ») et gardent la responsabilité de leur propre décision.

## 8. Compatibilité des anciennes URL

| Ancienne URL | Devenir | Condition |
|---|---|---|
| `/administration/badges/modeles` | redirection 302 vers l'Atelier (type visiteurs), paramètres conservés (`?person=`) | mêmes permissions |
| `/administration/badges/modeles/{apercu,personnes,preparer,imprimer}` et `PUT …/modeles` | conservées pendant R5a ; retirées seulement après recette | API appelée par l'écran actuel |
| `/administration/badges/etiquettes` | redirection vers l'Atelier (format étiquettes A4) avec la même sélection | trace ajoutée |
| `/administration/titulaires/{id}/carte/imprimer` | l'écran d'impression passe par l'Atelier (carte présélectionnée) | motif de réimpression conservé |
| `/administration/titulaires/alignement` | redirection vers la page d'essai commune | — |
| `/administration/titulaires/modeles` | page « Personnalisation des cartes » (R3) | hors Atelier |
| `/administration/titulaires/{id}/carte` | **conservée** : cycle de vie et historique | bouton « Ouvrir dans l'Atelier » |

Les `data-testid` utilisés par les tests de navigateur sont conservés ou migrés avec leurs tests. L'ensemble est vérifié par un test de redirections (§12).

## 9. Dépendances et migrations envisagées

### 9.1. Dépendances distinctes de R5a et de R5b

| Lot | Dépend de | Ne dépend PAS de |
|---|---|---|
| **R5a** — socle de l'Atelier et badges visiteurs génériques | A07 (fusionnée) ; migration additive M-R5-1 (traçabilité) | **R1, R2, R3, R4** : le mode nominatif visiteur lit la table `visitors`, jamais le registre des personnes ; le modèle générique existe déjà |
| **R5b** — cartes permanentes et modèles institutionnels | **R5a** (traçabilité, enveloppe d'impression commune) ; **R1** (prénom, nom, qualité, photo dans la fiche) ; **R2** (états de carte, photo obligatoire, remise, activation) ; **R3** (modèles versionnés, statut, valideur, `qr_cote`) ; **R4** (rendu des cartes institutionnelles) | — |

Dépendances croisées à connaître :

- **R2 dépend de la traçabilité de R5a** : l'activation d'une nouvelle carte exige « impression officielle confirmée » (DC-4), un fait que seul le suivi de l'Atelier produit. L'ordre d'exécution ne peut donc pas être R2 avant R5a (I-15).
- **Aucune émission officielle de carte n'est possible avant R3 et la validation institutionnelle d'un modèle** (DC-5) : tant que les modèles AILCT ne sont pas validés, toute carte reste en essai. C'est la conséquence voulue de la règle, pas un défaut.
- **R1** doit être fusionné avant R5b ; la PR #80 n'est pas fusionnée à ce jour.

### 9.2. Migrations additives envisagées (aucune n'est autorisée à ce stade)

| Id | Contenu | Lot |
|---|---|---|
| M-R5-1 | `badge_print_runs` (tirage) et `badge_print_events` (événements d'état), ajout seul, déclencheurs de protection, droits restreints | R5a |
| M-R5-2 | lien de l'événement de tirage vers la version de modèle de R3 (clé étrangère nullable) ; `qr_cote` du modèle appartient à M-R3-x | R5b |
| M-R2-x | états de remise et d'activation des cartes, marqueur « ancienne carte » (les cartes existantes ne sont pas présentées comme une remise constatée), version de modèle dans `holder_card_prints` ; **le préalable d'activation lit la traçabilité de M-R5-1** | R2 (après R5a) |
| M-R3-x | modèles de cartes, versions, validations, paramètres communs | R3 |

Aucune table de personnes n'est créée. Le lien catégorie d'événement ↔ catégorie institutionnelle existe déjà (`categories.institutional_code`).

## 10. Examen des contrats R2, R3, R4 et incompatibilités signalées

Chaque incompatibilité est **signalée avant toute proposition de modification**. Aucune modification n'est engagée.

| N° | Incompatibilité | Constat [C] | Impact | Proposition [P] | Décision |
|---|---|---|---|---|---|
| **I-01** | **QR au verso (cahier) et scan visiteur au recto** | Le cahier « recommande » le QR au verso (§20). Les badges visiteurs actuels portent le QR au **recto** ; un agent devrait retourner un badge dont le QR est au verso. | Débit des contrôles d'entrée | **Tranchée [D] (DC-1)** : QR configurable par modèle ; défaut = comportement existant ; modèles AILCT soumis à validation institutionnelle | **[V]** AILCT pour les modèles AILCT |
| **I-02** | **Le journal appelle « imprimée » une simple génération** | `carte.imprimee` et `badge.impression_preparee` sont écrites à la génération de la page (« il s'agit d'une préparation : le navigateur ne permet pas de confirmer le tirage ») | Un lecteur du journal peut croire à un support sorti | Conserver les noms, préciser le sens (TR-09), ajouter les états demandé/confirmé | [D] |
| **I-03** | **L'aperçu d'une carte réelle ne marque pas l'exposition** | La page Carte rend le **vrai QR** du verso (`cardData`) ; seul `recordPrint` marque le lot. L'atelier visiteurs marque dès l'aperçu. | Un lot peut rester supprimable alors que son QR a été affiché | **Tranchée [D] (DC-2)** : marquage avant tout aperçu d'un vrai QR (INV-03) ; changement plus restrictif, sans effet sur les accès | décidée |
| **I-04** | **L'aperçu visiteur est toujours un badge réel** | `BadgeArtwork::select` prend le premier badge de la plage et marque le lot ; il n'existe pas d'aperçu fictif visiteur | Choisir un modèle expose un vrai QR | Mode « modèle fictif » sans lecture de lot (INV-02) | à valider |
| **I-05** | **Les modèles originaux ne sont pas disponibles** | Le contrat A01 cite « 24 images de modèles (8 × recto/verso/assemblage) » ; le dépôt n'en contient aucune ; la fiche de relevé de l'A02 (§6.5) n'est pas remplie | Aucune couleur, aucune mise en page ne peut être déclarée conforme | Le relevé devient un livrable préalable à R3 ; en attendant, tout modèle reste « provisoire » | **[V]** AILCT |
| **I-06** | **« Émission » est consommée à la génération** | `recordPrint` classe le premier tirage généré comme « émission » et exige un motif dès le suivant, même si le premier n'a jamais été imprimé | Une impression ratée oblige à motiver | **Tranchée [D] (DC-3)** : motif obligatoire à chaque génération après la première, confirmée ou non ; règle étendue aux badges nominatifs (TR-05). Le contrat A05 n'est pas modifié (la règle existante est conservée et étendue) | décidée |
| **I-07** | **R2 : activation et impression physique** | L'impression physique n'est pas observable | Une carte pourrait être activée sans impression | **Tranchée [D] (DC-4)** : activation d'une nouvelle carte seulement après impression officielle confirmée par un humain **et** remise explicite ; anciennes cartes préservées sans présenter une remise comme constatée | décidée (détails dans le contrat R2) |
| **I-08** | **Logo institutionnel en bitmap** | Le logo est un PNG/JPEG de 32 à 4 000 pixels, stocké en PNG ; les badges visiteurs imposent le logo IKOMA en dur ; ni le verso, ni les étiquettes ne portent de logo | Logo flou à l'impression à faible résolution ; logo non institutionnel sur les badges visiteurs | Résolution minimale conseillée affichée à l'envoi ; logo de l'installation sur tous les rendus ; SVG à étudier | **[V]** logo officiel à fournir |
| **I-09** | **R1 non fusionné** | `full_name` unique dans `main` | Pas de prénom/nom séparés pour le rendu | R5 ne démarre qu'après la fusion de R1 | dépendance |
| **I-10** | **Identifiant imprimé** | Le cahier cite `AILCT-2026-XXXXXX` ; le produit imprime `ID-nnnnnn-k` (préfixe, largeur, clé configurables) | Écart d'aspect, aucun écart technique | Conserver les identifiants historiques [D] ; format éventuel par décision séparée | décision séparée |
| **I-11** | **Formats : visiteurs (A4, paysage) et modèle institutionnel (CR80 portrait)** | L'atelier visiteurs propose PVC ou A4, portrait ou paysage | Un modèle institutionnel validé impose son format ; le générique garde ses choix | Le format vient du modèle (décision 3) ; les options hors modèle sont masquées | [D] |
| **I-12** | **Permissions des étiquettes** | `BadgeLabelsController` exige `badges.gerer` seulement, sans contrôle de l'ouverture de l'événement ni trace | Faille de traçabilité | Mêmes contrôles et mêmes traces que les autres formats | [D] |
| **I-13** | **Verso visiteur générique** | Consignes de visite génériques, non validées par le client | Mention institutionnelle absente du verso | Le verso vient du modèle validé ; générique sinon | **[V]** |
| **I-14** | **Étiquettes sans aucune trace** | Aucune action de journal, mais marquage de l'exposition | Tirage non traçable | TR-06 | [D] |
| **I-15** | **Dépendance circulaire apparente R2 ↔ R5a ↔ R3** | L'activation (R2) exige une impression officielle confirmée (R5a) avec un modèle validé (R3) | Aucune carte ne peut être activée officiellement avant R5a, R3 et la validation d'un modèle | R5a avant R2 ; R2 lit la traçabilité ; émission officielle impossible tant qu'aucun modèle n'est validé (DC-5) | décidée ; séquence à confirmer |
| **I-16** | **« Un seul Atelier » pouvait être lu comme « un seul moteur de rendu »** | Deux moteurs de rendu existent | Fusion forcée risquée (régression des deux rendus) | Unicité = expérience et interface contractuelle (§3.1 bis) ; moteurs distincts autorisés | **Tranchée [D]** |
| **I-17** | **Essai et vrai QR** | Un essai avec un vrai QR créerait un support lisible sans valeur officielle | Confusion à l'accueil, exposition | Essai = données et QR d'exemple uniquement (§6) ; essai matériel de lecture à décider séparément | à décider si besoin |

## 11. Séquencement R5a / R5b

**Tous les lots sont en HOLD** jusqu'à nouvelle autorisation du dirigeant.

### 11.1. Ordre d'exécution proposé [P]

```
R1 (PR #80) ──────────────────────────────────────────────────┐
R5a (trace + Atelier visiteurs génériques) ─► R2 ─► R3 ─► R4 ─► R5b
```
R5a ne dépend ni de R1 ni de R2 à R4 : il peut précéder ou suivre R1. **R2 dépend de R5a** (traçabilité nécessaire à l'activation). R5b dépend de tous les autres.

### 11.2. R5a — socle de l'Atelier et badges visiteurs génériques
**Dépendances :** A07 (fusionnée), migration M-R5-1. **Aucune** dépendance à R1, R2, R3 ni R4.

Contenu :
- Enveloppe d'impression commune : barre d'outils, consignes, contrôle de débordement, nonce, en-têtes (les **moteurs de rendu restent distincts** ; §3.1 bis).
- Marquage d'exposition factorisé (une seule implémentation, comportement identique ; DC-2) et aperçu fictif visiteur sans QR réel.
- Trace des trois états (M-R5-1), motif obligatoire à chaque génération après la première pour un badge nominatif (DC-3), motif facultatif journalisé pour l'anonyme.
- Étiquettes A4 intégrées au même suivi ; page d'alignement commune.
- Atelier « visiteurs » avec le modèle générique et le QR au recto (comportement actuel, DC-1) ; redirections des anciennes URL visiteurs.

**Critères de sortie R5a :** aucune régression sur les tests visiteurs existants ; trois états tracés et séparés des états métier ; étiquettes tracées ; aperçu fictif sans jeton lu ; migration additive, réversible à vide ; aucune écriture de l'Atelier dans un état métier.

### 11.3. R5b — cartes permanentes et modèles institutionnels
**Dépendances :** R5a, **R1**, **R2**, **R3**, **R4**.

Contenu :
- Type « cartes permanentes » : sélection groupée avec **vérification individuelle**, contrôles photo, catégorie, modèle, état de la carte ; motif à chaque génération après la première (DC-3).
- `ModelResolver` complet : modèles validés, version tracée, format et `qr_cote` du modèle ; essai marqué pour un modèle non validé (DC-5).
- Visiteurs institutionnels : modèle, format et position du QR du modèle validé de leur catégorie.
- Marquage d'exposition dans la fiche de carte (I-03).
- Redirections des anciennes URL permanentes ; la fiche garde le cycle de vie ; les préalables d'activation (DC-4) sont contrôlés par la fiche (R2).

**Critères de sortie R5b :** aucune émission officielle sans modèle validé ; cartes déjà émises valides et réimprimables ; impression ≠ remise ≠ activation ≠ habilitation vérifié par test ; essai jamais promu en officiel.

### 11.4. Option indépendante
Factoriser l'enveloppe d'impression et le marquage d'exposition, à comportement identique, peut être réalisé indépendamment de R2.

## 12. Critères de validation et de non-régression

### 12.1. Tests existants à conserver verts

`BadgeWorkshopTest`, `BadgeLifecycleTest`, `BadgeConcurrencyTest`, `BadgeIndividualDeletionTest`, `HolderCardsTest`, `PermanentHoldersTest` (test d'architecture), `PermanentAccessTest`, `RecetteA07Test` (matrice des permissions sur **toutes** les routes à permission) ; E2E : `badge-workshop`, `badges`, `cartes`, `consolidation`, `manual-visitors`, `controle`, `poste`.

### 12.2. Nouveaux tests exigés

| Famille | Exemples |
|---|---|
| Contrats | même QR avant et après réimpression ; mêmes états de badge ; aucun statut, association, habilitation ni activation modifié par un tirage |
| Exposition | aperçu fictif : aucun jeton lu, lot non marqué ; aperçu réel : lot marqué avant rendu, pour les deux types |
| Traçabilité | trois états ; événements en ajout seul (`UPDATE`/`DELETE`/`TRUNCATE` refusés) ; confirmation ≤ généré ; tirage non confirmé affiché ; journal sans donnée personnelle |
| Règles | motif obligatoire nominatif, facultatif mais journalisé anonyme ; motif dès le 2ᵉ tirage de carte ; modèle non validé marqué et non officiel |
| Sécurité | permissions serveur par rôle ; en-têtes `no-store` et CSP ; débit limité ; plafond 1 000 ; accès direct aux anciennes routes |
| Architecture | l'Atelier n'appelle ni `BadgeService`, ni `SiteControl`, ni habilitations |
| Compatibilité | redirections 302 avec paramètres ; ancienne API visiteurs encore opérationnelle en R5a ; `data-testid` migrés |
| Rendu | aperçu = impression (même vue, mêmes dimensions) ; QR décodable après rendu (jsQR) pour les deux types ; contrôle de débordement ; matrice des 8 catégories × recto/verso |
| États | tableau de séparation §1.4 : aucune écriture de l'Atelier dans un état métier ; aucun mot de traçabilité dans un statut ; un tirage `essai` ne satisfait aucun préalable |
| Décisions | DC-1 : défaut conservé (recto visiteur, verso carte) ; DC-2 : aucun chemin de rendu d'un vrai QR sans marquage préalable (fiche de carte comprise) ; DC-3 : motif exigé à la 2ᵉ génération, tirage précédent non confirmé inclus ; DC-4 : activation refusée sans impression officielle confirmée et sans remise explicite ; DC-5 : modèle non validé = essai marqué, nature `essai` enregistrée |
| Conformité par moteur | chaque moteur de rendu (visiteurs, cartes) satisfait le contrat `RenderedBadge` : dimensions, zone calme du QR, mentions, aucune ressource externe |

### 12.3. Réserves matérielles

L'impression réelle (imprimante PVC, alignement recto-verso, lecture du QR imprimé, tenue du support) **reste non testée sur matériel réel** ; elle fait l'objet d'une recette matérielle à part. Le cahier exige d'ailleurs une démonstration de lecture du QR avant validation définitive (§14 du cahier).

## 13. Audit graphique transversal

Référence : cahier des charges AILCT, 14 pages (« document de travail »). Seul ce qui est écrit « devra » est une exigence ; le reste est une recommandation.

### 13.1. Les huit catégories et leurs couleurs

| N° | Catégorie | Fond demandé | Mention demandée | Teinte de cellule relevée dans le tableau du cahier (indicative) | Couleur enregistrée aujourd'hui |
|---|---|---|---|---|---|
| 01 | Personnel de l'AILCT | Vert | PERSONNEL AILCT | `#D9EAD3` | `#64748B` (neutre) |
| 02 | Partenaires permanents | Orange | PARTENAIRE PERMANENT | `#FCE4D6` | `#64748B` |
| 03 | Intervenants occasionnels | Rouge | INTERVENANT | `#F4CCCC` | `#64748B` |
| 04 | Délégations officielles | Or (finition éventuellement métallisée) | DÉLÉGATION OFFICIELLE | `#FFD966` | `#64748B` |
| 05 | Visiteurs | Argent | VISITEUR | `#E7E6E6` | `#64748B` |
| 06 | Accompagnateurs | Bronze | ACCOMPAGNATEUR | `#D9C2A6` | `#64748B` |
| 07 | Stagiaires | Bleu | STAGIAIRE | `#CFE2F3` | `#64748B` |
| 08 | Experts | Jaune | EXPERT | `#FFF2CC` | `#64748B` |

- Le cahier ne donne **que des noms de couleurs** ; aucune valeur RVB ni CMJN officielle. Les pastilles du tableau et les teintes des cellules ne coïncident pas (exemple : la pastille « Jaune » est un orange vif, la cellule est un jaune très pâle) : **la couleur exacte est ambiguë et doit être fournie par le client [V]**. Les teintes ci-dessus sont des **relevés indicatifs**, pas des valeurs officielles.
- Où les couleurs sont définies aujourd'hui [C] : (1) `institutional_categories.color`, huit lignes neutres modifiables ; (2) `categories.color` par événement (couleur libre, copiée à la création de la catégorie liée) ; (3) deux couleurs libres par événement dans l'atelier visiteurs (`#0066B3` et `#F75C03` par défaut, couleurs IKOMA) ; (4) l'encre du texte est choisie automatiquement selon la couleur (`inkFor`). Ces trois lieux ne communiquent pas.
- Une couleur ne confère jamais un droit [D].

### 13.2. Exigences du cahier face au produit actuel

| Exigence du cahier | Niveau | État actuel [C] | Écart / action | Prio. | Validation |
|---|---|---|---|---|---|
| Logo officiel de l'AILCT | obligatoire | recto des cartes : logo de l'installation ; **visiteurs : logo IKOMA en dur** ; verso, étiquettes, alignement : aucun | logo de l'installation partout (I-08) | P1 | **[V]** logo |
| Mention « ACADÉMIE INTERNATIONALE DE LUTTE CONTRE LE TERRORISME » | obligatoire | recto : nom court ; nom complet au verso seulement | nom complet sur le recto (R4) | P1 | [V] |
| Mention « AILCT » | éventuelle | nom court affiché | — | P3 | — |
| Catégorie du porteur | obligatoire | libellé pluriel du référentiel ; couleur en bandeau de 15 mm | mention au singulier par catégorie ; couleur comme élément dominant (§20) | P1 | [V] |
| Photographie d'identité | obligatoire | facultative | obligatoire pour une nouvelle émission (R2) [D] | P1 | — |
| Nom et prénom | obligatoire | `full_name` ; séparés après R1 | rendu prénom/nom (R4) | P1 | — |
| Fonction / qualité | obligatoire | fonction enregistrée, **non imprimée** ; qualité après R1 | imprimer fonction ou qualité (R4) | P1 | [V] règle d'affichage |
| Numéro d'identification unique | obligatoire | imprimé (`ID-nnnnnn-k`) | format historique conservé [D] (I-10) | P3 | décision séparée |
| QR code sécurisé | obligatoire | jeton opaque, noir sur blanc, sans donnée personnelle ; **recto visiteurs, verso cartes** | position par modèle (I-01) | P1 | **[V]** |
| Éléments graphiques de sécurité | obligatoire | niveau 1 : QR, numéro, photo, logo, couleur ; niveau 2 (hologramme, microtexte, UV, filigrane) : aucun | microtexte possible au rendu ; le reste relève de la production physique | P2 | [V] |
| Format ID-1 vertical, 85,60 × 53,98 mm | recommandé | CR80 portrait pour les cartes ; visiteurs portrait ou paysage | format imposé par le modèle institutionnel (I-11) | P2 | [V] |
| Coins arrondis, épaisseur, finition, impression recto-verso | recommandé | hors logiciel ; recto-verso par deux pages | page d'alignement pour vérifier ; reste matériel | P3 | matériel |
| Validité (« 01/01/2027 – 31/12/2027 ») | affichable (temporaires) | **non imprimée sur les cartes** ; période seulement en visiteur nominatif | imprimer la validité quand elle s'applique (R4) | P1 | [V] |
| Maquette validée **pour chacune des 8 catégories** avant production en série | obligatoire | une seule case globale `carte_modele_valide` | validation, version, date et valideur **par modèle** (R3) | P1 | **[V]** |
| Démonstration de lecture du QR avant validation | obligatoire | test automatisé sur rendu ; pas de scan matériel | recette matérielle | P1 | matériel |
| Cordon, porte-badge | recommandé | hors périmètre logiciel | — | — | — |

### 13.3. Modèles

- Un seul gabarit de carte pour les huit catégories ; seule la bande d'en-tête change de couleur. Un seul jeu de mentions du verso (deux lignes, avis, contact) pour toutes les catégories.
- Visiteurs : trois modèles (visiteur, VIP, prestataire/stagiaire) sans lien avec les huit catégories ; mention « STAGIAIRE » déduite d'un mot du nom de catégorie.
- **Rien n'est marqué officiel** : tout est « provisoire » tant que R3 n'est pas livré et validé.

### 13.4. Aperçus et PDF

- **Aperçu = impression** : même vue et même feuille de style (`holder-card-*` + `holder-cards.css` ; `card.blade.php` + `cards.css`), dimensions physiques fixes ; vérifié par E2E (décodage du QR de l'aperçu et de la page d'impression, génération d'un PDF avec `preferCSSPageSize` pour les cartes ; décodage jsQR pour les visiteurs).
- **Il n'existe pas de génération de PDF côté serveur** [C] : l'impression est celle du navigateur. Le PDF des tests sert de contrôle, jamais de preuve d'impression physique (INV-14).
- L'aperçu écran est à taille réelle (petit) ; pas de zoom. L'aperçu fictif n'existe que pour les cartes (huit exemples d'un coup).
- Les cartes passent par une page d'alignement ; les badges visiteurs n'en ont pas ; les étiquettes non plus.

### 13.5. Pages concernées

| Page | Logo | Couleur de catégorie | Modèle | Aperçu | Impression | Trace |
|---|---|---|---|---|---|---|
| Atelier visiteurs (V3) | IKOMA en dur | 2 couleurs libres | générique | badge réel | PVC / A4 | journal (génération) |
| Étiquettes A4 (V2) | aucun | couleur de la catégorie d'événement | — | aucun | A4 | **aucune** |
| Badges (V1) | — | liste | — | — | liens | — |
| Carte du titulaire (P1) | installation | bandeau neutre | unique | carte réelle (vrai QR) | CR80 | historique des tirages (génération) |
| Modèles de cartes (P2) | installation | neutre | 8 exemples | fictif | — | audit de réglage |
| Alignement (P3) | — | — | — | — | CR80 | aucune |
| Contrôle au poste | — | catégorie d'événement pour les visiteurs ; **rien pour les titulaires permanents** | — | — | — | décisions |

### 13.6. Recommandations de l'audit [P]

1. Obtenir du client : couleurs exactes, logo officiel (si possible vectoriel), originaux des 24 modèles, textes des mentions, règle d'affichage fonction/qualité, position du QR **[V]**.
2. Définir couleurs, mention et finition **dans le modèle de chaque catégorie** (R3), source unique ; supprimer les doublons de couleur.
3. Rendu des cartes (R4) : logo, nom complet, prénom/nom, fonction/qualité, validité, catégorie en couleur dominante. Aucun moteur de rendu unique n'est imposé avec les badges visiteurs (§3.1 bis).
4. Afficher la catégorie et sa couleur au poste de contrôle pour les titulaires (lot R5b/contrôle, changement additif de la réponse de contrôle).
5. Rendre toute impression traçable en trois états (§7).

## 14. Décisions et validations restantes

### 14.1. Tranchées par le dirigeant (révision 2)
DC-1 à DC-6 (§1.1 bis) ; unicité de l'Atelier comme expérience et interface (I-16) ; dépendances distinctes de R5a et R5b (§9.1).

### 14.2. Validations institutionnelles (AILCT) conservées [V]

| Sujet |
|---|
| couleurs exactes des huit catégories (RVB/CMJN) ; le cahier ne donne que des noms |
| logo officiel de l'AILCT (si possible vectoriel) |
| originaux des 24 modèles (8 catégories × recto, verso, assemblage) et fiche de relevé (A02 §6.5) |
| position du QR pour chacun des modèles AILCT (DC-1) |
| textes des mentions institutionnelles, mention de catégorie au singulier, règle d'affichage fonction/qualité |
| éléments de sécurité graphiques à intégrer côté logiciel |
| **validation officielle de chaque maquette** avant toute émission officielle (cahier §11) ; démonstration de lecture du QR (cahier §14) |
| imprimante et consommables (essai matériel) |

### 14.3. Points techniques encore ouverts [P]

| Sujet |
|---|
| détail de l'activation en R2 : une ou deux actions (remise puis activation) dans la fiche ; durée d'affichage d'un tirage « non confirmé » |
| confirmation de l'ordre R5a avant R2 (I-15) |
| statut des modèles génériques intégrés (officiels par défaut pour les badges visiteurs) et absence de modèle générique de carte permanente |
| résolution minimale du logo ; plafond d'impression groupée de cartes |
| besoin éventuel d'un essai avec vrai QR pour un test de lecture matériel (I-17) |

## 15. Limites de ce document

- Il décrit le **contrat** ; il n'implémente rien. Les noms de tables, de classes et d'actions du journal restent des propositions de conception : le dirigeant a validé l'architecture générale et les décisions DC-1 à DC-6, mais n'a pas autorisé l'implémentation (HOLD sur R5a et R5b).
- Les affirmations sur le code sont tirées de la lecture de `main` (65c7e2e) ; elles n'ont pas été rejouées sur matériel.
- Les couleurs relevées sur le cahier sont indicatives et ne constituent pas une validation institutionnelle.

## 16. Historique des révisions

| Révision | Date | Contenu |
|---|---|---|
| 1 | 2 novembre 2026 | Contrat initial (`e86ce46`) : responsabilités, parcours, interfaces, invariants, traçabilité, dépendances, séquencement, audit graphique, incompatibilités I-01 à I-14 |
| 2 | 2 novembre 2026 | Décisions DC-1 à DC-6 ; séparation des états métier et des états de traçabilité (§1.4) ; unicité de l'Atelier comme expérience et interface contractuelle (§3.1 bis) ; dépendances distinctes de R5a et R5b (§9.1, §11) ; incompatibilités I-15 à I-17 ; I-01, I-03, I-06, I-07 tranchées ; validations AILCT conservées (§14.2) |
