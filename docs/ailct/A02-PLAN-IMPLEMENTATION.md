# A02 — Plan d'implémentation AILCT : lots, dépendances, critères d'acceptation, risques

Statut : **proposition de conception, documentaire**. **Aucune implémentation n'est autorisée** tant que le dirigeant n'a pas validé ce dossier. Ce plan ne modifie aucun fichier applicatif, aucune migration, aucune donnée.
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
| **A06** | Cycle de vie des permanents et sorties | Sortie sans restitution ; validité propre au titulaire ; expiration ; révocation et fenêtre hors ligne documentée ; impact F04, F05, F07. | Restitution et annulation existent ; mode permanent à créer | A04, D-09, D-10, D-13, D-14 | M à L |
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
2. Expiration constatée et visible ; révocation immédiate côté serveur ; comportement hors ligne conforme à la fenêtre acceptée (D-13).
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
| P3 | Modèle de données des permanents mal choisi (conservation, purge, doublons). | Élevée | ADR avant A04 ; ne rien migrer avant D-07. |
| P4 | Sortie quotidienne d'un permanent traitée comme une restitution. | Élevée | A06 ; décision D-14 avant tout code. |
| P5 | Révocation non propagée aux tablettes hors ligne. | Élevée | Fenêtre explicitée et acceptée (D-13) ; réglage du délai maximal. |
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
| D-07 | Titulaires permanents (A événement permanent / B registre / C hybride) | **C, après ADR** | A04 |
| D-08 | Identifiant imprimé : périmètre, format, titulaire ou carte | Séquence globale, clé de contrôle, jamais remise à zéro | A04 |
| D-09 | Durées de validité par catégorie | Valeurs du tableau de travail à confirmer | A04, A06 |
| D-10 | Autorité de révocation et de duplicata | Superviseur (existant), sans nouveau rôle | A06 |
| D-11 | Champs exacts des 24 modèles (photo, fonction, pays…) | Relevé sur les originaux | A05 |
| D-12 | Duplex, fond perdu, matériel d'impression | Épreuves sur le matériel du client | A05 |
| D-13 | Fenêtre de révocation hors ligne acceptée | Réduire le délai maximal des postes sensibles ; acceptation écrite | A06 |
| D-14 | Sortie des permanents sans restitution | Mouvement sans changement de statut | A06 |
| D-15 | Textes du verso (propriété, perte, restitution, légal) | Fournis par le client | A05 |
| D-16 | Base légale et conservation des données du personnel | À établir avec le client avant A04 | A04 |
| D-17 | Langues (français seul ou bilingue) | Français d'abord, champs réservés pour l'anglais | A03 |
| D-18 | Éléments de sécurité physiques (hologramme, guillochis, UV) | Hors périmètre sauf exigence | A05 |
| D-19 | Préfixe `IKOMA1:` du QR | Conserver ; en informer le client | A04 |
| D-20 | Mention de confidentialité sur les documents imprimés | Selon classification du client | A03 |

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
2. Il tranche ou délègue chaque décision du registre (section 8) ; les décisions bloquantes sont traitées en premier : D-01, D-02, D-07, D-16.
3. Il fournit les entrées client (A02.1).
4. Il déclare le dossier validé, lot par lot. **Aucun lot d'implémentation ne démarre avant cette validation écrite.**
5. Les documents restent des **projets de travail** jusqu'à cette validation ; rien n'est officiellement AILCT ou GAMAD tant que le dirigeant ne l'a pas déclaré.
