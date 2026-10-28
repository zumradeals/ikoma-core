#!/bin/sh
# Migrations de production : créé le compte applicatif, applique les migrations avec le compte propriétaire, puis accorde les droits minimaux.
set -eu
php scripts/provision-db.php
if ! DB_USERNAME=ikoma DB_PASSWORD="$(tr -d '\r\n' < /run/secrets/db_owner)" php artisan migrate --force; then
  # Diagnostic : l'application masque volontairement les messages d'erreur ; on montre l'environnement du conteneur (jamais un secret).
  echo "Diagnostic : utilisateur $(id -u):$(id -g)" >&2
  ls -ld /tmp /var/www/html/storage /var/www/html/bootstrap/cache >&2 || true
  exit 1
fi
php scripts/grant-db.php
