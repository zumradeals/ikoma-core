#!/bin/sh
# Processus ponctuel séparé de PHP-FPM, aucune commande fournie par la requête web.
set -eu
manifest=$(find /maintenance-backups -maxdepth 1 -name 'ikoma-*.sha256' -type f | sort | tail -n 1)
[ -n "$manifest" ] || { echo 'Preuve de sauvegarde absente.' >&2; exit 1; }
DB_USERNAME=ikoma
DB_PASSWORD=$(cat /run/secrets/db_owner)
export DB_USERNAME DB_PASSWORD
exec php artisan maintenance:traiter --sauvegarde="$manifest"
