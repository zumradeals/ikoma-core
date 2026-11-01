# A02 — Plan d'implémentation AILCT : lots, dépendances, critères d'acceptation, risques

Statut : **proposition de conception, documentaire — révision R01** (mission AILCT-A02-R01 : contrats des titulaires permanents et des tablettes hors ligne, matrice de traçabilité, rapport en section 14). **Aucune implémentation n'est autorisée** tant que le dirigeant n'a pas validé ce dossier. Ce plan ne modifie aucun fichier applicatif, aucune migration, aucune donnée.
Base : `main` au commit `9a7729f`. Références : `AILCT-IDENTITY-001`, `AILCT-BADGE-001`, `A01-ARCHITECTURE-ET-COMPATIBILITE.md` (PR n° 61, non fusionnée et non touchée).
Documents frères : [A02-IDENTITE-INSTITUTIONNELLE.md](A02-IDENTITE-INSTITUTIONNELLE.md), [A02-BADGES-INSTITUTIONNELS.md](A02-BADGES-INSTITUTIONNELS.md).

## 1. Légende

**[V]** exigence validée, **[C]** constat dans le code, **[H]** hypothèse, **[D-nn]** décision à arbitrer (registre section 8). Taille : **S** (≤ 2 jours), **M** (≤ 1 semaine), **L** (1 à 3 semaines), valeurs d'ordre de grandeur **[H]**, hors délais d'approvisionnement du client.

## 2. Principes directeurs [V]

