#!/bin/sh
set -eu
case "${1:-}" in
  donnees:purger|audit:purger|comptes:bris-de-glace|maintenance:traiter) ;;
  *) echo 'Commande privilégiée non autorisée.' >&2; exit 1 ;;
esac
# Secret de maintenance existant, jamais transmis au processus PHP-FPM.
DB_USERNAME=ikoma
DB_PASSWORD=$(cat .secrets/db-owner)
export DB_USERNAME DB_PASSWORD
exec php artisan "$@"
