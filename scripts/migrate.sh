#!/bin/sh
set -eu
composer install --no-interaction --prefer-dist
php scripts/provision-db.php
DB_USERNAME=ikoma DB_PASSWORD="$(cat .secrets/db-owner)" php artisan migrate --force
php scripts/grant-db.php
chmod -R a+rwX storage bootstrap/cache
