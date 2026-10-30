# IKOMA ACCESS — Serveur de travail et mises à jour

Ce document décrit le déploiement historique de `ikoma.dgafrique.com` depuis `deploy/demo/deploy.sh`. Ce serveur est l'environnement de travail du propriétaire et **n'est pas le VPS d'un client**. Son nom de configuration `APP_ENV=demo` reste historique.

## Déploiement

Sur le serveur déjà configuré :

```sh
cd /opt/ikoma
sudo sh deploy/demo/deploy.sh
```

La procédure de déploiement :
- récupère la branche configurée (`main` par défaut) ;
- garde les secrets et la base PostgreSQL existants ;
- construit les conteneurs Docker, applique les migrations et vérifie leur disponibilité ;
- configure ou conserve le serveur HTTPS du sous-domaine ;
- **ne crée plus de comptes, événements ou visiteurs fictifs**.

Si le dépôt GitHub est privé, utilisez une clé de déploiement en lecture seule, sans inscrire de jeton dans les commandes de l'historique du serveur.

Les variables facultatives restent `DOMAIN`, `BRANCH`, `DIR` et `REPO`. Le serveur peut héberger d'autres sites : le déploiement ne doit pas écraser leurs configurations.

## Première installation sans données fictives

Le nouvel environnement doit être initialisé par le mécanisme d'installation adapté à sa configuration, et par son premier administrateur réel, avec 2FA confirmée. La commande `db:seed` est exclusivement destinée à une recette fictive demandée explicitement ; elle n'est plus lancée par le script de déploiement.

## Ancienne base contenant le seed

Un simple `git pull`, un redémarrage ou un nouveau déploiement ne supprime pas les enregistrements existants.

**Ne lancez jamais `make migrate-fresh-seed` pour faire disparaître les données fictives : cette commande détruit toute la base.**

Pour l'ancien événement « Exercice fictif IKOMA — démonstration », les 300 visiteurs, 400 badges et les comptes `@exemple.test`, suivez la procédure transactionnelle et contrôlée : [SORTIE_MODE_DEMO.md](SORTIE_MODE_DEMO.md). Elle inclut la sauvegarde, la simulation, la préservation d'un compte administrateur réel et le refus de supprimer des données ayant servi aux opérations.

## Contrôles après mise à jour

```sh
curl -sI https://ikoma.dgafrique.com/sante | head -1
cd /opt/ikoma
docker compose -f docker-compose.yml -f deploy/demo/docker-compose.demo.yml ps
```

Vérifiez également l'ouverture de la landing IKOMA ACCESS et de la connexion, le parcours 2FA et le fonctionnement des espaces Admin, Superviseur et Agent.

## Note de conformité

L'absence de bandeau jaune indique seulement qu'il ne s'affiche plus. Elle **ne constitue pas une certification de production client**. Les vraies données personnelles doivent être traitées dans un environnement conforme aux exigences d'hébergement, de confidentialité et de sécurité applicables. Les tests automatisés conservent leurs propres fixtures sur une base isolée.


## Protection HTTPS lors des mises à jour (30 octobre 2026)

Le script de déploiement ne modifie plus un vhost Nginx IKOMA déjà présent :
- ni réécriture du fichier `/etc/nginx/sites-available/ikoma-demo.conf` ;
- ni appel à Certbot pour un certificat déjà configuré ;
- contrôle de `nginx -t` et de `https://ikoma.dgafrique.com/sante` via 127.0.0.1, en validant le certificat avec SNI (sans `-k`).

**Important :** cette protection empêche la reproduction de l'incident, mais **ne répare pas** un vhost
déjà incorrect. Si HTTPS échoue, ne relancez pas le déploiement en boucle : identifiez d'abord le
certificat réellement servi et tout conflit de vhost. Pour diagnostic lecture seule :

```sh
sudo nginx -t
sudo nginx -T 2>&1 | grep -nE 'conflicting server name|ikoma\.dgafrique\.com|listen.*443'
openssl x509 -in /etc/letsencrypt/live/ikoma.dgafrique.com/fullchain.pem -noout -subject -ext subjectAltName
echo | openssl s_client -connect 127.0.0.1:443 -servername ikoma.dgafrique.com 2>/dev/null | openssl x509 -noout -subject -ext subjectAltName
curl --noproxy '*' --resolve 'ikoma.dgafrique.com:443:127.0.0.1' -I https://ikoma.dgafrique.com/sante
```

Le premier démarrage, lorsqu'aucun vhost IKOMA n'existe, conserve le chemin de provisionnement
historique par Certbot. Une vérification TLS est obligatoire après ce provisionnement.
Le port local, le DNS, les routes et les permissions doivent être contrôlés avant toute intervention
sur une installation réelle.

## Déploiement automatique : décision à cadrer séparément

Le mécanisme GAMAD repose sur un minuteur systemd, un compte applicatif dédié, des versions
isolées, des sauvegardes et un retour arrière. **Ce script IKOMA n'offre pas aujourd'hui les
mêmes garanties.** `docker-compose.yml` monte `/opt/ikoma` directement dans les conteneurs ;
`deploy.sh` exécute un `git reset --hard` dans ce dossier. Le déclencher automatiquement
sur chaque commit créerait un risque de modification des fichiers servis en cours de requête.

L'automatisation sera une évolution distincte, non activée par ce correctif. Conditions à
formaliser avant tout minuteur : compte et privilèges Docker (équivalents à un accès hôte élevé
sans isolation), verrou de déploiement, contrôle CI, construction hors du dossier servi,
sauvegarde et restauration vérifiées, migrations compatibles avec le rollback, vérification
TLS/HTTP, état de la version réellement déployée, plafonnement des relances, journal
d'audit et procédure de retour arrière. **Ne pas installer de minuteur root reprenant
le script actuel tel quel.**
