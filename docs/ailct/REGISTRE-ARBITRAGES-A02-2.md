# Registre d'arbitrages A02.2 — fiches de décision (AILCT-A02.2, chantier 3)

| Champ | Valeur |
|---|---|
| Statut | **Fiches d'aide à la décision. Aucune décision n'est prise par ce document.** |
| Portée | Documentaire. Aucun code, migration, permission ni donnée. |
| Documents liés | `ADR-TITULAIRES-PERMANENTS.md`, `POLITIQUE-CONTROLE-HORS-LIGNE.md`. Contrats (non dupliqués) : `A02-BADGES-INSTITUTIONNELS.md` §15–16 et `A02-PLAN-IMPLEMENTATION.md` §8, §12 (PR n° 62, commit `e3af8bf`) ; `AILCT-BADGE-001`, `A01` (PR n° 61). |

## 1. Qui décide quoi

Deux niveaux de décision :

- **Produit (P)** : le dirigeant, en tant que responsable du produit IKOMA ACCESS, peut décider seul : architecture, règles génériques du produit, invariants de sécurité, ordre des lots.
- **Client (C)** : validation **explicite et écrite** de l'Académie Internationale de Lutte Contre le Terrorisme : tout ce qui relève de ses locaux, de ses zones, de ses personnels, de sa base légale, de ses procédures d'exploitation et de la valeur de ses risques.

| Fiche | Sujet | Décide le produit (P) | Validation explicite du client AILCT (C) | Bloque |
|---|---|---|---|---|
| **D-07** | Architecture des permanents | **Oui** : choix de C1/C2/C3 | **Partielle** : confirmer l'usage (pas de réémission par événement), le périmètre des permanents | A04 |
| **D-13** | Sécurité hors ligne | **Oui** : invariants et mécanismes génériques | **Partielle** : prendre connaissance du risque résiduel (il ne remplace aucune mesure) | A06 |
| **D-14** | Sortie sans restitution | **Oui** : invariant INV-P01 | Information et confirmation de l'usage | A06 |
| **D-16** | Conservation et base légale | Non (cadrage seulement) | **Oui, exclusivement** : base légale, durées, responsable du traitement | A04 |
| **D-21** | Classification des zones | **Non** | **Oui, exclusivement** | A06 |
| **D-22** | Durées de confiance | Valeurs **plafond** génériques | **Oui** : valeurs par zone et acceptation du risque résiduel | A06 |
| **D-23** | Procédure de repli | Squelette | **Oui** : contenu, responsables, accès exceptionnels | A06, A07 |
| **D-24** | Opérations sans confiance | **Oui** : principe | **Oui** : confirmation par le responsable opérationnel | A06 |

**Lecture** : seules D-07 (architecture), D-13 (mécanismes) et D-14 peuvent être clôturées par le dirigeant seul sur le plan produit. D-16, D-21, D-22 (valeurs), D-23 et D-24 (confirmation) **ne peuvent pas être définitives sans le client**.

## 2. Ordre d'arbitrage recommandé

1. **D-14** (le plus simple, déjà exigé) → 2. **D-07** (ADR) → 3. **D-13** (contrat) et **D-24** (principe) → 4. envoi au client de **D-16, D-21, D-22, D-23** avec la grille et le squelette de procédure.

Les décisions 1 à 3 permettent de **préparer** l'implémentation ; les décisions du client conditionnent le **paramétrage** et la mise en exploitation.

---

## Fiche D-07 — Architecture des titulaires permanents

