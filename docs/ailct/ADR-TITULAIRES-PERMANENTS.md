# ADR — Titulaires permanents (AILCT-A02.2, chantier 1)

| Champ | Valeur |
|---|---|
| Statut | **Proposée — en attente d'approbation du dirigeant.** Aucune variante n'est approuvée tant que la décision D-07 n'est pas prise. |
| Portée | Documentaire. Aucun code, aucune migration, aucune permission, aucune donnée. |
| Base | `main` au commit `9a7729f`. |
| Contrats référencés (non dupliqués) | `A02-BADGES-INSTITUTIONNELS.md` §15 (objets O1–O5, invariants **INV-P01 à P12**, tests **TA-P01 à P13**) et §16 (invariants INV-H) ; `A02-PLAN-IMPLEMENTATION.md` §8 (décisions) et §12 (matrice) — révision R01, PR n° 62, commit `e3af8bf` ; `AILCT-BADGE-001` et `A01-ARCHITECTURE-ET-COMPATIBILITE.md` (PR n° 61). Ces fichiers ne sont pas encore sur `main` : les renvois visent les branches de ces PR. |
| Documents frères | `POLITIQUE-CONTROLE-HORS-LIGNE.md`, `REGISTRE-ARBITRAGES-A02-2.md` |

Légende : **[C]** constat dans le code de `main`, **[H]** hypothèse, **[D-nn]** décision à arbitrer. Les noms de tables et de colonnes cités sont **indicatifs** ; aucun n'est arrêté.

## 1. Question tranchée par cet ADR

Comment représenter le personnel et les partenaires permanents de l'AILCT (catégories AILCT-01 et AILCT-02) alors que le produit rattache aujourd'hui toute personne, toute carte et tout contrôle à **un événement** ?

Les exigences à satisfaire sont celles du contrat R01 : cinq objets distincts (titulaire, carte, passage, autorisation, validité), les invariants INV-P01 à P12, et en particulier **INV-P01 : la sortie quotidienne ne restitue, n'annule ni ne désactive la carte**.

## 2. Constats sur le moteur actuel [C]

| # | Constat | Source | Conséquence pour les permanents |
|---|---|---|---|
| K1 | Une personne n'existe que comme fiche `visitors` **d'un événement** (`event_id`). | `docs/schema.md` | Pas de personne hors événement. |
| K2 | Un badge a un `event_id` ; le code `B-nnn` est unique **par événement** ; le jeton QR est unique globalement. | `BadgeLots`, `BadgeService` | La carte est liée à un événement ; le jeton, lui, est global. |
| K3 | La recherche d'un badge par **jeton** est globale ; par **code**, filtrée par événement. | `BadgeService::controler` (l. 147-154) | Une carte d'un autre événement est retrouvable puis refusée. |
| K4 | Un badge d'un autre événement que celui du poste est refusé (`autre_evenement`). | `SiteControl.php:39` | Point précis à adapter pour une carte permanente. |
| K5 | Les autorisations de zones viennent de la **catégorie d'événement** de la fiche (`category_zone`). | `SiteControl::control`, `docs/schema.md` | INV-P03 est déjà vrai si la participation est une fiche d'événement. |
| K6 | La **sortie** passe la fiche à `sorti` **et restitue** le badge dans la même transaction. | `SiteControl.php:107-127` | Contradiction directe avec INV-P01. À corriger quelle que soit la variante. |
| K7 | Une fiche `present` ou `sorti` ne peut pas être de nouveau admise (`deja_present`), sauf indicateur explicite. | `Desk.php:336` | La ré-entrée du lendemain d'un permanent est bloquée. |
| K8 | La présence est calculée **par événement** à partir du dernier passage `entree`/`sortie` de chaque fiche. | `Presence::count` | Une fiche de participation par événement donne une présence correcte sans changement. |
| K9 | L'instantané d'une tablette est calculé pour **l'événement du poste** (`devices.event_id`). | `ListSnapshot.php` | Une carte permanente devra y figurer pour les seuls titulaires attendus. |
| K10 | Clôture, archivage et anonymisation s'appliquent à un **événement** ; la conservation anonymise les fiches échues. | `EventAvailability`, `DataRetention`, `docs/LOT33_CONTRATS.md` | Il faut protéger ce qui est permanent (INV-P10). |
| K11 | L'annulation est irréversible ; la destination nominative d'une carte est immuable par déclencheur en base. | `BadgeService::annuler`, `BadgePreparation` | Cohérent avec INV-P04 à P06 : à conserver. |
| K12 | Précédent de migration additive dont le retour est refusé après usage. | migration `2026_11_13_…profile_portraits…` | Modèle à suivre pour la réversibilité. |

