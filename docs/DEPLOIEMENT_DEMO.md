# Déploiement de la démonstration — https://ikoma.dgafrique.com

Statut : projet de travail de l'architecte, **non encore exécuté sur le serveur** (le script a été vérifié seulement pour sa syntaxe ; la première exécution réelle est à surveiller). Cadre : plan de conception section 10, environnement « Démonstration » (sous-domaine de test, **données fictives uniquement**).

> **Ce n'est pas la production.** Le paquet Docker actuel est celui du développement. N'y mettez jamais de vraie liste de visiteurs, de vraie pièce d'identité ni de vraie photo. La production se fera sur un serveur dédié et durci, avec les sauvegardes chiffrées et l'installation guidée du lot L12, après décision d'hébergement du client.

## Avant de commencer (une seule fois)

1. **DNS** : chez le gestionnaire du domaine dgafrique.com, créer un enregistrement **A** `ikoma` → adresse IPv4 publique du serveur Contabo. Attendre quelques minutes la propagation (`nslookup ikoma.dgafrique.com`).
2. **Serveur** : Contabo VPS sous Ubuntu 22.04/24.04 ou Debian 12, accès SSH root (ou sudo), au moins 2 Go de mémoire et 15 Go de disque libres. Ports **80 et 443** ouverts (pare-feu Contabo et `ufw` si actif) ; le port 22 reste ouvert.
3. **Autres sites déjà présents** : le script détecte nginx ou Apache et y ajoute un site `ikoma.dgafrique.com` sans toucher aux autres (la configuration est testée avant rechargement et retirée si elle est refusée). Si aucun serveur web n'est installé, il installe Caddy.
4. **Dépôt privé ?** Si `zumradeals/ikoma-core` est privé, préparer sur le serveur une clé de déploiement en lecture seule (GitHub → Settings → Deploy keys) puis lancer avec `REPO=git@github.com:zumradeals/ikoma-core.git`. Ne jamais coller un jeton personnel dans une commande conservée dans l'historique du serveur.

## La commande de déploiement

Connectez-vous au serveur (`ssh root@ADRESSE_IP`) puis exécutez :

```sh
curl -fsSL https://raw.githubusercontent.com/zumradeals/ikoma-core/main/deploy/demo/deploy.sh -o /root/deploy-ikoma.sh && sh /root/deploy-ikoma.sh
```

(Dépôt privé : `git clone git@github.com:zumradeals/ikoma-core.git /opt/ikoma && REPO=git@github.com:zumradeals/ikoma-core.git sh /opt/ikoma/deploy/demo/deploy.sh`.)

Le script, dans l'ordre : installe Git, Make et Docker s'ils manquent ; vérifie que `ikoma.dgafrique.com` pointe bien vers ce serveur ; récupère la branche `main` dans `/opt/ikoma` ; génère la clé de l'application et les mots de passe de base de données (fichiers `.env` et `.secrets/`, jamais dans Git) ; construit et démarre l'application (PostgreSQL, PHP, nginx local sur 127.0.0.1 (port 8080, ou le premier port libre suivant si 8080 est déjà pris ; il est mémorisé dans `IKOMA_PORT` du fichier `.env`), tâches planifiées) ; applique les migrations ; charge les comptes et données **fictifs** ; ajoute le site au serveur web existant (nginx ou Apache, ou installe Caddy s'il n'y en a aucun) et obtient un certificat HTTPS Let's Encrypt renouvelé automatiquement (variable facultative `EMAIL=vous@exemple.com` pour les avis d'expiration).

La première exécution dure plusieurs minutes (téléchargements). Les variables optionnelles : `DOMAIN`, `BRANCH` (par défaut `main`), `DIR` (par défaut `/opt/ikoma`), `REPO`.

## Après le déploiement

- Les mots de passe des cinq comptes fictifs (administrateur, superviseur, agent d'accueil, hôte, encadreur) sont dans `/root/ikoma-comptes-demo.txt`, affichés **une seule fois**. Notez-les dans un gestionnaire de mots de passe puis supprimez le fichier : `shred -u /root/ikoma-comptes-demo.txt`.
- Les comptes administrateur et superviseur doivent activer la double authentification à la première connexion ; conservez les codes de secours hors de l'application.
- Ouvrez https://ikoma.dgafrique.com. Contrôles : `curl -sI https://ikoma.dgafrique.com/up | head -1` (réponse 200) et `cd /opt/ikoma && docker compose ps` (services « running »/« healthy »).
- Poste d'accueil : connectez-vous avec le compte agent, **Mon espace → Poste d'accueil** (disponible quand le lot L5 est fusionné). Autorisez la caméra du navigateur.

## Mettre à jour

Relancer la même commande (ou `sh /opt/ikoma/deploy/demo/deploy.sh`) : le script récupère la dernière version de `main`, reconstruit, applique les nouvelles migrations et complète les données fictives sans modifier les comptes existants. Pour déployer une autre branche : `BRANCH=nom-de-branche sh /opt/ikoma/deploy/demo/deploy.sh`.

## Remettre la démonstration à zéro

`cd /opt/ikoma && make migrate-fresh-seed` **efface toutes les données** de la démonstration et recrée les comptes (nouveaux mots de passe affichés une fois). Arrêt : `docker compose down` (la base est conservée) ; redémarrage : relancer le script.

## Serveur web déjà présent

Cas d'un serveur web non reconnu par le script : garder l'application sur `127.0.0.1:8080` et ajouter un site dans le serveur existant. Exemple nginx (puis `certbot --nginx -d ikoma.dgafrique.com` pour le certificat) :

```nginx
server {
    server_name ikoma.dgafrique.com;
    client_max_body_size 6m;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

L'application doit recevoir `X-Forwarded-Proto: https` : elle y fait confiance (le conteneur n'écoute que sur 127.0.0.1) pour construire ses liens et cookies en HTTPS. Le script s'arrête alors à l'étape 7, après avoir démarré l'application : configurez le site ci-dessus.

## Sécurité de la démonstration

- Données fictives uniquement ; aucune inscription publique ; comptes nominatifs avec mots de passe aléatoires ; double authentification obligatoire pour l'administrateur et le superviseur.
- PostgreSQL n'est pas exposé hors du réseau Docker ; l'application n'écoute que sur 127.0.0.1:8080 ; seul le serveur web du serveur (nginx, Apache ou Caddy) est public.
- Limites connues du paquet de développement (traitées au lot L12) : images non figées par version de production, code monté depuis le dossier du serveur, `npm ci` à chaque démarrage, pas de sauvegarde automatique, journaux sans rotation, pas de politique de contenu stricte (lot L11).
- Si le site ne doit pas être visible de tous, ajouter un `basic_auth` ou une liste d'adresses autorisées dans le Caddyfile (`deploy/demo/Caddyfile`).
