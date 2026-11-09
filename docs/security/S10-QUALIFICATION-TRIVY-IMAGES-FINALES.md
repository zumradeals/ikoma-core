# S10 — Qualification des vulnérabilités Trivy des images finales

Source : journal de l'étape « Lister les constats HIGH/CRITICAL » du job `production`, exécution CI 42543639601 (commit `39d07ec`, PR #100). Les rapports complets restent dans l'artefact GitHub `analyses-images-finales` (non téléchargeable depuis la session de travail : l'hôte de stockage est bloqué par le proxy, aucun contournement n'a été tenté ; l'inventaire ci-dessous vient du journal CI, produit à partir de ces mêmes rapports). Scanner : Trivy 0.76.1, seuil HIGH/CRITICAL inchangé, aucune exclusion.

## Inventaire constaté (3 constats HIGH, 0 CRITICAL)

| Image | CVE | Composant | Version installée | Sévérité | Correctif publié | Statut au constat |
|---|---|---|---|---|---|---|
| nginx (Alpine 3.24.2) | CVE-2026-107891 | musl | 1.2.6-r2 | HIGH | oui : 1.2.6-r3 | à corriger |
| nginx (Alpine 3.24.2) | CVE-2026-107891 | musl-utils | 1.2.6-r2 | HIGH | oui : 1.2.6-r3 | à corriger |
| nginx (Alpine 3.24.2) | CVE-2026-85091 | zlib | 1.3.2-r0 | HIGH | oui : 1.3.2-r1 | à corriger |

Images `php` (Alpine 3.24.4) et `db` (Alpine 3.24.3) : aucun HIGH/CRITICAL au même constat.

## Exposition réelle (analyse)

Le détail technique de chaque CVE (vecteur, fonction concernée) n'a pas pu être consulté depuis la session ; l'exposition n'est donc **pas** qualifiée « non exploitable ». Éléments vérifiés : les deux composants (bibliothèque C et bibliothèque de compression) sont chargés par le processus nginx, seule porte d'entrée du système ; l'image tourne sans privilèges (UID 101), et les correctifs existent déjà dans les dépôts Alpine. Traitement retenu : correction, pas acceptation de risque.

## Mesure appliquée

`docker/prod/Dockerfile`, étage `nginx` : `apk upgrade --no-cache` sous `USER root`, puis retour à `USER 101` (utilisateur sans privilèges de l'image amont). C'est le mécanisme déjà utilisé pour l'étage PHP. Aucun changement de contrat (ports, fichiers, utilisateur d'exécution). Aucun seuil ni contrôle CI modifié.

## Statut (mis à jour après preuve CI)

- **Preuve** : exécution CI 42545445031 (commit `28d9cdb`, qui contient le correctif `62298c8`) : étapes « Analyser les trois images finales » et « Refuser une preuve finale manquante ou incohérente » en succès ; journal : « Total HIGH/CRITICAL : 0 » et « Contrôle complet : aucun HIGH/CRITICAL » ; l'exécution 42544753132 (`62298c8`) montrait déjà le même succès. Seuil et contrôles inchangés.
- CVE-2026-107891 (musl, musl-utils) et CVE-2026-85091 (zlib) : **corrigées** dans l'image nginx (preuve CI ci-dessus).
- Aucune acceptation de risque n'a été nécessaire ni enregistrée.
- Limites : le rapport complet reste dans l'artefact `analyses-images-finales` (non lisible depuis la session de travail) ; seuls HIGH/CRITICAL sont qualifiés ici, pas les niveaux inférieurs. `apk upgrade` rend la construction dépendante de l'état du dépôt Alpine au moment du build (même choix que l'étage PHP) : un nouveau HIGH publié plus tard bloquera de nouveau le contrôle, ce qui est le comportement voulu.