## 3. Les quatre éléments communs à toutes les variantes

Quelle que soit la variante, ces points **ne dépendent pas du choix** et sont donc exclus de la comparaison :

1. **Correction de la sortie (K6, INV-P01)** : une carte permanente est identifiée par un **marqueur explicite** (jamais la couleur, le code ni le nom de catégorie, INV-P09) ; sa sortie n'appelle pas `restituer`.
2. **Ré-entrée (K7)** : la fiche d'un permanent peut être de nouveau admise après une sortie.
3. **Catégorie institutionnelle sans droit** (INV-P03) : les droits viennent de la catégorie d'événement.
4. **Contrat hors ligne** (INV-H) : appliqué tel quel, y compris à la carte permanente.

## 4. Les variantes étudiées

Elles reprennent les variantes C1, C2 et C3 de `A02-BADGES-INSTITUTIONNELS.md` §15.5. Pour éviter toute ambiguïté, chacune est précisée ici par un schéma de données **indicatif**.

### 4.1. C1 — Registre léger + contexte permanent pour les cartes

- **Titulaire** : nouvelle entité de registre, hors événement (identité, fonction, organisation, code de catégorie institutionnelle, état Actif/Clos, début et fin d'engagement).
- **Participation** : c'est la **fiche `visitors` existante** d'un événement, avec une **référence nullable** vers le titulaire. Elle porte, comme aujourd'hui, la catégorie d'événement, les dates de validité et le statut de présence. Elle copie l'identité du titulaire à sa création.
- **Contexte permanent** : un événement porteur d'un **indicateur explicite « permanent »** (colonne additive). Il héberge le stock de cartes, les lots, la catégorie et les zones du site de l'Académie. Il est **protégé** contre la clôture, l'archivage et l'anonymisation (garde en service **et** en base, sur le modèle des déclencheurs existants).
- **Carte permanente** : une carte du contexte permanent. Son caractère permanent résulte de l'**indicateur explicite de l'événement** qui la porte (INV-P09). Son jeton et son code `B-nnn` sont ceux du moteur actuel.
- **Contrôle dans un autre événement** : si le poste est d'un événement E1 et la carte d'un contexte permanent E0, le contrôle cherche l'**unique participation** du titulaire dans E1 ; il en tire catégorie, validité et zones. Absence de participation → refus `participation_absente`. Le reste de la chaîne (statut de la carte, annulation, hors ligne) est inchangé.
- **Sortie** : fait enregistré, fiche de participation passée à `sorti`, **carte inchangée**.

### 4.2. C2 — Cartes et titulaires hors de tout événement

- Titulaire **et carte** existent hors événement : la carte n'a plus d'événement d'origine (colonne d'événement rendue nullable ou nouvelle entité de cartes).
- Le contrôle de **n'importe quel** événement accepte toute carte active dont le titulaire a une participation couvrant l'instant.
- Les lots, réservations de poste, atelier d'impression, instantanés tablette, références composites et déclencheurs existants, tous bâtis sur la paire (événement, badge), sont à reprendre.

### 4.3. C3 — Registre pour l'identité, une carte par événement

- Titulaire en registre ; participation = fiche d'événement ; **une carte distincte est émise pour chaque événement**.
- Le contrôle n'est pas modifié (sauf points communs de la section 3).
- Le permanent change de carte à chaque événement.

### 4.4. Rappel des options écartées par la revue R01

- **A — événement permanent seul, sans registre** : pas de séparation entre titulaire et fiche d'événement ; chaque événement ordinaire exigerait de dupliquer le personnel ; risque d'effacement par la conservation. Écartée par R01.
- **B — registre complet sans contexte** : revient en pratique à C2.

## 5. Matrice comparative

Notation qualitative de 1 (défavorable) à 5 (favorable), **avis de conception, non mesure**. Le total n'est pas pondéré : la lecture se fait critère par critère, et les critères éliminatoires priment (section 5.2).

### 5.1. Critères demandés

| # | Critère | C1 | C2 | C3 |
|---|---|:-:|:-:|:-:|
| 1 | Séparation titulaire / carte / passage / autorisation | **4** | **5** | **3** |
| 2 | Ampleur des changements du modèle de données | **4** | **2** | **4** |
| 3 | Compatibilité avec F01–F09 | **4** | **3** | **4** |
| 4 | Impact sur le moteur de contrôle actuel (moins = mieux) | **3** | **1** | **5** |
| 5 | Risque de doublons et de divergence d'identité | **3** | **4** | **2** |
| 6 | Conservation et anonymisation | **3** | **4** | **3** |
| 7 | Migrations et retour arrière | **4** | **2** | **4** |
| 8 | Risque de régression et maintenabilité | **3** | **2** | **4** |
| 9 | Cohérence métier (usage réel du permanent) | **4** | **5** | **1** |
| | **Total (indicatif, non pondéré)** | **32** | **28** | **30** |