| | |
|---|---|
| **Question** | Comment représenter le personnel et les partenaires permanents sans lier leur statut à un événement ? |
| **Options** | A. événement permanent seul (écartée par R01) ; **C1** registre léger + contexte permanent protégé pour les cartes ; **C2** cartes et titulaires hors événement ; **C3** une carte par événement. |
| **Recommandation** | **C1** (ADR §7). |
| **Pourquoi** | Plus petite surface de changement (une règle de reconnaissance inter-événements) ; additive et réversible avant usage ; garde la structure (événement, badge) ; compatible avec les installations existantes. C3 échoue au critère « pas de réémission à chaque événement » ; C2 est la plus lourde (régression). |
| **Conséquences si approuvée** | Registre des titulaires ; indicateur « permanent » sur un événement ; protection du contexte contre clôture/anonymisation ; règle de contrôle inter-événements ; instantané tablette étendu ; lots A04 et A06 cadrés. |
| **Risques** | Contexte permanent = point de défaillance unique (double garde) ; ré-entrée à préciser ; divergence d'identité limitée. |
| **Conséquences si refusée ou reportée** | A04 et A06 ne démarrent pas ; les catégories AILCT-01/02 ne peuvent pas être mises en œuvre. |
| **Décideur** | **Produit** (choix). **Client** : confirmer l'usage (permanent ne change pas de carte) et le périmètre. |
| **Préalables** | Étude sur données fictives (ADR §9) ; réponses D-16. |
| **Réponse attendue** | ☐ Approuver C1 ☐ Approuver C2 ☐ Approuver C3 ☐ Demander une étude complémentaire sur : ________ |

## Fiche D-13 — Sécurité hors ligne

| | |
|---|---|
| **Question** | Valide-t-on le contrat INV-H01 à H10 comme règle de sécurité du produit pour les tablettes déconnectées ? |
| **Options** | (1) Valider le contrat tel quel ; (2) valider avec amendements ; (3) conserver le comportement actuel (limite vérifiée au déverrouillage seulement). |
| **Recommandation** | **(1)**, avec correction de l'écart de l'évaluation **à chaque décision**. |
| **Pourquoi** | L'écart actuel permet à une tablette déverrouillée avant le seuil d'autoriser des accès après le seuil. L'acceptation du risque par écrit ne protège pas. |
| **Conséquences si approuvée** | Vérification d'âge à chaque décision ; motifs de refus ; journalisation enrichie ; zones en ligne obligatoire ; lot A06 cadré. |
| **Risques** | Davantage de refus en cas de panne ; nécessité d'une procédure de repli (D-23). |
| **Conséquences si refusée** | Le risque d'autorisation sur données périmées subsiste ; non recommandé pour une installation AILCT. |
| **Décideur** | **Produit.** Le client prend connaissance du **risque résiduel** (section 8 de la politique). |
| **Préalables** | Aucun pour la validation du contrat ; D-21/D-22 pour le paramétrage. |
| **Réponse attendue** | ☐ Valider ☐ Valider avec amendements : ________ ☐ Refuser |

## Fiche D-14 — Sortie sans restitution

