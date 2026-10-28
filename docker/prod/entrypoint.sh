#!/bin/sh
# Point d'entrée des conteneurs PHP de production : lit les secrets montés par Docker (jamais dans l'image ni dans le dépôt), puis lance la commande.
set -eu
secret() { if [ -r "/run/secrets/$1" ]; then tr -d '\r\n' < "/run/secrets/$1"; fi; }
APP_KEY="$(secret app_key)"
DB_PASSWORD="$(secret db_app)"
IKOMA_DB_APP_PASSWORD="$DB_PASSWORD"
export APP_KEY DB_PASSWORD IKOMA_DB_APP_PASSWORD
if [ -r /run/secrets/db_owner ]; then export DB_OWNER_PASSWORD_FILE=/run/secrets/db_owner; fi
if [ -r /run/secrets/install_token ]; then export IKOMA_INSTALL_TOKEN_FILE=/run/secrets/install_token; fi
export APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=warning SESSION_SECURE_COOKIE=true
export DB_CONNECTION=pgsql DB_HOST="${DB_HOST:-db}" DB_PORT=5432 DB_DATABASE="${DB_DATABASE:-ikoma}" DB_USERNAME="${DB_USERNAME:-ikoma_app}"
exec "$@"
