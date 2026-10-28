# Rapport L10 — Espace de l’hôte

## Fait

- Départ de main au commit 1be551b après fusion de L9 (#16) ; règles, plan, matrice, schéma, décisions et PDF de fonctionnement relus. Branche dédiée ; PR #17 fusionnée par le dirigeant sur main au commit 64340ede29138e5b21ebcc7c10daaa7a22a75061. Rapport et captures livrés dans une PR documentaire complémentaire.
- Mes invités : fiches rattachées au compte par hosts.user_id, contrôle d’objet côté serveur sur chaque action. Ajout, modification et retrait avant toute entrée ; compte sans fiche expliqué et vide. Aucun accès aux autres hôtes, à l’administration, au pilotage, au journal ou aux alertes.
- Décisions du dirigeant : catégorie Visiteur uniquement ; délai initial de deux minutes, réglable dans Administration > Comptes. Les catégories initialement fixées par l’administration peuvent être conservées ; l’hôte ne peut pas attribuer une catégorie supérieure ni contredire un badge ou un groupe existant.
- Notifications internes d’arrivée, de non-listé et d’avis hors période ; compteur, lecture, dédoublonnage et rechargements partiels Inertia toutes les 2,5 s. Arrivée notifiée à partir de l’entrée effective du poste, y compris les arrivées de groupes.
- Décision non listée sous verrou et une seule fois ; conservation de host_validation, refus avec motif facultatif et alerte au superviseur. L’agent récupère la réponse dans le poste ; le non-listé accepté reste limité à l’accueil accompagné.
- Avis hors période enregistré et visible à l’accueil ; lié aux dates courantes, donc non réutilisable après changement de période. Le circuit d’avis obtenu par téléphone reste disponible.
- Escalade planifiée toutes les dix secondes après le délai réglé, y compris sans compte hôte lié. Rattrapage des demandes en attente lors d’un rattachement ultérieur. Une alerte normale de non-listé L8 est élevée à haute sans doublon.
- Doublons/homonymes, nettoyage et neutralisation de formules cohérents avec L3. Avertissement générique, sans fiche étrangère exposée. Verrou d’événement partagé avec les imports. Retrait logique propagé à la recherche, à la copie et aux effectifs actifs des groupes ; les historiques restent conservés.
- Version L6 enrichie d’une révision d’événement pour distinguer les modifications d’une même seconde ; aucune donnée authentifiée dans le cache du service worker. Contrôles d’autorisation sous verrou face aux changements de rattachement.
- Audit réutilisé, identifiants/noms de champs/nombres seulement. Aucun nom ni motif libre dans l’audit, erreurs techniques neutralisées. Aucun test antérieur supprimé ou affaibli ; droits des trois journaux en ajout seul inchangés.
- docs/NOTIFICATIONS.md : étude sans code du push futur et de ses conditions de sécurité. Schéma, journal et README complétés. Données fictives, mots de passe de recette générés uniquement à l’exécution.

## Pas fait, conformément au lot

Aucun e-mail, SMS, Web Push, service externe ou dépendance nouvelle. L’espace hôte n’est pas un écran hors ligne et ne promet pas de notification avec l’onglet fermé. Aucune nouvelle permission, annulation de badge par l’hôte, modification des durées de conservation ou mesure de production L11/L12.

## Vérifications

Vérifications locales exécutées : Pint, Larastan niveau 6, ESLint, vue-tsc, tests Vue, build Vite. PostgreSQL et Chromium sont vérifiés dans GitHub Actions sur Docker ; ils ne sont pas revendiqués comme exécutés localement.

Commandes de recette : make install, make up, make migrate-fresh-seed, make lint, make test (deux fois successivement), make test-front et make e2e. Les résultats finaux et captures sont consignés ci-dessous après la recette complète.

## Limites et questions ouvertes

- Notifications en moins de cinq secondes : réseau disponible et page visible ; le délai se compte après l’enregistrement serveur, donc après réception des opérations d’une tablette hors ligne. Aucun accord de l’hôte n’est inventé hors ligne.
- Le délai de deux minutes est vérifié par le planificateur toutes les dix secondes. Sans compte lié, l’agent doit contacter l’administrateur ; la demande reste bloquée et remonte au superviseur.
- Le retrait conserve les références historiques. Un badge déjà remis n’est pas annulé automatiquement : l’hôte n’a pas ce droit ; restitution par l’accueil ou annulation par le superviseur restent nécessaires.
- Le push navigateur reste une décision future du dirigeant (tiers de distribution, consentement, sécurité et exception réseau). Les durées de conservation restent à valider au lot L11 avec le client et un juriste.

Aucun arbitrage métier supplémentaire ne bloque L10. Le dirigeant a validé et fusionné la PR fonctionnelle #17 le 28 octobre 2026.

## Résultats de la recette complète

CI [40671812253](https://github.com/zumradeals/ikoma-core/actions/runs/40671812253), commit fonctionnel d743cf8424410cd3f27cf1de7a7d93b1df4ccabf : **verte** sur Docker PHP 8.5.12 / PostgreSQL 18.6.

| Commande | Résultat exécuté |
|---|---|
| make install / make up | Installation et démarrage réussis |
| make lint | Pint, Larastan niveau 6, ESLint, vue-tsc passent |
| make migrate-fresh-seed | **2 secondes**, limite 120 secondes |
| make test, deux fois de suite | **153 tests passent**, 15 106 puis 15 254 assertions |
| make test-front | **89 tests Vue passent** |
| make e2e | **34 parcours passent** ; 1 scénario de captures L5 est ignoré par sa condition préexistante L5_CAPTURES=1 |

Notification d’arrivée mesurée dans le parcours à deux contextes : **799 ms après l’accusé de l’enregistrement serveur**, seuil strict de 5 000 ms. Le même parcours valide un non-listé depuis le téléphone, poursuit sa photo/validation/badge et son entrée sur la tablette, vérifie exactement « Zones : Hall accueil fictif » (pas de salle principale), ajoute un invité et vérifie le retour à la liste.

Les [trois captures mobiles](captures/L10/README.md) proviennent de cette recette et ont été inspectées. Le test vérifie l’absence de défilement horizontal à 380 px. Aucun compte réel ni secret dans ces captures.