### 5.2. Critères éliminatoires

| Critère éliminatoire | C1 | C2 | C3 |
|---|---|---|---|
| **E1** — Respecte INV-P01 à P12 (R01) | Oui, avec les garde-fous de la section 7 | Oui | Oui |
| **E2** — Une carte permanente n'est **pas réémise à chaque événement** (usage du personnel et des partenaires permanents) **[H, à confirmer par le client]** | Oui | Oui | **Non** |
| **E3** — Aucune réécriture des cartes, jetons, associations ni historiques existants (V-BD-11) | Oui | Oui, mais avec une migration structurelle de `badges` | Oui |
| **E4** — Installation non-AILCT strictement inchangée (V-ID-11) | Oui (colonnes nullables, indicateur à faux) | À démontrer (structure modifiée) | Oui |

**Lecture.** C3 est la plus simple techniquement mais **échoue à E2** : elle déplace la complexité vers l'exploitation (une carte neuve par événement pour chaque permanent, identifiant imprimé instable, coûts d'impression et risque de perte multipliés). C2 est la plus fidèle au principe « le permanent ne dépend pas d'un événement » mais modifie la **paire (événement, badge)** sur laquelle reposent lots, réservations, instantanés, atelier, références composites et déclencheurs : c'est le plus grand risque de régression. **C1 est le compromis qui satisfait E1 à E4 avec la plus petite surface de changement.**

## 6. Analyse détaillée par critère

### 6.1. Séparation des cinq objets

| Objet | C1 | C2 | C3 |
|---|---|---|---|
| O1 Titulaire | Registre, hors événement | Registre, hors événement | Registre, hors événement |
| O2 Carte | Dans le contexte permanent (événement protégé) | Hors événement | Dans chaque événement |
| O3 Passage | Rattaché à l'événement **contrôlé** et à la fiche de participation | Idem | Idem |
| O4 Autorisation | Participation → catégorie d'événement → zones (inchangé) | Idem | Idem |
| O5 Validité | Engagement (O1), participation (dates de la fiche), carte (annulation) | Idem | Idem |

C1 garde une dépendance de la carte à un événement, mais **cet événement est un contexte d'hébergement protégé**, non un événement d'exploitation : l'**état permanent du titulaire** ne dépend pas de lui (il vit dans le registre). C'est une dépendance **résiduelle, assumée et bornée** — à la différence de l'option A, qui portait aussi l'identité.

### 6.2. Changements du modèle de données (indicatifs)

| Changement | C1 | C2 | C3 |
|---|---|---|---|
| Table de registre des titulaires (+ mémoire des identifiants imprimés émis) | Oui | Oui | Oui |
| Référence nullable titulaire sur `visitors` + unicité (titulaire, événement) | Oui | Oui | Oui |
| Indicateur « permanent » sur `events` | **Oui** | Non | Non |
| Carte sans événement (colonne nullable ou nouvelle entité) + adaptation des références composites, déclencheurs, réservations, lots | Non | **Oui, lourd** | Non |
| Marqueur de carte permanente | Dérivé de l'indicateur d'événement | Colonne ou entité propre | Colonne propre |
| Catégorie institutionnelle sur `categories` (déjà prévue par A02) | Oui | Oui | Oui |

### 6.3. Compatibilité avec F01 à F09

