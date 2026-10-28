FROM php:8.5.12-fpm-trixie
RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y --no-install-recommends; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS libpq-dev libonig-dev libxml2-dev libzip-dev libjpeg62-turbo-dev libpng-dev unzip git; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install pdo_pgsql mbstring zip xmlreader gd; \
    ldd /usr/local/lib/php/extensions/*/*.so | awk '/=>/ { print $(NF-1) }' | xargs -r realpath | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS libpq-dev libonig-dev libxml2-dev libzip-dev libjpeg62-turbo-dev libpng-dev; \
    rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
