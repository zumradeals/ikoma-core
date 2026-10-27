#!/bin/sh
set -eu
composer install --no-interaction --prefer-dist
php scripts/provision-db.php
DB_USERNAME=ikoma DB_PASSWORD="$(cat .secrets/db-owner)" php artisan migrate --force
php scripts/grant-db.php
find storage bootstrap/cache -path storage/app/private/imports -prune -o -exec chmod a+rwX {} +
