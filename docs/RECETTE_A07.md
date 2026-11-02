# Recette A07 — qualification et préparation à l'exploitation

Données fictives uniquement. **Réussi** = preuve automatisée citée. **Non testé** = non exécuté sur matériel réel ou hors périmètre de l'environnement de test. **Bloqué** = dépend d'une décision de l'AILCT. Aucune simulation n'est présentée comme un essai matériel.

## 1. Défauts constatés et corrigés

| # | Défaut | Correction | Non-régression |
|---|---|---|---|
| D1 | La fiche de participation créée à la première entrée d'un titulaire permanent apparaissait dans les outils d'**invités temporaires** (recherche et aperçu du poste d'accueil, copie locale des tablettes, liste et fiche d'administration des invités, retrait/modification), donc un agent ou un administrateur pouvait la manipuler comme un invité. | Ces fiches (`visitors.holder_id` non nul) sont exclues de la recherche, de la copie locale, du poste d'accueil et de l'administration des invités (404). | `RecetteA07Test` : « ne montre jamais la fiche de participation… » |
| D3 | Un aperçu d'import devenu illisible (clé de chiffrement changée, fichier corrompu) faisait échouer **tous** les imports suivants de l'événement, en silence (constaté pendant la recette locale). | L'aperçu illisible est retiré, le fait est journalisé (`liste.import_apercu_illisible`, sans donnée personnelle) et l'import continue. | `RecetteA07Test` « ne laisse pas un aperçu d'import illisible… » |
| D2 | Les dates d'une habilitation saisies en heure locale étaient lues comme de l'UTC par le serveur : décalage de plusieurs heures hors UTC. | Conversion en UTC explicite avant l'envoi (`toUtcIso`). | `habilitations.test.ts` (fuseaux Europe/Paris et Abidjan) |

## 2. Tableau de recette

| Parcours | Résultat | Preuve |
|---|---|---|
| Connexion / déconnexion, 2FA | Réussi | `auth.spec.ts`, `SecurityTest` |
| Permissions admin / superviseur / agent / hôte / encadreur | Réussi | `RecetteA07Test` « refuse côté serveur… » : **toutes** les routes à permission × 5 rôles, aucune réponse de succès ni de validation quand une permission manque |
| Identité institutionnelle, accueil configurable | Réussi | `HomeConfigTest`, `institution.spec.ts`, `offline.spec.ts`, `update.spec.ts` |
| Événements, catégories, zones, postes | Réussi | `business.spec.ts`, `tableau.spec.ts`, `Postes*`/`Business*` Pest |
| Journaux et décisions | Réussi | `RecetteA07Test` « journalise chaque décision… » (+ `audit:verify`), page Habilitations |
| Invité : création manuelle, import CSV, photo facultative | Réussi | `ManualVisitorsTest`, `ListImportTest`, `manual-visitors.spec.ts`, `import.spec.ts` |
| Badges visiteurs : émission, impression, contrôle, sortie, restitution | Réussi | `BadgeWorkshopTest`, `badge-workshop.spec.ts`, `controle.spec.ts`, `poste.spec.ts` |
| Clôture d'événement | Réussi | `DataLifecycleTest`, `RecetteA07Test` « empêche la clôture… » (titulaire présent) |
| Titulaire : création, modification, catégorie institutionnelle | Réussi | `PermanentHoldersTest`, `titulaires.spec.ts` |
| Carte : émission, impression CR80 recto verso, réimpression | Réussi (rendu navigateur et PDF) | `HolderCardsTest`, `cartes.spec.ts` |
| Perte / vol / révocation / remplacement, retrait d'habilitation | Réussi | `PermanentAccessTest`, `habilitations.spec.ts` |
| Entrées et sorties répétées, plusieurs événements | Réussi | `PermanentAccessTest` |
| Historique des mouvements d'un titulaire | **Partiel** | Décisions par événement (page Habilitations) et journal d'audit ; pas de vue par titulaire tous événements — non ajoutée (nouvelle fonction) |
| Aucun parcours temporaire ne modifie le registre permanent | Réussi | `RecetteA07Test` « ne modifie jamais le registre… », `ConsolidationTest` |
| Habilitations : carte valide ≠ accès, catégorie sans droit, zone sans politique, expirée/retirée, carte perdue/volée/révoquée, sortie sans restitution | Réussi (serveur, HTTP direct) | `PermanentAccessTest` (25 tests) |
| Hors ligne (10 situations) | Réussi en simulation navigateur | `poste-permanents.spec.ts`, `permanent-offline.test.ts`, `PermanentAccessTest` (rejeu, conflits, doublons) ; confiance expirée simulée par l'horloge de la page |
| Sans durée de confiance approuvée : refus des entrées hors ligne | Réussi | `PermanentAccessTest`, `permanent-offline.test.ts` |
| Sorties et évacuation hors ligne | Réussi | `PermanentAccessTest` (sortie carte volée, évacuation), `poste-permanents.spec.ts` |
| Migrations depuis zéro et retour arrière à vide | Réussi (27 migrations, 4 retours arrière puis rejeu) | essai local sur base vide |
| Sauvegarde / restauration | Réussi pour l'export logique local (dump → restauration, 27 migrations, 12 déclencheurs conservés, chaîne d'audit intacte) ; procédure chiffrée de production : réussi en CI (job `production`) | CI ; **non rejouée sur le serveur réel** |
| Commit servi | Réussi | étiquette OCI `org.opencontainers.image.revision` des images (volontairement absente de `/sante`) |
| Démonstration interdite en production, remise à zéro refusée | Réussi | `DemoProtectionTest`, `DemoLabTest` |
| Accueil public sans donnée sensible, service worker et caches | Réussi | `HomeConfigTest`, `SessionCookieRaceTest`, `hardening.spec.ts` |
| Fichiers et photographies | Réussi | portraits chiffrés hors dossier public, relus seulement par route authentifiée ou page d'impression (`ManualVisitorsTest`, `HolderCardsTest`) |
| Séparation des installations | Réussi | réglages en base propre à chaque installation (`HomeConfigTest` « isole la configuration… ») |
| Surveillance, redémarrage, reprise après interruption | Réussi en CI uniquement | `/sante`, jobs `production` ; **non rejoué sur le serveur réel** |

