# Déploiement en production — IKOMA Accueil (lot L12)

> **Ce document décrit la production.** La démonstration (données fictives) reste décrite dans [DEPLOIEMENT_DEMO.md](DEPLOIEMENT_DEMO.md) et ne doit **jamais** recevoir de vraies données. Une production et une démonstration ne partagent ni serveur, ni secrets, ni sauvegardes.
> Statut : projet de travail. Aucun serveur réel n'a été touché ; la procédure est exécutée de bout en bout par la CI sur un serveur de recette éphémère (job « production »).

## 1. Ce que vous obtenez

| Élément | Comment |
|---|---|
| Une seule porte d'entrée | Nginx : le port 80 redirige vers le 443 (HTTPS, HSTS). La base de données et PHP n'ont **aucun port ouvert** et sont sur un réseau interne sans accès à Internet. |
| Images figées par version | `ikoma-php:<version>` et `ikoma-nginx:<version>`, construites par `deploy/prod/build.sh`. Aucun code n'est monté depuis le serveur ; le code de l'image n'est pas modifiable par l'application. |
| Conteneurs sans privilèges | PHP et Nginx tournent avec un utilisateur ordinaire, en lecture seule, sans capacités Linux. PostgreSQL démarre avec les seules capacités nécessaires puis abandonne les droits de root (exception documentée de l'image officielle). |
| Secrets hors Git et hors images | Générés par `install.sh` dans `prod-data/secrets/` (droits `0400`). La clé de chiffrement de l'application, les deux mots de passe de la base (propriétaire des migrations, compte applicatif à droits minimaux) et le jeton d'installation sont séparés. |
| Installation guidée | `install.sh` puis un assistant web à usage unique. |
| Sauvegardes chiffrées | Chaque nuit, avec une clé **publique** : la clé privée n'est jamais sur le serveur. |
| Restauration testée | `restore.sh`, vérifiée par la CI à chaque modification. |

## 2. Avant de commencer (à préparer avec le client)

1. **Un serveur** dédié ou virtuel dédié, Linux (Debian 13 ou Ubuntu 24.04 recommandés), 4 Go de mémoire, 2 processeurs, 40 Go de disque au moins. Durcissez-le d'abord : [DURCISSEMENT_SERVEUR.md](DURCISSEMENT_SERVEUR.md). Le choix de l'hébergement est décrit dans [HEBERGEMENT.md](HEBERGEMENT.md) (décision du dirigeant et du client requise).
2. **Un nom de domaine** qui pointe vers le serveur (certificat public), ou un nom interne (réseau fermé).
3. **Docker** (version récente) avec Docker Compose v2, plus `openssl`, `gpg`, `curl`, `git`.
4. **Une paire de clés de sauvegarde**, créée **sur un poste de confiance, pas sur le serveur** :
   ```sh
   gpg --quick-generate-key "Sauvegardes IKOMA <sauvegardes@votre-organisation>" rsa3072 encrypt 5y
   gpg --armor --export "Sauvegardes IKOMA" > cle-publique.asc          # à copier sur le serveur
   gpg --armor --export-secret-keys "Sauvegardes IKOMA" > cle-privee.asc # à garder HORS du serveur, dans un coffre, en double exemplaire
   ```
   **Sans la clé privée, les sauvegardes sont illisibles pour tout le monde, vous compris.** Conservez-la dans au moins deux lieux sûrs différents.

## 3. Installation

```sh
git clone https://github.com/zumradeals/ikoma-core.git /opt/ikoma
cd /opt/ikoma
git checkout <étiquette-de-version>        # jamais une branche en production
```

Selon votre cas (une seule commande à lancer) :

```sh
# Serveur public : certificat Let's Encrypt obtenu et renouvelé automatiquement
sudo bash deploy/prod/install.sh --domaine ikoma.exemple.org --tls letsencrypt --courriel exploitation@exemple.org --cle-sauvegarde cle-publique.asc

# Réseau fermé, sans accès à Internet : certificat auto-signé (à remplacer ensuite par celui de l'autorité interne du client)
sudo bash deploy/prod/install.sh --domaine ikoma.interne --tls interne --cle-sauvegarde cle-publique.asc

# Le client fournit son certificat
sudo bash deploy/prod/install.sh --domaine ikoma.interne --tls fourni --certificat fullchain.pem --cle-privee privkey.pem --cle-sauvegarde cle-publique.asc
```

Le script dit ce qu'il fait à chaque étape, s'arrête à la première erreur, et peut être relancé sans danger (les secrets déjà créés sont conservés). À la fin il affiche l'adresse de l'assistant et un **jeton d'installation**.

### L'assistant d'initialisation (une seule fois)
1. Ouvrez `https://<votre-domaine>/installation`.
2. **Vérifications** : base de données, stockage, clé de chiffrement, HTTPS, mode production. Un point obligatoire en échec bloque l'installation et dit quoi faire.
3. Saisissez le jeton, puis le premier administrateur (mot de passe de 14 caractères au moins) et le premier événement.
4. L'assistant se ferme **pour toujours** : il devient introuvable (404), et chaque tentative d'accès ensuite est journalisée (empreinte de l'adresse, jamais l'adresse).
5. Connectez-vous : la configuration de la **double authentification** est exigée dès la première connexion. Conservez les codes de secours hors de l'application.
6. Vérifiez : `sudo bash deploy/prod/smoke.sh --apres-installation`. Tout doit être coché ✓.

Aucune donnée de démonstration n'est jamais créée : le jeu de données fictives (`db:seed`) **refuse de s'exécuter** quand `APP_ENV=production`.

## 4. Sauvegardes

- **Quand** : chaque nuit à 02 h 17 (planification créée par `install.sh` en root, sinon à ajouter vous-même : `17 2 * * * bash /opt/ikoma/deploy/prod/backup.sh`).
- **Quoi** : la base de données (format PostgreSQL compressé), les fichiers (photos et pièces chiffrées par l'application, imports), un fichier d'effectifs (nombres de lignes, aucune donnée personnelle) et les empreintes SHA-256. Tout est chiffré avec la **clé publique**.
- **Où** : `prod-data/backups/` (conservation réglable : `BACKUP_RETENTION_DAYS` dans `prod-data/backup.conf`, 14 jours au départ) **et** une copie externe : renseignez `BACKUP_COPY_COMMAND` (disque réseau, serveur distant, stockage objet), par exemple :
  ```sh
  BACKUP_COPY_COMMAND='rsync -a --chmod=F600 "$1" sauvegarde@serveur-distant:/sauvegardes/ikoma/'
  ```
  **Sans copie externe, une sauvegarde ne protège pas contre la perte du serveur** (incendie, vol, panne de disque). Ne vous en passez pas en production.
- **Alerte** : le résultat de chaque sauvegarde est enregistré. Si la dernière réussie a plus de 26 heures (réglage `sauvegarde_alerte_heures`) ou si elle a échoué, l'administrateur voit « Sauvegarde à vérifier » en tête de son tableau de bord, et `/sante` passe à « dégradé ».
- **Objectifs proposés** (à valider avec le client) : perte de données maximale (RPO) de 24 heures ; durée de reprise (RTO) de moins de 2 heures avec la clé privée sous la main. Pendant un événement, **le RPO réel est bien meilleur** : les tablettes gardent leurs opérations en attente et les renvoient à la reprise. Pour un RPO plus court, planifiez la sauvegarde plus souvent (toutes les heures pendant l'événement).

## 5. Restaurer

La restauration **n'écrase jamais la production par défaut** : elle crée une base séparée pour que vous puissiez la contrôler.

```sh
bash deploy/prod/restore.sh --base prod-data/backups/ikoma-base-20271001T021700Z.dump.gpg \
                            --fichiers prod-data/backups/ikoma-fichiers-20271001T021700Z.tar.gpg \
                            --cle-privee /chemin/vers/cle-privee.asc
```
Elle déchiffre (la clé privée n'est utilisée que dans un dossier temporaire effacé ensuite), restaure dans `ikoma_restauration`, **vérifie la chaîne d'audit** (`audit:verify`) et **compare le nombre de lignes** aux effectifs relevés à la sauvegarde. Elle s'arrête en erreur à la moindre différence.

Pour **remplacer** la production (sinistre) : ajoutez `--remplacer-la-production`, tapez `REMPLACER` pour confirmer. L'application est arrêtée, la base et les fichiers sont remplacés, les droits minimaux sont réappliqués, puis tout redémarre. Les tablettes enverront ensuite leurs opérations en attente. **Faites un exercice de restauration au moins une fois avant la mise en service, puis chaque trimestre.**

## 6. Mettre à jour et revenir en arrière

```sh
cd /opt/ikoma
sudo bash deploy/prod/update.sh <nouvelle-étiquette>
```
1. refuse si le dossier contient des modifications locales ;
2. **sauvegarde obligatoire** (si elle échoue, rien ne change : **aucune migration sans sauvegarde préalable**) ;
3. récupère la version, construit les images, applique les migrations, redémarre (quelques secondes ; une tablette hors ligne continue de travailler) ;
4. lance le test de fumée ; **en cas d'échec, retour arrière automatique**.

**Retour arrière manuel** : `sudo bash deploy/prod/rollback.sh` (redémarre la version précédente, dont les images restent sur le serveur). **Règle du projet : les migrations sont additives** (ajout de tables, de colonnes, de réglages) ; le code précédent fonctionne donc sur la base migrée. Une migration destructive n'est livrée qu'avec une procédure de restauration documentée dans la version concernée.

## 7. Surveiller

- `https://<domaine>/sante` : sans connexion, sans donnée sensible, sans numéro de version. Réponse `{"statut":"ok"|"degrade"|"ko", base, disque, sauvegarde, synchronisation}` ; code 503 si la base ne répond pas.
- À brancher sur l'outil de surveillance du client (point de contrôle toutes les minutes ; alerter si `statut` ≠ `ok` pendant plus de 10 minutes ou si le code n'est pas 200).
- Journaux : `docker compose --project-directory /opt/ikoma --env-file prod-data/env -f docker-compose.prod.yml logs --tail=100 php` (rotation automatique : 5 fichiers de 10 Mo par service). Ils ne contiennent ni nom de visiteur ni paramètre de requête.

## 8. Rotation des secrets

| Secret | Quand | Comment |
|---|---|---|
| Mot de passe du compte applicatif (`db_app`) | tous les ans, ou en cas de doute | remplacer `prod-data/secrets/db_app` (nouveau mot de passe aléatoire, `chown 10001:10001`, `chmod 0400`) puis `docker compose … run --rm migrator sh scripts/migrate-prod.sh && docker compose … up -d` |
| Mot de passe propriétaire (`db_owner`) | tous les ans | changer d'abord dans PostgreSQL (`ALTER ROLE ikoma PASSWORD …` via `docker compose exec db psql -U ikoma`), puis remplacer le fichier, puis redémarrer |
| Clé de l'application (`app_key`) | **ne pas la changer à la légère** : elle chiffre les photos, les pièces et les sessions ; un changement les rend illisibles. À faire seulement après avoir déchiffré/rechiffré, avec l'aide du concepteur. |
| Certificat HTTPS | automatique avec Let's Encrypt ; sinon à l'échéance | `deploy/prod/renew-cert.sh` |
| Clés de sauvegarde | tous les 3 à 5 ans | créer une nouvelle paire, remplacer `prod-data/backup-public.asc` ; **garder l'ancienne clé privée** tant qu'il reste des sauvegardes qu'elle déchiffre |

## 9. Ce qui reste à décider ou à faire (non couvert par ce lot)

- Le **lieu d'hébergement** et ses conséquences juridiques ([HEBERGEMENT.md](HEBERGEMENT.md)).
- La **politique de conservation** des sauvegardes (durée, lieux, destruction) : elle doit rester cohérente avec la durée de conservation des données ([CONSERVATION_DONNEES.md](CONSERVATION_DONNEES.md)) — une donnée purgée de la production reste dans les anciennes sauvegardes jusqu'à leur expiration.
- Un **test d'intrusion indépendant** avant tout usage réel.
- Les **failles sans correctif publié** des images de base (voir [REVUE_SECURITE.md](REVUE_SECURITE.md)) : à réexaminer à chaque mise à jour.
- La **supervision** : branchement sur l'outil du client et astreinte.
