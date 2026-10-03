# syntax=docker/dockerfile:1.7
FROM php:8.4-fpm-alpine AS base

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache bash git curl icu-libs libzip libpng libjpeg-turbo freetype libwebp \
        imagemagick mysql-client fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev \
        libjpeg-turbo-dev freetype-dev libwebp-dev imagemagick-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl opcache pcntl pdo_mysql zip \
    && pecl install redis imagick \
    && docker-php-ext-enable redis imagick \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Run as the host user so files created in the container stay editable on the host.
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app \
    && sed -i 's/^user = www-data/user = app/; s/^group = www-data/group = app/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html
USER app


FROM base AS dev
USER root
RUN apk add --no-cache --virtual .xdebug-deps $PHPIZE_DEPS linux-headers \
    && pecl install xdebug && docker-php-ext-enable xdebug && apk del .xdebug-deps \
    && printf "xdebug.mode=off\nxdebug.client_host=host.docker.internal\n" > /usr/local/etc/php/conf.d/xdebug.ini
USER app

FROM base AS prod
COPY --chown=app:app . /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && php artisan storage:link || true
