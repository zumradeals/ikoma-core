# EXPERIMENTATION UNIQUEMENT : NE PAS UTILISER EN PRODUCTION
# Même branche PHP 8.5, distribution alternative Alpine 3.24.
# Aucun code IKOMA, aucune clé, aucune donnée ou configuration de service.
FROM php:8.5.12-fpm-alpine3.24
RUN set -eux; \
    apk add --no-cache libpq oniguruma libxml2 libzip libjpeg-turbo libpng; \
    apk add --no-cache --virtual .ikoma-build-deps $PHPIZE_DEPS postgresql-dev oniguruma-dev libxml2-dev libzip-dev libjpeg-turbo-dev libpng-dev; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install pdo_pgsql mbstring zip xmlreader gd; \
    apk del .ikoma-build-deps
