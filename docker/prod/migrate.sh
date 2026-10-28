#!/bin/sh
# Migrations de production : créé le compte applicatif, applique les migrations avec le compte propriétaire, puis accorde les droits minimaux.
set -eu
php scripts/provision-db.php
DB_USERNAME=ikoma DB_PASSWORD="$(tr -d '\r\n' < /run/secrets/db_owner)" php artisan migrate --force
php scripts/grant-db.php