| Module | C1 | C2 | C3 |
|---|---|---|---|
| F01 Administration | Écrans titulaires et contexte permanent | Écrans titulaires + refonte de l'atelier | Écrans titulaires |
| F02 Personnes et listes | Registre ; fiche liée ; import de participations | Idem + recherche inter-événements | Idem |
| F03 Badges | Lots dans le contexte permanent ; atelier inchangé dans sa structure | **Atelier, lots et réservations à refondre** | Atelier inchangé, mais volume de réémission |
| F04 Accueil | Remise de carte permanente | Idem | Idem, à chaque événement |
| F05 Contrôle et sorties | **Une règle de reconnaissance inter-événements** + sortie sans restitution + ré-entrée | **Contrôle serveur et hors ligne réécrit** | Sortie et ré-entrée seulement |
| F06 Groupes et cars | Aucun lien ; reste désactivable | Idem | Idem |
| F07 Pilotage | Présence par participation : inchangée | À revoir (présence inter-événements) | Inchangée |
| F08 Espace hôte | Aucun effet (l'hôte ne crée pas de titulaire) | Idem | Idem |
| F09 Audit, conservation | Garde du contexte permanent ; nouvelles actions d'audit | Idem + conservation de cartes hors événement | Conservation du registre |

### 6.4. Conséquences sur le moteur de contrôle actuel

| Point du moteur | C1 | C2 | C3 |
|---|---|---|---|
| `BadgeService::controler` (statut, annulation, période) | Inchangé | Réécrit (plus d'événement d'origine) | Inchangé |
| `SiteControl::control` — refus `autre_evenement` (l. 39) | **Un cas ajouté** : carte du contexte permanent → recherche de la participation | Supprimé et remplacé par la règle inter-événements | Inchangé |
| `SiteControl::exit` — `restituer` (l. 127) | **Retiré pour les cartes permanentes** (commun) | Idem | Idem |
| `Desk::enter` — `deja_present` (l. 336) | Assoupli pour les participations de permanents (commun) | Idem | Idem |
| Instantané tablette (`ListSnapshot`) | **Ajout** des cartes permanentes des titulaires attendus dans l'événement du poste | Réécrit | Inchangé |
| Contrôle hors ligne (`site.ts`, `apply.ts`) | Même ajout côté client + motif `participation_absente` | Réécrit | Inchangé |
| Présence (`Presence`) | Inchangée (par fiche de participation, K8) | À revoir | Inchangée |
| Audit des contrôles | Inchangé (+ champs INV-H) | Idem | Idem |

### 6.5. Doublons et divergence d'identité

| Risque | C1 | C2 | C3 |
|---|---|---|---|
| Deux titulaires pour une même personne | Détection par normalisation (règles d'import existantes) + avertissement ; **pas de fusion en v1** [H] | Idem | **Plus élevé** : la personne se retrouve dans chaque événement sans pivot fiable |
| Nom différent entre registre et fiche d'événement | La participation **copie** l'identité à sa création ; le registre est la **source** ; une mise à jour du registre se propage aux participations des événements **ouverts** seulement ; les événements clos ne sont jamais modifiés | Lecture directe du registre (pas de copie) : divergence réduite, mais perte de la structure de recherche actuelle | Pas de mécanisme |
| Deux participations du même titulaire dans un événement | Interdit par unicité (titulaire, événement) | Idem | Idem |
| Titulaire Clos mais fiche encore active | Fin d'engagement : clôture des participations ouvertes dans la même opération (INV-P07) | Idem | Idem |

### 6.6. Conservation et anonymisation

| Sujet | C1 | C2 | C3 |
|---|---|---|---|
| Titulaire **actif** | Jamais anonymisé (INV-P10) | Idem | Idem |
| Contexte permanent | **Exclu** de la clôture, de l'archivage et de la purge par indicateur + garde en base ; **risque central de C1** | Sans objet | Sans objet |
| Participations d'un événement clos | Anonymisées comme aujourd'hui (la copie d'identité est effacée) ; le registre n'est pas touché | Anonymisation à redéfinir (pas de copie) | Comme aujourd'hui |
| Titulaire **Clos** | Politique de conservation propre, durée à fixer par le client **[D-16]** ; cartes annulées gardent une destination immuable (identifiant opaque) | Idem | Idem |
| Carte nominative physique | L'anonymisation ne récupère pas la carte (règle existante) | Idem | Idem |

### 6.7. Migrations et retour arrière

| Sujet | C1 | C2 | C3 |
|---|---|---|---|
| Nature | **Additive** : nouvelle table, colonnes nullables, indicateur à faux | **Structurelle** sur `badges` et ses dépendances | Additive |
| Données existantes | Aucune ligne modifiée | Reprise des cartes existantes (risque V-BD-11) | Aucune ligne modifiée |
| Retour arrière avant usage | Possible (suppression des ajouts) | Difficile | Possible |
| Retour arrière après usage | **Refusé** (précédent K12) ; sortie par désactivation fonctionnelle | Quasi impossible | Refusé |
| Installations existantes | Inchangées (aucun titulaire, aucun contexte permanent) | À démontrer | Inchangées |

### 6.8. Régression et maintenabilité

| | C1 | C2 | C3 |
|---|---|---|---|
| Surface de code touchée | Petite et localisée : 1 règle de contrôle, sortie, ré-entrée, instantané, garde de conservation | Très large | Très petite |
| Dette conceptuelle | Un événement spécial (contexte) à documenter | Faible une fois fait | **Forte** (réémission, identité éclatée) |
| Testabilité | TA-P01 à P13 ciblent des points précis | Suite entière à reprendre | Facile |
| Évolutivité | Passage ultérieur à C2 possible (le contexte devient une simple origine) | — | Passage à C1 coûteux |

## 7. Recommandation

**Retenir la variante C1 — registre léger de titulaires, participations portées par les fiches d'événement existantes, cartes permanentes hébergées par un contexte permanent protégé.** Cette recommandation n'est **pas** une approbation : elle attend la décision D-07.

### 7.1. Justification architecturale

1. **Simplicité durable.** C1 ajoute un registre et un indicateur, sans toucher à la structure (événement, badge) qui porte lots, réservations, instantanés et déclencheurs. C2 touche cette structure ; C3 la préserve mais crée une dette d'exploitation.
2. **Cohérence métier.** Le titulaire est permanent par son registre ; la carte reste stable d'un événement à l'autre ; l'autorisation vient toujours de la catégorie d'**événement** (INV-P03). Les passages sont rattachés à l'événement contrôlé, ce qui laisse la présence et l'évacuation (F07) inchangées.
3. **Compatibilité avec l'existant.** Tout est additif et nullable ; une installation sans titulaires se comporte exactement comme aujourd'hui (E4). Aucun QR, jeton ni historique n'est réécrit (E3).
4. **Réversibilité et évolution.** Le choix est réversible avant usage, et C1 n'interdit pas un passage ultérieur à C2 : le contexte permanent deviendrait une simple origine des cartes.

### 7.2. Garde-fous obligatoires de C1

| Garde-fou | Risque couvert | Preuve |
|---|---|---|
| Le contexte permanent est protégé **en base** (déclencheur) et **en service** contre clôture, archivage, anonymisation, purge | INV-P10 ; perte du personnel | TA-P07 |
| Règle unique de reconnaissance inter-événements, **sans lecture** de la couleur, du code ni de la catégorie institutionnelle | INV-P03, INV-G02 | TA-P08 + test d'architecture |
| Aucune participation → **refus par défaut** | Accès sans autorisation | TA-P02, TA-H12 |
| La carte permanente n'est jamais restituée par un passage | INV-P01 | TA-P01 |
| Instantané tablette limité aux titulaires attendus dans l'événement du poste (minimisation) | INV-P12, INV-H10 | TA-H08 |
| Retour arrière refusé après usage | Perte de données | Test de migration |
| Copie d'identité à la création de la participation, propagation aux événements **ouverts** seulement | Divergence d'identité | Test dédié |

### 7.3. Risques résiduels de C1

1. **Le contexte permanent est un point de défaillance unique** : si sa protection échoue, le personnel est exposé à la conservation. Mitigation : double garde et test dédié.
2. **Ré-entrée quotidienne** : le chemin d'entrée d'un permanent (poste de contrôle sans passage par l'accueil) doit être précisé, car l'entrée est aujourd'hui enregistrée par l'accueil (`Desk`). Point à instruire en préparation du lot A06.
3. **Divergence d'identité** limitée mais non nulle entre registre et participations anciennes.
4. **Le contexte permanent et son indicateur sont un concept à expliquer** aux administrateurs (écran et documentation simples).

## 8. Conséquences pour les lots

| Lot | Effet |
|---|---|
| A02.2 | Cet ADR + la politique hors ligne + le registre des arbitrages. |
| A04 | Registre, participation, indicateur, identifiant imprimé, gardes. **Ne démarre qu'après approbation de D-07 et réponse à D-16.** |
| A06 | Sortie sans restitution, ré-entrée, reconnaissance inter-événements, hors ligne. |
| A07 | Recette : TA-P01 à P13 et TA-H01 à H12. |

## 9. Preuves à produire avant approbation définitive de l'implémentation

1. **Étude sur données fictives** (sans migration de production) : liste des requêtes et écrans touchés, estimation du volume de modifications, démonstration que l'installation non-AILCT reste identique.
2. **Maquette des règles de contrôle** (décision table) pour : carte du contexte permanent dans E0 ; dans E1 avec participation ; sans participation ; titulaire Clos ; carte annulée ; carte expirée.
3. **Plan de test** TA-P01 à P13 relu par le dirigeant.
4. **Réponses du client** : D-16 (base légale, conservation), confirmation de E2 (pas de réémission par événement) et du périmètre « personnel / partenaires permanents ».

## 10. Ce que cet ADR ne décide pas

Noms de tables et de colonnes ; format de l'identifiant imprimé (D-08) ; durées de validité (D-09) ; autorité de révocation (D-10) ; valeurs hors ligne (D-22) ; suspension temporaire d'un titulaire (D-25) ; fusion de doublons. Aucune hypothèse de ce document n'est une décision.
