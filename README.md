# IKOMA Accueil

Système de vérification rapide des visiteurs pour site sensible : liste nominative, badges QR uniques, poste d'accueil sur tablette (utilisable sans internet), application mobile de pilotage.

**Nom de travail, projet en cours de conception.** Conçu et réalisé par IKOMA GROUP.

## Où trouver quoi
- `docs/PLAN_CONCEPTION.md` : le plan de conception complet
- `AGENTS.md` : règles imposées à l'exécutant
- `docs/prompts/` : prompts d'exécution, lot par lot
- `docs/JOURNAL_DECISIONS.md` : décisions validées et en attente
- `docs/prototype/index.html` : prototype de démonstration (ouvrir dans un navigateur ; données fictives)

Les instructions de lancement du projet seront ajoutées au lot L0.


## Lancer le socle L0

Prérequis : Git, Docker avec Docker Compose v2 et Make (sur Windows, utiliser WSL). Docker doit être démarré. Aucune installation locale de PHP ou Node n'est nécessaire.

Dans un terminal, exécuter ces trois commandes :

```sh
git clone https://github.com/zumradeals/ikoma-core.git && cd ikoma-core
make install
make up
```

Ouvrir ensuite http://localhost:8080. Le premier lancement télécharge les dépendances et peut prendre plusieurs minutes. `make down` arrête le site et conserve la base. `make up` le relance. `make install` génère une clé et un mot de passe locaux seulement si `.env` n'existe pas ; ne jamais publier ce fichier. Ce paquet est destiné au développement, pas à une mise en production.

## Vérifier le projet

Après le lancement :

```sh
make test        # Tests PHP ; base séparée ikoma_test
make test-front  # Tests des composants Vue
make e2e         # Chromium : première visite puis réouverture hors ligne
make lint        # Style PHP/JS, analyse PHP et TypeScript
```

`make migrate-fresh-seed` efface la base de développement et rejoue les migrations ; ne l'utiliser que pour repartir de zéro. Aucune table métier ni donnée de démonstration n'est créée au lot L0. Seule la table technique des migrations est présente.

Pour essayer le mode hors ligne : visiter la page une première fois, attendre l'activation du service worker puis couper le réseau et rouvrir l'adresse. Utiliser localhost ou HTTPS : les service workers ne fonctionnent pas sur une adresse HTTP distante. La connexion n'est pas nécessaire pour rouvrir la page provisoire ; aucun parcours métier n'est implémenté.

Les composants et règles visuelles sont décrits dans `docs/design-system.md`. Les versions exactes des bibliothèques sont figées dans `composer.lock` et `package-lock.json`.

### Versions et vérification des sources

Versions stables consultées le 27 octobre 2026 : PHP 8.5.12, Laravel 13.39.0, Inertia Laravel 3.5.2 / Vue 3.9.1, Vue 3.5.44, Tailwind 4.3.3, Vite 8.3.5, TypeScript 7.0.2, PostgreSQL 18.6 et Nginx stable 1.30.6. PHP local de vérification : 8.3 ; image de référence : 8.5.12.

Sources : [PHP](https://www.php.net/releases/), [Laravel](https://laravel.com/docs/releases), [Inertia](https://github.com/inertiajs/inertia-laravel/releases), [registre npm](https://www.npmjs.com/), [PostgreSQL](https://www.postgresql.org/support/versioning/), [Nginx](https://nginx.org/en/download.html). Les registres ont été interrogés avant installation, les fichiers lock fixent les versions résolues.

## Lot L1 : comptes et audit

Référence fonctionnelle L1/L2 : [document v1.0](docs/reference/Mode_de_fonctionnement_verification_visiteurs.pdf). La [matrice des droits](docs/MATRICE_DROITS.md) doit être validée par le dirigeant.

Les commandes de lancement restent identiques. `make install` prépare aussi un secret propriétaire dans `.secrets/` (jamais commité). L’application utilise `ikoma_app`, sans droits de modification ou suppression du journal ; les migrations utilisent un compte distinct. Conserver `.env` et `.secrets/` ensemble pour redémarrer la même base. Un ancien volume L0 utilise son mot de passe initial, conservé par le script d’installation.

Pour créer les cinq comptes fictifs, exécuter `docker compose exec php php artisan db:seed`. Chaque mot de passe aléatoire est affiché uniquement à la création du compte : ne pas enregistrer cette sortie dans un journal partagé. Un deuxième seed ne réinitialise pas les mots de passe. Aucun mot de passe n’est présent dans Git.

Ouvrir « Se connecter ». Les administrateurs et superviseurs doivent configurer puis confirmer leur application TOTP avant d’ouvrir leur espace. Conserver les codes de secours hors de l’application. Il n’y a pas d’inscription publique. L’administrateur crée les comptes, attribue un rôle, désactive et réinitialise le mot de passe depuis Comptes ; la nouvelle valeur est transmise par un canal sûr. Une modification de compte ferme ses sessions. La durée d’inactivité se règle en bas de Comptes.

Vérifier le journal : `docker compose exec php php artisan audit:verify`. Le chaînage signale une ligne modifiée ; il ne constitue pas une protection contre un administrateur PostgreSQL qui réécrirait toute la chaîne. La lecture du journal est aussi accessible au superviseur, sans droit de gestion des comptes. L’export est réservé à un futur lot.

Le correctif PWA calcule une empreinte des sources à chaque build. Le test e2e modifie puis reconstruit réellement la page provisoire, attend l’activation du nouveau service worker et vérifie sa réouverture hors ligne. Aucun écran authentifié n’est mis en cache.

## Lot L2 : modèle et démonstration

Après `make install` et `make up`, `make migrate-fresh-seed` reconstruit la base de développement et charge les comptes et données fictives. **Cette commande efface les données existantes.** Conserver les mots de passe affichés une seule fois pour les cinq comptes fictifs. Pour ajouter seulement le jeu manquant sans effacer les comptes existants : `docker compose exec php php artisan db:seed`.

Le compte administrateur, après activation TOTP, accède à Zones, Catégories et Badges depuis Mon espace. Zones et catégories permettent création/modification ; Badges reste en lecture seule, avec filtres statut, lot et réservation par poste. Aucun écran Événements ni parcours d’accueil, scan, import ou contrôle de groupe n’est disponible dans L2.

Le [schéma et les questions ouvertes](docs/schema.md) décrivent les tables, contraintes et limites. Le seed conserve le comportement des comptes L1 ; un second lancement ne duplique pas la démonstration. Les données sont entièrement fictives.

Vérifications : `make test`, `make test-front`, `make e2e`, `make lint`. La CI mesure aussi la reconstruction et le seed en moins de deux minutes ; elle conserve les captures des trois écrans. Le test Playwright ajoute une zone et une catégorie fictives dans la base de développement.
