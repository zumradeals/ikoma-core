FROM php:8.5.12-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libxml2-dev libzip-dev libjpeg62-turbo-dev libpng-dev unzip git \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_pgsql mbstring zip xmlreader gd \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
