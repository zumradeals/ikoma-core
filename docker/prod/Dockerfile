# Images de production d'IKOMA Accueil (lot L12) : aucun code monté, aucun outil de développement, utilisateurs sans privilèges.
# Construction : sh deploy/prod/build.sh <version>   (jamais « latest » : chaque image porte la version qui l'a produite)
# syntax=docker/dockerfile:1

# 1) Interface : construite ici, puis copiée dans l'image Nginx et dans l'image PHP (service worker et manifeste inclus).
FROM node:24-bookworm AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.ts tsconfig.json ./
# vite.config.ts calcule l'empreinte de la version à partir de ces dossiers.
COPY resources ./resources
COPY routes ./routes
COPY app ./app
COPY public ./public
RUN npm run build

# 2) Base PHP : mêmes extensions que le développement ; compilateurs et en-têtes retirés après compilation.
FROM php:8.5.12-fpm-trixie AS base
RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y --no-install-recommends; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS libpq-dev libonig-dev libxml2-dev libzip-dev libjpeg62-turbo-dev libpng-dev unzip git; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install pdo_pgsql mbstring zip xmlreader gd; \
    ldd /usr/local/lib/php/extensions/*/*.so | awk '/=>/ { print $(NF-1) }' | xargs -r realpath | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS libpq-dev libonig-dev libxml2-dev libzip-dev libjpeg62-turbo-dev libpng-dev unzip git; \
    rm -rf /var/lib/apt/lists/*

# 3) Dépendances PHP de production seulement (sans les outils de test).
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts --no-interaction

# 4) Image PHP finale : le code appartient à root et n'est pas modifiable par l'application ; seuls storage/ et bootstrap/cache/ le sont.
FROM base AS php
RUN useradd --uid 10001 --user-group --no-create-home --shell /usr/sbin/nologin ikoma
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /app/app ./app
COPY --from=vendor /app/bootstrap ./bootstrap
COPY --from=vendor /app/config ./config
COPY --from=vendor /app/database ./database
COPY --from=vendor /app/routes ./routes
COPY lang ./lang
COPY resources/views ./resources/views
COPY artisan composer.json ./
COPY --from=assets /app/public/build ./public/build
COPY public/index.php public/robots.txt public/favicon.ico public/icon.svg ./public/
COPY public/brand ./public/brand
COPY public/badges ./public/badges
COPY scripts/provision-db.php scripts/grant-db.php ./scripts/
COPY docker/prod/migrate.sh ./scripts/migrate-prod.sh
COPY docker/prod/entrypoint.sh /usr/local/bin/ikoma-entrypoint
COPY docker/prod/php.ini /usr/local/etc/php/conf.d/zz-ikoma.ini
COPY docker/prod/php-fpm.conf /usr/local/etc/php-fpm.d/zz-ikoma.conf
RUN set -eux; \
    rm -f /usr/local/etc/php-fpm.d/docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf; \
    mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    chown -R ikoma:ikoma storage bootstrap/cache; \
    chmod 0755 /usr/local/bin/ikoma-entrypoint
USER ikoma
ENTRYPOINT ["ikoma-entrypoint"]
CMD ["php-fpm"]

# 5) Image Nginx : seule porte d'entrée ; sert l'interface construite et transmet le reste à PHP-FPM. Sans privilèges (ports 8080 et 8443).
FROM nginxinc/nginx-unprivileged:stable-alpine-slim AS nginx
COPY --from=assets /app/public/build /var/www/html/public/build
COPY public/favicon.ico public/icon.svg public/robots.txt /var/www/html/public/
COPY public/brand /var/www/html/public/brand
COPY public/badges /var/www/html/public/badges
COPY docker/security-common-headers.conf /etc/nginx/ikoma-headers.conf