Suites exécutées sur le commit de la PR : Pest, Vitest, Pint, PHPStan, typecheck, ESLint, E2E navigateur complet (49 réussis, 1 ignoré : captures `L5_CAPTURES` uniquement ; l'import 1000 lignes a échoué une fois en local à cause de D3 puis réussi après correction).

## 3. Essais sur matériel réel — **Non testé sur matériel réel**

| Essai | Statut | À vérifier |
|---|---|---|
| Tablette administrateur, superviseur, agent d'accueil | Non testé sur matériel réel | affichage 1024 px, confort tactile, PIN, verrouillage |
| Caméra de lecture QR (carte CR80 et badge visiteur) | Non testé sur matériel réel | lecture à distance et angle réels, lumière, vitesse (le décodage jsQR du rendu est simulé) |
| Imprimante PVC et consommables | Non testé sur matériel réel | marges, alignement recto verso, sens de retournement, taille réelle 53,98 × 85,60 mm, QR décodable après impression |
| Cartes CR80 réelles | Non testé sur matériel réel | adhérence, contraste du QR, page d'alignement |
| Coupure et retour réels du réseau | Non testé sur matériel réel | tablette en mode avion pendant des opérations (l'E2E coupe le réseau du navigateur) |

## 4. Risques résiduels

1. Heures de début et de fin d'événement saisies sans fuseau et lues comme UTC (sans effet en UTC+0, à corriger avant tout déploiement dans un autre fuseau).
2. Les tablettes reçoivent l'identifiant (jamais le nom) de toutes les cartes permanentes, pour distinguer « habilitation absente » de « inconnu » ; minimisation possible si l'AILCT l'exige.
3. Un titulaire parti sans scanner sa sortie bloque la clôture de l'événement tant que sa sortie n'est pas enregistrée.
4. Statuts « confiance expirée » et expiration dépendent de l'horloge de la tablette (refus par excès : une horloge à l'envers est traitée comme expirée).
5. Sauvegarde et restauration de production vérifiées par la CI et par un essai logique local, pas par un exercice sur le serveur de l'institution.

## 5. Décisions réservées à l'AILCT (bloquantes pour l'exploitation réelle)

Classification officielle des zones ; habilitations institutionnelles réelles ; durées maximales de confiance hors ligne (sinon **toute entrée hors ligne est refusée**) ; procédure de panne prolongée ; conditions d'évacuation et de sortie dégradée ; durées et fondements de conservation des données personnelles ; modèles graphiques officiels des cartes (sinon mention « MODÈLE PROVISOIRE ») ; validation de l'imprimante et des consommables.

## 6. Conclusion

Le système est **qualifié sur l'environnement de test** : parcours essentiels, habilitations, isolation des permissions, hors ligne simulé, protections de démonstration et de remise à zéro, migrations et sauvegarde logique. Il n'est **pas prêt à être mis en exploitation réelle** tant que (a) les essais sur matériel réel ci-dessus ne sont pas faits et (b) les décisions de l'AILCT de la section 5 ne sont pas prises. Sans ces décisions, les refus sécurisés par défaut restent en vigueur.
