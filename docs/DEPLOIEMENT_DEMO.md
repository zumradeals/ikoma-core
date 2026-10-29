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
curl -sI https://ikoma.dgafrique.com/up | head -1
cd /opt/ikoma
docker compose -f docker-compose.yml -f deploy/demo/docker-compose.demo.yml ps
```

Vérifiez également l'ouverture de la landing IKOMA ACCESS et de la connexion, le parcours 2FA et le fonctionnement des espaces Admin, Superviseur et Agent.

## Note de conformité

L'absence de bandeau jaune indique seulement qu'il ne s'affiche plus. Elle **ne constitue pas une certification de production client**. Les vraies données personnelles doivent être traitées dans un environnement conforme aux exigences d'hébergement, de confidentialité et de sécurité applicables. Les tests automatisés conservent leurs propres fixtures sur une base isolée.
