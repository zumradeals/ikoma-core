# IKOMA ACCESS — Sortie des données de démonstration

Cette procédure concerne **le serveur de travail** `ikoma.dgafrique.com`. Elle ne signifie pas que le VPS est un serveur de production client.

## Ce qui change avec cette mise à jour

- Le bandeau jaune « Démonstration : données fictives uniquement » est retiré des pages publiques et authentifiées.
- Le script `deploy/demo/deploy.sh` **ne lance plus automatiquement** `db:seed` à chaque déploiement.
- Les comptes et données déjà présents ne sont **jamais effacés automatiquement** par une mise à jour de code.
- La commande `demo:purger` repère les UUID fixes de `DemoSeeder` et les cinq comptes `@exemple.test` créés par `DatabaseSeeder`.
- La commande ne touche ni aux autres événements ni aux autres comptes, ni au journal d'audit intègre.

## 1. Conditions préalables

1. Avoir déployé une version contenant `demo:purger` et vérifié la disponibilité de l'application.
2. **Créer au préalable un véritable compte administrateur actif**, distinct de `@exemple.test`, puis **activer et confirmer sa 2FA**. Valider que ce compte permet de se connecter.
3. Vérifier qu'aucun poste/tablette n'utilise encore les badges ou l'événement fictif.
4. Préparer et contrôler la sauvegarde complète de la base (commande ci-dessous). Elle peut contenir des données personnelles : ne pas la déposer dans Git.

Les commandes suivantes s'exécutent **sur le serveur de travail**, et non dans GitHub :

```sh
cd /opt/ikoma
umask 077
docker compose -f docker-compose.yml -f deploy/demo/docker-compose.demo.yml exec -T db \
  pg_dump -U ikoma -d ikoma -Fc > /root/ikoma-avant-retrait-demo.dump
test -s /root/ikoma-avant-retrait-demo.dump
chmod 600 /root/ikoma-avant-retrait-demo.dump
```

La sauvegarde doit être vérifiée, conservée hors du dossier servi par le web et stockée de façon sûre.

## 2. Simulation obligatoire

```sh
cd /opt/ikoma
docker compose -f docker-compose.yml -f deploy/demo/docker-compose.demo.yml \
  exec -T php php artisan demo:purger
```

La commande affiche **le nombre d'événements, de visiteurs, de badges et de comptes fictifs identifiés**. Elle n'efface rien sans `--confirmer`.

La purge se **refuse** si les UUID attendus ne correspondent plus aux éléments seedés, si l'événement a enregistré des mouvements, des contrôles ou des alertes, si un compte nominatif fictif a été renommé, ou si aucun véritable administrateur 2FA n'est accessible. Si la commande se refuse, arrêter : ne pas faire de `DELETE` SQL général pour contourner cette protection.

## 3. Nettoyage ciblé

Après une simulation conforme, une sauvegarde vérifiée et la validation du compte administrateur réel :

```sh
docker compose -f docker-compose.yml -f deploy/demo/docker-compose.demo.yml \
  exec -T php php artisan demo:purger --confirmer
```

L'opération est **transactionnelle** : une contrainte relationnelle ou une incohérence empêche une suppression partielle. Le journal d'audit existant est conservé, puis complété d'un événement de purge. Les sessions des comptes fictifs supprimés sont invalidées.

## 4. Vérifications

- La liste des événements ne contient plus « Exercice fictif IKOMA — démonstration ».
- Les 300 visiteurs, 400 badges et cinq comptes du seed ne sont plus présents.
- Les événements et comptes authentiques restent inchangés.
- La landing et la connexion utilisent la marque **IKOMA ACCESS** et n'affichent plus le bandeau jaune.
- L'accès administrateur fonctionne avec la 2FA.
- Une nouvelle exécution de `demo:purger` en simulation affiche zéro élément identifié.

## Limites

La présence du code `DemoSeeder` et de fixtures dans les tests reste volontaire pour la recette automatisée, qui utilise une base isolée. Le nom interne historique `APP_ENV=demo` reste utilisé par le script du serveur de travail : **la suppression du bandeau ne transforme pas cet environnement en hébergement client de production**.

Si de l'activité a été enregistrée sur l'événement fictif ou si des comptes fictifs ont servi ailleurs, aucune purge automatique ne doit être tentée : les données doivent être revues pour conserver les preuves d'activité et éviter toute perte.
