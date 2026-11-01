# S0.3 : PHP Alpine candidate, PostgreSQL remains on its current base.
# The runtime www-data user retains access to local .env group 33 without widening its permissions.
FROM php:8.5.12-fpm-alpine3.24
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache libpq oniguruma libxml2 libzip libjpeg-turbo libpng unzip git; \
    apk add --no-cache --virtual .ikoma-compile $PHPIZE_DEPS postgresql-dev oniguruma-dev libxml2-dev libzip-dev libjpeg-turbo-dev libpng-dev; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install pdo_pgsql mbstring zip xmlreader gd; \
    apk del .ikoma-compile; \
    env_group="$(awk -F: '$3 == 33 {print $1; exit}' /etc/group)"; \
    if [ -z "$env_group" ]; then addgroup -S -g 33 ikoma-fpm-files; env_group=ikoma-fpm-files; fi; \
    addgroup www-data "$env_group"; \
    id -G www-data | grep -Eq '(^| )33( |$)'
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
