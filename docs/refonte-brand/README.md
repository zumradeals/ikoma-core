# IKOMA ACCESS — nouvelle identité publique et connexion

## Périmètre

- Renommage **commercial et visible** : IKOMA ACCESS.
- Slogan approuvé : **Contrôlez les accès. Maîtrisez les visites.**
- Le dépôt technique `ikoma-core`, les routes, permissions, données, journaux et parcours métier ne sont pas renommés.
- Pages concernées : landing `/`, connexion `/login`, challenge 2FA `/two-factor-challenge`, titre et manifeste PWA.
- La page commerciale est publique. Aucun compte d’essai, tarif, client, chiffre de performance ou fonctionnalité non établie n’y est inventé.
- Les informations de présentation commerciale sont renvoyées à IKOMA GROUP par email. La création et la récupération des comptes restent du ressort de l’organisation.

## Système responsive

La landing et l’écran de connexion reprennent **la largeur du dashboard administrateur : 100 % de l’espace disponible avec un conteneur plafonné à 1 800 px** (et non l’ancien `max-w-5xl` de 1 024 px).

- Desktop : hero deux colonnes + couverture commerciale complète, formulaire de connexion sur une seconde colonne.
- Tablette : contenu plein écran et cartes redistribuées selon la place.
- Téléphone : colonnes empilées, boutons accessibles et aucun débordement horizontal.
- Captures E2E pour 1440×900, 1024×768, 768×1024 et 390×844.

## Identité graphique

- Bleu marine : `#0B3C6B`.
- Bleu : `#0066B3`.
- Orange : `#F75C03`.
- Fonds : `#F4F7FB` et blanc.
- Assets `public/brand/ikoma-access-{icon,logo,logo-slogan}.svg`, et favicon / icône PWA `public/icon.svg`.
- Les SVG web sont des déclinaisons vectorielles du concept de marque. Conserver les originaux graphiques validés séparément pour les futurs exports imprimés.

## Invariants

- Login : `POST /login`, mêmes champs nommés `email` et `password`, mêmes retours d’erreur, mot de passe effacé après envoi.
- Deuxième facteur : `POST /two-factor-challenge`, codes TOTP et codes de secours préservés, même nécessité de validation.
- Aucune modification des contrôles d’accès ou des limites de sécurité.
- Le bandeau `APP_ENV=demo` reste inchangé.
- Les trois espaces authentifiés restent fonctionnellement indépendants : seul leur nom commercial public change.
- PWA : images publiques mises en pré-cache et copiées dans les deux images Docker de production. Aucune donnée de session n’est mise en cache.

## Recette attendue

1. `make lint` et `make test-front`.
2. `make test`, `make e2e` (y compris connexion + challenge).
3. Installation de production, rendu des assets via Nginx, captures desktop/tablette/mobile.
4. Scanner Trivy inchangé : risque système résiduel à apprécier séparément.