| | |
|---|---|
| **Question** | Confirme-t-on que la sortie d'un permanent n'entraîne jamais la restitution, l'annulation ni la désactivation de sa carte ? |
| **Options** | (1) Valider INV-P01 ; (2) autoriser une restitution automatique configurable ; (3) ne rien changer. |
| **Recommandation** | **(1).** |
| **Pourquoi** | Exigence fondamentale R01. Le comportement actuel (`SiteControl::exit` appelle la restitution) rend l'usage quotidien d'un permanent impossible. Le flux visiteur reste inchangé. |
| **Conséquences si approuvée** | Marqueur explicite de carte permanente (indicateur d'événement en C1) ; ré-entrée assouplie pour ces fiches ; tests TA-P01 à P03. |
| **Risques** | Une erreur de marquage ferait traiter un visiteur comme permanent ; test dédié et marquage explicite. |
| **Conséquences si refusée** | Les permanents ne peuvent pas utiliser un badge au quotidien. |
| **Décideur** | **Produit.** Information du client. |
| **Préalables** | Aucun. |
| **Réponse attendue** | ☐ Valider ☐ Valider avec amendements : ________ ☐ Refuser |

## Fiche D-16 — Conservation et base légale

| | |
|---|---|
| **Question** | Sur quelle base légale l'AILCT traite-t-elle les données de son personnel et de ses partenaires permanents, et combien de temps les conserve-t-elle ? |
| **Options** | À fournir par le client. Éléments à trancher : base légale du registre ; durée de conservation d'un titulaire **Clos** ; sort des photographies ; durée de conservation des preuves hors ligne ; droits des personnes concernées ; responsable du traitement. |
| **Recommandation** | **Ne rien coder avant la réponse.** Reprendre, pour la structure, le cadre existant : durées approuvées par réglage, anonymisation collective à l'échéance, aucune purge du journal d'audit depuis le module. |
| **Pourquoi** | Les données du personnel permanent n'ont pas la même base que celles des visiteurs d'un événement. Le droit applicable à l'AILCT relève du client et de son service juridique ; ce dossier ne le détermine pas. |
| **Conséquences si approuvée** | Paramétrage de la conservation du registre ; gardes de A04. |
| **Risques** | Non-conformité si le registre est mis en service sans base établie. |
| **Conséquences si reportée** | **A04 reste bloqué** ; seul l'ADR et les études peuvent avancer. |
| **Décideur** | **Client exclusivement** (avec son service juridique / responsable de la protection des données). Le produit fournit les capacités. |
| **Préalables** | Questionnaire ci-dessus envoyé au client. |
| **Réponse attendue (client)** | Base légale : ________ ; durée titulaire Clos : ________ ; photographies : ________ ; preuves hors ligne : ________ ; responsable : ________ |

## Fiche D-21 — Classification des zones

| | |
|---|---|
| **Question** | Quelles zones exigent un contrôle en ligne (classe A), lesquelles tolèrent un hors ligne court (B) ou standard (C) ? |
| **Options** | La grille de `POLITIQUE-CONTROLE-HORS-LIGNE.md` §3 à renseigner. Règle de repli proposée [H] : **zone non classée = classe A**. |
| **Recommandation** | Faire renseigner la grille par le **responsable opérationnel** avec la sécurité de l'Académie ; conserver la règle prudente pour les zones non classées. |
| **Pourquoi** | La classification des zones de l'AILCT est inconnue et ne doit pas être inventée. |
| **Conséquences si approuvée** | Réglages de classe par zone ; comportement hors ligne par zone. |
| **Risques** | Trop de zones A : blocage en cas de panne. Trop peu : exposition. |
| **Conséquences si reportée** | A06 ne peut pas être paramétré ; l'installation devrait fonctionner **toutes zones en classe A** (prudent, moins disponible). |
| **Décideur** | **Client exclusivement.** |
| **Préalables** | Questionnaire d'aide à la classification (politique §3.2). |
| **Réponse attendue (client)** | Grille remplie, signée par : ________ , date : ________ |

## Fiche D-22 — Durées de confiance

| | |
|---|---|
| **Question** | Quelle durée maximale de confiance pour la configuration générale et pour chaque classe ? |
| **Options (toutes des hypothèses [H])** | Générale : 1 h / 2 h / **4 h** / 8 h / 12 h (valeur actuelle). Classe B : 15 min à 2 h (**30–60 min**). Classe C : 1 à 8 h (**2–4 h**). Seuil d'avertissement : **50 %**. |
| **Recommandation** | Partir de **4 h** général, **30–60 min** classe B, **2–4 h** classe C, puis ajuster après exercice de panne. Interdire la valeur « illimitée ». |
| **Pourquoi** | Équilibre entre sécurité et continuité ; l'actuel 12 h n'est justifié par aucune analyse AILCT. |
| **Conséquences si approuvée** | Valeurs saisies dans l'administration ; recette R2 à R5. |
| **Risques** | Trop court : refus fréquents en cas de connexion instable. Trop long : fenêtre de révocation plus large. |
| **Conséquences si reportée** | Le produit conserve 12 h par défaut ; non recommandé pour l'AILCT. |
| **Décideur** | **Produit** : fixe un plafond générique. **Client** : valeurs par zone et acceptation du risque résiduel. |
| **Préalables** | D-21 ; exercice de panne réseau. |
| **Réponse attendue** | Générale : ____ ; B : ____ ; C : ____ ; avertissement : ____ % ; objectif de propagation : ____ min |

## Fiche D-23 — Procédure de repli

| | |
|---|---|
| **Question** | Quelle conduite à tenir lors d'une panne réseau, d'une panne de tablette ou de serveur, et quand la confiance est expirée ? |
| **Options** | Squelette de `POLITIQUE-CONTROLE-HORS-LIGNE.md` §8 : à compléter ; variantes sur les accès exceptionnels en classe A (interdits sauf décision du responsable opérationnel / interdits sans exception). |
| **Recommandation** | Adopter le squelette ; interdire toute entrée « validée » dans l'outil quand la confiance est expirée ; exiger un registre papier de repli et sa réconciliation ; réaliser un **exercice de panne** avant exploitation. |
| **Pourquoi** | Une procédure non écrite est improvisée au pire moment. |
| **Conséquences si approuvée** | Document d'exploitation signé ; formation des agents ; critère de recette R11. |
| **Risques** | Erreur humaine dans le registre papier ; exercices non réalisés. |
| **Conséquences si reportée** | Pas de mise en exploitation (A07). |
| **Décideur** | **Client exclusivement** (contenu, responsables, numéros) ; le produit fournit le squelette. |
| **Préalables** | D-21 ; désignation des responsables. |
| **Réponse attendue (client)** | Procédure signée par : ________ , date : ________ ; accès exceptionnels en classe A : ☐ interdits ☐ sur décision du responsable opérationnel |

## Fiche D-24 — Opérations autorisées sans confiance

| | |
|---|---|
| **Question** | Quelles opérations restent possibles quand les données ne sont plus fiables ? |
| **Options** | Principe de la politique §9 : **maintenues** — sortie, liste d'évacuation, nombre de présents, alerte locale, verrouillage ; **refusées** — entrée, association de badge, admission. À arbitrer : consultation d'identité à des fins de contrôle. |
| **Recommandation** | Adopter le principe : maintenir ce qui protège les personnes et n'accorde aucun accès ; refuser ce qui en accorde. |
| **Pourquoi** | Une panne ne doit ni piéger des personnes ni ouvrir des accès. |
| **Conséquences si approuvée** | Alignement de l'association de badge et de l'admission sur la confiance (évolution produit) ; recettes R8 et R9. |
| **Risques** | Enregistrer des sorties sur données périmées peut créer des incohérences de présence ; réconciliation à la reprise. |
| **Conséquences si refusée** | Soit tout est bloqué (risque pour les personnes), soit tout est permis (risque d'accès). |
| **Décideur** | **Produit** (principe). **Client** : confirmation par le responsable opérationnel de la liste. |
| **Préalables** | Aucun pour le principe. |
| **Réponse attendue** | ☐ Valider le principe ☐ Valider avec amendements : ________ ; consultation d'identité : ☐ permise ☐ refusée |

---

## 3. Synthèse pour le dirigeant

**Décisions que vous pouvez prendre seul sur le plan produit** : D-14, D-07 (choix d'architecture), D-13 (mécanismes), D-24 (principe), plafond de D-22.
**Décisions nécessitant la validation explicite de l'AILCT** : D-16 (exclusivement), D-21 (exclusivement), D-23 (exclusivement), valeurs de D-22, confirmation de D-24 ; et, pour D-07, la confirmation de l'usage des permanents.

**Ce que ces arbitrages débloquent** : les décisions produit (D-14, D-07, D-13, D-24) permettent de **préparer** A04 et A06 ; l'**implémentation de A04** exige en plus D-16 ; le **paramétrage et la mise en exploitation** exigent D-21, D-22, D-23.

Aucune hypothèse de ces documents n'est une décision approuvée tant que la case « Réponse attendue » n'est pas renseignée.