1. **AILCT est l'identité visible ; IKOMA ACCESS reste le produit réutilisable.** Les adaptations passent par un **mécanisme de personnalisation institutionnelle** (profil versionné), pas par une copie du produit ni un renommage global.
2. **Les modules F01–F09 sont préservés** ; F06 reste activable par l'administrateur.
3. **Aucune donnée existante, aucun QR déjà imprimé, aucun historique ne sont modifiés.** Toute évolution de schéma est **additive** et réversible tant qu'elle n'est pas utilisée.
4. **La marque, la couleur ou le code de catégorie ne confèrent jamais de droit.**
5. **Une installation non-AILCT reste strictement inchangée** (profil neutre par défaut).
6. Chaque lot : code, tests, documentation courte, journal des décisions, rapport (règles d'exécution du dépôt). Une branche et une pull request par lot.
7. **Rien n'est livré en production sans validation du dirigeant ni essais d'impression réels.**

## 3. Découpage en lots

La numérotation suit celle du rapport A01 (A02.1, A02.2, A03 à A07) pour rester traçable.

| Lot | Intitulé | Contenu | Existe / à configurer / à faire | Dépend de | Taille [H] |
|---|---|---|---|---|:-:|
| **A02.1** | Validation du dossier et des entrées client | Validation de ce dossier par le dirigeant ; réception du logo source vectoriel et de l'attestation de droits, du cahier des charges, des 24 images, des couleurs et polices officielles, des textes légaux ; remplissage de la fiche de relevé des 24 modèles. | Documentaire, aucune ligne de code | — | S |
| **A02.2** | Décisions d'architecture (ADR) | Tranche D-05 (sélection du profil), D-07 (titulaires permanents), D-08 (identifiant imprimé), D-14 (sortie des permanents) ; contrats de données et de compatibilité (aucun QR existant modifié). | Documentaire | A02.1 | S |
| **A03** | Socle d'identité institutionnelle | Profil institutionnel déclaratif versionné ; service de résolution avec repli sur le profil neutre ; composant de marque et accès `useInstitution()` ; variables de couleur ; titres, métadonnées, manifeste PWA servi selon D-05 ; remplacement des 14 fichiers à marque codée en dur ; test anti-fuite de marque ; test d'architecture (aucune logique métier ne lit le profil). | Réglages et `config/ikoma.php` existent pour **sélectionner** ; description du profil à créer ; composants à factoriser | A02.1 (logo, charte), D-01 à D-05 | L |
| **A04** | Référentiel institutionnel et identifiant imprimé | Huit catégories (code `AILCT-01…08`, couleur, symbole) ; liaison **additive** catégorie institutionnelle ↔ catégorie d'événement ; titulaires permanents selon l'ADR ; identifiant imprimé (séquence, clé de contrôle, mémoire des identifiants émis) ; nouvelles actions d'audit ; colonnes d'import. | Catégories d'événement et `position` existent ; reste à créer | A02.2, A03 (profil) | L |
| **A05** | Badges AILCT recto-verso | Huit modèles fidèles aux originaux ; QR au verso ; mentions ; compteur de tirages ; appariement recto/verso ; verrouillage des couleurs officielles dans l'atelier ; états dérivés (expiré) et rapport des badges expirés. | Atelier à trois modèles et impression CR80 existent ; moteur à étendre | A04, 24 originaux, D-11, D-12, D-15 | L |
| **A06** | Cycle de vie des permanents, sorties et contrat hors ligne | Mise en œuvre des contrats `INV-P` et `INV-H` des sections 15 et 16 du document badges : sortie sans restitution ; validité propre au titulaire ; expiration ; vérification de l'âge des données **à chaque décision** ; zones à contrôle en ligne ; journalisation et conflits ; impact F04, F05, F07. **Ne démarre qu'après l'ADR (A02.2) et les décisions D-13, D-14, D-21, D-22.** | Restitution et annulation existent ; mode permanent à créer | A04, D-09, D-10, D-13, D-14 | M à L |
| **A07** | Recette institutionnelle et passage en exploitation | Essais physiques (impression PVC, scan réel, zones autorisées/interdites, expiration, révocation, recto-verso apparié) ; non-régression complète ; accessibilité ; procédure de remise ; réserve sur la publication. **Pas de déploiement sans ordre du dirigeant.** | Pipelines CI existants | A03 à A06, matériel et cartes du client | M |

Ordre de livraison recommandé : A02.1 → A02.2 → A03 → A04 → A05 → A06 → A07. **A03 peut démarrer avec des visuels de substitution neutres** tant que le logo n'est pas livré (aucune illustration générique ne doit être *publiée* à la place des originaux).

## 4. Graphe de dépendances

```
A02.1 (entrées client) ──► A02.2 (ADR) ──► A04 (référentiel, identifiant, permanents) ──► A05 (8 modèles) ──┐
        │                                      ▲                                                          ├─► A07 (recette)
        └──────────────► A03 (socle identité) ─┘                                         A06 (permanents) ──┘
                                                       (A06 dépend de A04 ; A05 et A06 parallélisables)
Bloquants externes : logo/droits (D-01), originaux et couleurs (D-02, D-11), matériel d'impression (D-12), base légale (D-16).
```

## 5. Critères d'acceptation par lot

### A03 — Socle d'identité
1. Profil AILCT sélectionné explicitement ; **absence ou invalidité du profil → rendu strictement identique à aujourd'hui** (comparaison de captures).
2. Aucune occurrence de « IKOMA » visible sur les 37 surfaces de l'inventaire (sauf mention du fournisseur si D-03 la retient), y compris titres, `alt`, `aria-label`, manifeste, documents imprimés, exports.
3. Aucune route, permission, espace de noms, variable `IKOMA_*` ni préfixe QR modifié.
4. Contrastes conformes (texte courant ≥ 4,5 : 1) ; couleur de focus visible.
5. Parcours poste d'accueil hors ligne et service worker fonctionnels avec le profil AILCT.
6. Tests d'architecture : aucune lecture du profil dans les services métier.

### A04 — Référentiel et identifiants
1. Huit catégories présentes, liaison facultative ; **changer la catégorie institutionnelle ne modifie aucun verdict** de contrôle (test de garde).
2. Identifiant imprimé unique sous concurrence, jamais réattribué, stable à la réimpression, clé de contrôle correcte.
3. Migrations **additives**, réversibles tant qu'inutilisées ; aucune ligne existante modifiée.
4. Journal d'audit : nouvelles actions sans donnée personnelle.
5. Purge/anonymisation n'efface pas un titulaire permanent actif.

### A05 — Badges
1. Huit modèles recto-verso conformes à la fiche de relevé (validés par le client sur épreuve).
2. QR identique à l'existant (même jeton, même préfixe), ≥ 28 mm, noir sur blanc ; lecture réelle réussie.
3. Nom, code et symbole présents sur chaque catégorie ; aucune information portée par la couleur seule.
4. Noms longs, accents, absence de photo : aucun débordement.
5. Recto et verso appariés ; réimpression sans nouvelle identité ni nouveau droit.

### A06 — Permanents
1. Entrées/sorties répétées d'un permanent sans changement de statut du badge.
2. Expiration constatée et visible ; révocation immédiate côté serveur ; comportement hors ligne conforme au contrat de la section 16 (TA-H01 à TA-H12) et non à une simple acceptation du risque.
2 bis. Tous les tests TA-P01 à TA-P13 passent ; aucun test existant n'est modifié pour obtenir ce résultat.
3. Évacuation (F07) inclut correctement permanents et visiteurs.

### A07 — Recette
1. Essai réel : impression PVC, scan, zone autorisée et interdite, badge expiré, badge révoqué, perte et duplicata.
2. Suite complète (lint, tests PHP, tests front, e2e, sécurité) au vert ; non-régression d'une installation non-AILCT.
3. Rapport de recette signé par le dirigeant ; **aucune mise en production sans son ordre écrit**.

## 6. Stratégie de tests transversale

| Famille | Contenu |
|---|---|
| Non-régression | Installation neutre identique ; badges et QR déjà imprimés lus avant/après ; aucune donnée existante modifiée. |
| Sécurité | Aucune élévation de droits par catégorie ou marque ; QR sans donnée personnelle ; photo uniquement si autorisée ; journal sans nom ni photo. |
| Architecture | Tests interdisant la lecture du profil et du code de catégorie institutionnelle par les services de contrôle et d'autorisation. |
| Hors ligne | Copie ancienne face à un badge révoqué ; verrouillage après le délai maximal ; resynchronisation. |
| Accessibilité | Contrastes calculés, focus, lecture écran, tablette tactile. |
| Impression | Épreuve physique, scan réel, alignement recto-verso, comparaison aux originaux. |
| Modules | Parcours F01–F09 avec F06 inactif puis actif. |

## 7. Risques majeurs

| Réf. | Risque | Gravité | Parade |
|---|---|:-:|---|
| P1 | Logo ou originaux non livrés / droits non établis. | Bloquant | Lot A02.1 ; visuels neutres de développement uniquement. |
| P2 | Confusion catégorie de badge / autorisation d'accès. | Élevée | Liaison explicite, tests de garde, revue systématique. |
| P3 | Modèle de données des permanents mal choisi (conservation, purge, doublons, modification du cœur de contrôle). | Élevée | ADR avant A04, évaluée sur les invariants INV-P01 à P12 (variantes C1, C2, C3) ; ne rien migrer avant D-07. |
| P4 | Sortie quotidienne d'un permanent traitée comme une restitution. | Élevée | A06 ; décision D-14 avant tout code. |
| P5 | Révocation non propagée aux tablettes hors ligne. | Élevée | **Mesures techniques** : durée maximale de confiance vérifiée à chaque décision, zones à contrôle en ligne obligatoire, recontrôle serveur, conflits (contrat de la section 16). Le risque résiduel est **documenté**, non « accepté » à la place d'une mesure. |
| P6 | Couleurs métalliques et CMJN non reproductibles. | Moyenne | Épreuves physiques ; couleurs fournies par le client. |
| P7 | Fuite de la marque IKOMA sur une surface oubliée. | Moyenne | Inventaire des surfaces et test automatique anti-fuite. |
| P8 | Régression d'une installation non-AILCT. | Élevée | Profil neutre identique ; captures de comparaison. |
| P9 | Dérive vers un produit multi-clients non maîtrisé. | Moyenne | Profil choisi au déploiement ; décision séparée requise. |
| P10 | Données personnelles du personnel permanent sans base légale établie. | Élevée | D-16 avant A04. |
| P11 | Dette de sécurité des images (Trivy, risque connu et accepté) persistante en production AILCT. | Moyenne | Point à rappeler au client ; revue des images avant exploitation. |

## 8. Registre des décisions à arbitrer

Recommandation en premier. Responsable : **dirigeant** sauf mention. « Bloque » : lot qui ne peut pas commencer sans la décision.

| Réf. | Sujet | Recommandation | Bloque |
|---|---|---|---|
| D-01 | Source et droits du logo (vectoriel original, attestation) | Obtenir fichier et attestation avant toute intégration | A03 (visuels finaux) |
| D-02 | Palette, polices, couleur de focus, traitement de l'or | Valeurs officielles du client en variables de couleur ; palette de travail provisoire | A03, A05 |
| D-03 | Mention du fournisseur technique ; reformulation de l'écran « Modules » | Mention discrète « technologie », sauf exigence contraire | A03 |
| D-04 | Texte et structure de la page d'accueil institutionnelle | Page sobre, sans argumentaire commercial | A03 |
| D-05 | Sélection du profil | Une image par institution + vérification à l'exécution | A03 |
| D-06 | Emplacement et propriété du fichier de profil (dépôt du produit ou dépôt dédié client) | Fichier versionné relu en revue ; à confirmer selon la politique de confidentialité du client | A03 |
| D-07 | Titulaires permanents : contrat métier validé d'abord (INV-P) ; stockage A / B / C1 / C2 / C3 | **Valider le contrat ; trancher le stockage par ADR** (aucune variante approuvée) | A04 |
| D-08 | Identifiant imprimé : périmètre, format, titulaire ou carte | Séquence globale, clé de contrôle, jamais remise à zéro | A04 |
| D-09 | Durées de validité par catégorie | Valeurs du tableau de travail à confirmer | A04, A06 |
| D-10 | Autorité de révocation et de duplicata | Superviseur (existant), sans nouveau rôle | A06 |
| D-11 | Champs exacts des 24 modèles (photo, fonction, pays…) | Relevé sur les originaux | A05 |
| D-12 | Duplex, fond perdu, matériel d'impression | Épreuves sur le matériel du client | A05 |
| D-13 | Contrat de sécurité hors ligne (section 16 des badges) | Valider les invariants INV-H et le comportement au refus ; la valeur des paramètres relève de D-21 et D-22 | A06 |
| D-14 | Sortie des permanents sans restitution (INV-P01) | Valider l'invariant : la sortie est un passage, jamais un changement d'état de la carte ; marqueur explicite « carte permanente » | A06 |
| D-15 | Textes du verso (propriété, perte, restitution, légal) | Fournis par le client | A05 |
| D-16 | Base légale et conservation des données du personnel | À établir avec le client avant A04 | A04 |
| D-17 | Langues (français seul ou bilingue) | Français d'abord, champs réservés pour l'anglais | A03 |
| D-18 | Éléments de sécurité physiques (hologramme, guillochis, UV) | Hors périmètre sauf exigence | A05 |
| D-19 | Préfixe `IKOMA1:` du QR | Conserver ; en informer le client | A04 |
| D-20 | Mention de confidentialité sur les documents imprimés | Selon classification du client | A03 |
| D-21 | Classification des zones « sensibles » (contrôle en ligne obligatoire, durée de confiance réduite) | Liste établie par le client avec la sécurité de l'Académie | A06 |
| D-22 | Valeurs : durée maximale de confiance générale, seuil d'avertissement, durée par zone | 4 h générale (hypothèse), 0 à 1 h pour zones sensibles ; interdiction de « sans limite » | A06 |
| D-23 | Procédure de repli quand la confiance est expirée (décision humaine hors application) | Document d'exploitation sous l'autorité du superviseur | A06, A07 |
| D-24 | Fonctions maintenues sans confiance : sorties et liste d'évacuation locale (INV-H08) | Maintenir, sans jamais accorder d'accès | A06 |
| D-25 | Titulaire : état unique Actif/Clos, ou ajout d'une suspension temporaire | Actif/Clos seulement (simplicité) | A04 |
| D-26 | Réintroduire ou non un « profil de sécurité » groupé pour les sites sensibles | Non : réglages individuels (la suppression antérieure des profils est maintenue) | A06 |

## 9. Hypothèses de travail (non validées)

| Réf. | Hypothèse | Où elle intervient |
|---|---|---|
| H-01 | Teintes de travail des huit catégories | Badges §3 |
| H-02 | Symboles des huit catégories | Badges §3 |
| H-03 | Durées de validité par catégorie | Badges §3.2 |
| H-04 | Photo obligatoire pour les catégories 1, 2, 3, 7, 8 | Badges §3.2 |
| H-05 | Une catégorie d'événement par catégorie institutionnelle et par événement | Badges §4.2 |
| H-06 | Format de l'identifiant `AILCT-<cat>-<n>-<clé>` | Badges §4.4 |
| H-07 | Répartition verticale recto/verso, cadre photo 24 × 32 mm, QR ≥ 28 mm | Badges §6 |
| H-08 | Fond perdu 1,5 mm, zone de sécurité 3 mm | Badges §6.1 |
| H-09 | Option C pour les permanents | Badges §4.3 |
| H-10 | Version anglaise probable | Identité §7 |

## 10. Hors périmètre de A02 et du plan

Aucun code, migration, donnée ni déploiement ; aucune modification des PR n° 60 et n° 61 ni de leurs branches ; pas de multi-tenance ; pas de renommage global des noms internes, routes, permissions, espaces de noms ou variables d'exploitation ; pas de nouveau rôle ni de nouvelle permission sans décision ; pas d'éléments de sécurité physiques sans exigence ; pas de modification du préfixe de QR ; pas de répétition générale (L13) non autorisée.

## 11. Procédure de validation

1. Le dirigeant relit les trois documents et la pull request documentaire.
2. Il tranche ou délègue chaque décision du registre (section 8) ; les décisions bloquantes sont traitées en premier : D-01, D-02, D-07, D-13, D-14, D-16, D-21, D-22.
3. Il fournit les entrées client (A02.1).
4. Il déclare le dossier validé, lot par lot. **Aucun lot d'implémentation ne démarre avant cette validation écrite.**
5. Les documents restent des **projets de travail** jusqu'à cette validation ; rien n'est officiellement AILCT ou GAMAD tant que le dirigeant ne l'a pas déclaré.

## 12. Matrice de traçabilité (révision R01)

Elle relie **exigence → décision → invariant → test d'acceptation → lot**. Les tests TA-P et TA-H sont définis aux sections 15.7 et 16.13 du document badges ; les tests TA-I (identité) et TA-B (badges, rendu) désignent le **n-ième point** de la section « Tests nécessaires » du document d'identité (§10) et du document badges (§13) : TA-I01 anti-fuite de marque, TA-I02 repli, TA-I03 non-régression non-AILCT, TA-I04 aucun effet métier (test d'architecture), TA-I05 titres et métadonnées, TA-I06 exports et impressions, TA-I07 accessibilité, TA-I08 hors ligne et PWA, TA-I09 sécurité (CSP) ; TA-B01 garde « catégorie ≠ autorisation », TA-B02 QR existants, TA-B03 cycle de vie, TA-B04 identifiant imprimé, TA-B05 hors ligne, TA-B06 permanents, TA-B07 rendu, TA-B08 accessibilité, TA-B09 impression réelle, TA-B10 confidentialité. **Aucun test n'est écrit ni exécuté par ce lot.**

| Exigence | Source | Décision | Invariants | Tests d'acceptation | Lot |
|---|---|---|---|---|---|
| Sortie quotidienne d'un permanent sans restitution, annulation ni désactivation | Mission R01 §1 ; V-BD-05 | D-14 | INV-P01, INV-P02, INV-P09 | TA-P01, TA-P02, TA-P03, TA-H12 | A06 |
| Statut permanent indépendant d'un événement | V-BD-05 (BADGE-001) | D-07, D-25 | INV-P04, INV-P10 | TA-P07, TA-P09 | A04 |
| Séparer identité, carte, passage, autorisation, validité | Mission R01 §1 | D-07 | INV-P01 à INV-P12 | TA-P01 à TA-P13 | A02.2, A04 |
| Catégorie institutionnelle ≠ autorisation | V-BD-03, V-BD-04, V-BD-12 | — | INV-P03 | TA-P08 | A04 |
| Identifiant imprimé unique, stable, non réattribuable ; réimpression sans nouveau droit | V-BD-06, V-BD-10 | D-08 | INV-P05, INV-P06 | TA-P05, TA-P13 | A04, A05 |
| Expiration, perte, révocation | V-BD-09, V-BD-13 | D-09, D-10 | INV-P06 à INV-P08 | TA-P04, TA-P05, TA-P09 | A06 |
| Compatibilité des QR et badges existants | V-BD-07, V-BD-11 ; V-ID-10 | D-19 | INV-P11, INV-G01 | TA-P03, TA-P11, TA-P13 | A04 à A07 |
| Parcours visiteur inchangé | F04, F05 (ACCESS-FOUNDATION-001) | — | INV-P11 | TA-P03, TA-P11 | A06 |
| Durée maximale de confiance des données synchronisées | Mission R01 §2 | D-13, D-22 | INV-H01 | TA-H01, TA-H02 | A06 |
| Comportement à l'expiration de la confiance | Mission R01 §2 | D-13, D-23 | INV-H02, INV-H07 | TA-H01, TA-H02, TA-H07 | A06 |
| Révocation non encore reçue | Mission R01 §2 | D-13, D-21 | INV-H03, INV-H04 | TA-H03, TA-H04, TA-H11 | A06 |
| Conditions de refus de contrôle | Mission R01 §2 | D-13 | INV-H01, H07, H10 | TA-H01, TA-H07, TA-H08 | A06 |
| Journalisation des décisions hors ligne | Mission R01 §2 ; V-BD-09 | D-13 | INV-H05 | TA-H05 | A06 |
| Resynchronisation et conflits | Mission R01 §2 | D-13 | INV-H04, INV-H06 | TA-H03, TA-H11 | A06 |
| Paramètres des sites sensibles | Mission R01 §2 | D-21, D-22, D-26 | INV-H09 | TA-H04, TA-H09 | A06 |
| Fonctions de sécurité des personnes maintenues | F07 | D-24 | INV-H08 | TA-H06, TA-P06 | A06 |
| Acceptation du risque ≠ protection | Mission R01 §2 | D-13 | INV-H03 | TA-H03, TA-H04 | A06 |
| Identité AILCT visible, marque neutre en repli | V-ID-05, V-ID-08, V-ID-11 | D-01 à D-05, D-17, D-20 | INV-G02, INV-G03 | TA-I01, TA-I02, TA-I05, TA-I06 | A03 |
| Marque sans effet sur les droits | V-ID-10 | — | INV-G02 | TA-I04 (test d'architecture) | A03 |
| Huit catégories, lisibilité sans la couleur seule | V-BD-01, V-BD-02 | D-02, D-11 | INV-G04 | TA-B01, TA-B07, TA-B08 | A04, A05 |
| Impression PVC, scan réel, recto-verso apparié | V-BD-10 | D-12, D-15, D-18 | INV-G01 | TA-B09 | A05, A07 |
| F01–F09 préservés, F06 désactivable | V-ID-10 ; ACCESS-FOUNDATION-001 | — | INV-G05 | TA-P12, TA-H10 | tous |
| Installation non-AILCT inchangée | V-ID-11 | — | INV-G03 | TA-I03 | A03, A07 |

**Invariants globaux (INV-G)** — rappel des contraintes déjà validées, utilisées ci-dessus :
INV-G01 le jeton QR et le préfixe `IKOMA1:` ne changent pas ; INV-G02 aucune marque, couleur ni code de catégorie ne confère de droit ; INV-G03 le profil neutre reproduit le rendu actuel ; INV-G04 la couleur n'est jamais le seul repère ; INV-G05 aucune suppression, réduction ni renommage d'un module fondateur.

## 13. Cohérence documentaire avec les contrats et le produit actuel

| Référence | Vérification | Résultat |
|---|---|---|
| `AILCT-IDENTITY-001` | Les sections 15 et 16 n'introduisent aucune logique de marque ; la marque reste sans effet sur les droits (INV-G02) ; une identité par installation (V-ID-09) n'est pas modifiée. | Compatible |
| `AILCT-BADGE-001` | V-BD-05 (permanent indépendant d'un événement) est respecté par le contrat objet O1 ; V-BD-06 et V-BD-10 par INV-P05 ; V-BD-09 par INV-P06 à P08 et INV-H05, H06 ; V-BD-11 par INV-P11 ; V-BD-12 par INV-P03. Les 8 catégories ne deviennent pas des rôles. | Compatible. Le « stockage » du permanent reste une décision ouverte conforme à BADGE-001 (« stratégie à décider après conception détaillée »). |
| `A01-ARCHITECTURE-ET-COMPATIBILITE.md` | La numérotation des lots (A02.1 à A07) est conservée ; la recette hors ligne et l'impression réelle relèvent de A07 comme prévu ; aucun conflit avec les dépendances. A06 est précisé et conditionné à l'ADR. | Compatible |
| `ACCESS-FOUNDATION-001` (F01–F09) | Impact par module : documents badges §15.6 et §16.12, identité §9. Aucun module retiré, réduit ni renommé ; F06 reste désactivable. | Compatible |
| Moteur de badges actuel | Statuts et déclencheur **inchangés** (expiration calculée, aucune nouvelle valeur) ; **un seul comportement actuel doit changer** : la sortie ne restitue plus une **carte permanente** (marqueur explicite) ; le comportement visiteur est conservé. | Compatible, avec une évolution identifiée (A06) |
| QR existants | Jeton, préfixe, génération et lecture inchangés (INV-G01). | Compatible |
| Règles du dépôt (`AGENTS.md`) | Réglages métier dans la table de réglages et l'écran d'administration (INV-H09) ; aucun nom de table inventé ; aucune décision présentée comme validée. | Conforme |

**Écart constaté dans le produit (à corriger plus tard, hors de ce lot)** : la limite d'âge hors ligne n'est évaluée qu'au déverrouillage (`runtime.ts:94`, `PosteGate.vue:113`) ; le contrat de la section 16 exige une évaluation à chaque décision.

## 14. Rapport final de la révision R01

### 14.1. Réserves levées (sous réserve de la validation architecturale du dirigeant)

| Réserve | Levée par | État |
|---|---|---|
| D-07 / D-14 : contrat métier distinguant identité, carte, passage, autorisation, validité | Doc. badges §15.1 à 15.4 ; invariants INV-P01 à P12 | **Levée en conception** ; stockage non tranché |
| Étude du registre hybride sans approbation | Doc. badges §15.5 (variantes C1, C2, C3 comparées) | **Levée** : étudiée, **non approuvée** |
| Sortie quotidienne sans restitution, annulation ni désactivation | INV-P01, constat sur `SiteControl::exit`, TA-P01 à TA-P03 | **Levée en conception** |
| D-13 : contrat hors ligne (durée de confiance, expiration, révocation non reçue, refus, journal, resynchronisation, sites sensibles) | Doc. badges §16 ; invariants INV-H01 à H10 ; TA-H01 à H12 | **Levée en conception** |
| « L'acceptation écrite n'est pas une protection » | INV-H03 ; §16.11 (risque résiduel documenté, borné et limité aux zones non sensibles) | **Levée** |
| Cohérence avec IDENTITY-001, BADGE-001, A01, F01–F09, moteur actuel, QR | Section 13 ci-dessus | **Vérifiée** |
| Matrice de traçabilité | Section 12 ci-dessus | **Livrée** |

### 14.2. Décisions restant à arbitrer

Bloquantes pour l'implémentation : **D-07** (contrat puis stockage A/B/C1/C2/C3), **D-13** (validation du contrat), **D-14** (invariant INV-P01), **D-16**, **D-21** (zones sensibles), **D-22** (valeurs de confiance). Complémentaires : D-23, D-24, D-25, D-26, ainsi que D-01 à D-06, D-08 à D-12, D-15, D-17 à D-20 (section 8). **Aucune hypothèse n'a été transformée en décision.**

### 14.3. Risques résiduels

1. **Fenêtre de révocation hors ligne** pour les zones non sensibles, bornée par la durée maximale de confiance (INV-H03, §16.11).
2. **Choix de stockage des permanents** : la variante la plus conforme à BADGE-001 (C2) est la plus invasive pour le contrôle ; risque de régression (P3).
3. **Évolution du contrôle de sortie** : seul point actuel du moteur à modifier, au cœur du flux de sortie (F05).
4. **Entrées client absentes** (logo, droits, 24 modèles, couleurs) : toutes les valeurs visuelles restent des hypothèses.
5. **Horloge des tablettes** : la garde d'intégrité réduit sans supprimer le risque de manipulation.
6. **Vulnérabilités connues des images de conteneurs** (risque accepté historiquement) : à revoir avant exploitation (P11).
7. **Matériel d'impression** : aucune certitude de fidélité des couleurs avant essais réels (P6).

### 14.4. Conditions préalables à l'implémentation

1. Validation architecturale finale du dirigeant sur les trois documents.
2. ADR (A02.2) tranchant le stockage des permanents sur la base des invariants INV-P01 à P12, avec évaluation sur données **fictives**.
3. Décisions D-13, D-14, D-21, D-22 prises par le dirigeant.
4. Base légale et conservation des données du personnel (D-16).
5. Entrées client : logo et attestation de droits, cahier des charges, 24 images, couleurs et polices, textes du verso.
6. Procédure de repli hors ligne (D-23) rédigée.
7. Aucun lot A03 à A07 ne démarre avant l'accord écrit ; aucun déploiement sans ordre du dirigeant.
