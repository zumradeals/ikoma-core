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
