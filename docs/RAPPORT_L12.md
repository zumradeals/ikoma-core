# Rapport L12 — production : déploiement durci, installation guidée, sauvegardes

Date : 28 octobre 2026. Branche `claude/stoic-wright-gib9iu`, PR n° 25.

## Fait
- **Images de production** (`docker/prod/Dockerfile`) : interface construite à part ; dépendances PHP sans outils de test ; code appartenant à root, non modifiable par l'application ; PHP-FPM et Nginx sans privilèges ; étiquetées par version Git.
- **`docker-compose.prod.yml`** : aucun code monté ; base et PHP sur un réseau Docker interne sans accès à Internet ; seul nginx publie des ports (80 → redirection, 443) ; conteneurs en lecture seule, toutes capacités Linux retirées (PostgreSQL : cinq capacités de démarrage), `no-new-privileges`, limites de mémoire et de processeurs, journaux avec rotation (5 × 10 Mo).
- **TLS** : Nginx ; Let's Encrypt (webroot, `renew-cert.sh`), certificat fourni, ou auto-signé pour un réseau fermé ; HTTP → HTTPS, HSTS.
- **Secrets** : générés par `install.sh` dans `prod-data/secrets/` (0400), jamais dans Git ni dans les images ; clé de l'application, mot de passe propriétaire des migrations, mot de passe du compte applicatif, jeton d'installation : quatre secrets distincts. Séparation migrateur / compte applicatif conservée.
- **Installation guidée** : `deploy/prod/install.sh` (prérequis, secrets, certificat, construction, démarrage, sauvegarde quotidienne) et assistant web `/installation` à usage unique (jeton, vérifications, premier administrateur avec 2FA imposé, premier événement) ; fermé (404) ensuite ; accès refusés journalisés par empreinte d'adresse.
- **Données de démonstration** : `db:seed` refuse `APP_ENV=production` ; la démonstration passe en `APP_ENV=demo` avec un bandeau.
- **Sauvegardes** : `backup.sh` (base + fichiers + effectifs + SHA-256, chiffrés avec une clé **publique** ; rétention ; copie externe configurable ; résultat enregistré) ; alerte sur le tableau de l'administrateur au-delà de 26 h ou en cas d'échec.
- **Restauration** : `restore.sh` dans une base séparée par défaut, avec `audit:verify` et comparaison des effectifs ; remplacement de la production seulement sur demande explicite et confirmation.
- **Mise à jour et retour arrière** : `update.sh` (sauvegarde obligatoire, test de fumée, retour automatique en cas d'échec), `rollback.sh`, `smoke.sh`.
- **Santé** : `/sante` sans connexion ni donnée sensible ; 503 si la base ne répond pas.
- **Durcissement du serveur** : guide + `verifier-serveur.sh` (lecture seule). **Hébergement** : recommandation et questions au client.
- **Corrections apportées à L11 par la recette** : style et bouton d'impression des étiquettes bloqués par la CSP (nonce par réponse) ; sorties de données de test corrompues par des caractères spéciaux ; test de lecture des QR.

## Preuves (CI, dernier commit)
- Job **`production`** : installation depuis zéro avec le seul `install.sh`, assistant, fermeture, 12 vérifications de sécurité avant et après installation (HTTPS, en-têtes, aucun port de base publié, conteneurs non-root…), refus du jeu de démonstration (pour la bonne raison), sauvegarde chiffrée vérifiée illisible, `/sante` indiquant la sauvegarde, restauration avec `audit:verify` et effectifs identiques, mise à jour et retour arrière : **vert**.
- Job `verification` : lint, tests PHP (deux fois), tests front, e2e complet, contrôle des exceptions, audit des dépendances, gitleaks, ZAP baseline : **verts**. **Trivy : rouge** (voir risques).
- Local : Pest 202, PHPStan, Pint, `docker compose config`, syntaxe de tous les scripts.

## Pas fait
- Aucun serveur réel touché ; l'hébergement n'est pas choisi (HEBERGEMENT.md).
- Centralisation des journaux et détection d'intrusion (audit F3), SBOM et signature des images (audit G2), gestion des appareils (tablettes), test d'intrusion indépendant, PSSI du client.
- Pas d'image publiée sur un registre : les images sont construites sur le serveur à partir de l'étiquette Git choisie.
- Politique de conservation des sauvegardes alignée sur la purge des données : à décider avec le client.

## Commandes de vérification
```sh
bash deploy/prod/install.sh --domaine localhost --tls autosigne --cle-sauvegarde cle-publique.asc --ports 8080:8443 --sans-planification
bash deploy/prod/ci-recette.sh cle-privee.asc
bash deploy/prod/smoke.sh --apres-installation
sudo bash deploy/prod/verifier-serveur.sh
```

## Décisions à prendre par le client
Lieu d'hébergement et juridiction ; gardien de la clé privée de sauvegarde (et suppléant) ; emplacement de la copie externe ; durées de conservation des sauvegardes ; fréquence des sauvegardes pendant un événement ; organisation de l'astreinte et de la surveillance de `/sante` ; acceptation du risque résiduel ci-dessous.

## Risques résiduels
- **Trivy** : les paquets système Debian 13 des images PHP et PostgreSQL comptent des failles élevées ou critiques **sans correctif publié** (util-linux, libxml2, curl, gnupg, systemd, perl, ncurses). Aucune action possible côté application ; risque accepté provisoirement par le dirigeant (option B), à réexaminer à chaque mise à jour. L'image Nginx n'en compte aucune.
- PostgreSQL démarre en root pour initialiser son volume puis abandonne ses droits (comportement de l'image officielle), avec capacités réduites.
- Un serveur dont le disque n'est pas chiffré expose la base si le disque est dérobé (DURCISSEMENT_SERVEUR.md, section 6).
- Les sauvegardes sont illisibles sans la clé privée : sa perte est irréversible.
