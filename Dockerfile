# syntax=docker/dockerfile:1
#
# One image, three roles. docker-compose.yml runs it as the web server, the
# queue worker and the scheduler; only the command differs.

# ── 1. Frontend assets ───────────────────────────────────────────────
FROM node:20-bookworm-slim AS assets
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Tailwind scans Blade views and config/gatepass.php for class names, so the
# whole app (minus .dockerignore) is needed, not just resources/js.
COPY . .
RUN npm run build

# ── 2. PHP dependencies ──────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./
# Extensions are installed in the runtime stage, not this one, hence
# --ignore-platform-reqs. Scripts run later, once the app code is present.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ── 3. Runtime ───────────────────────────────────────────────────────
FROM php:8.4-apache-bookworm

# gd for dompdf and for visitor-pass QR images (App\Services\QrCodePng);
# pdo_mysql and pdo_pgsql for the two supported production databases.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_mysql pdo_pgsql zip

# Command-line optimizers behind App\Services\Media\ImageService::optimize()
# and Media Library conversions; any that are missing are silently skipped.
RUN apt-get update \
    && apt-get install -y --no-install-recommends jpegoptim optipng pngquant gifsicle webp libavif-bin \
    && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-community-hub.ini"

# Apache serves public/ on 8080 so the container can run as www-data rather
# than root; ports below 1024 would need root to bind.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!Listen 80$!Listen 8080!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:8080>!' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n</Directory>\nServerTokens Prod\nServerSignature Off\n' \
        > /etc/apache2/conf-enabled/community-hub.conf

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

# package:discover writes bootstrap/cache/packages.php. It needs no
# environment, unlike config:cache, which runs at start-up in the entrypoint.
# .dockerignore strips storage/ of local files, so recreate the directories
# Laravel expects before anything writes to them.
RUN mkdir -p storage/app/public storage/app/private storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/community-hub-entrypoint

USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'

ENTRYPOINT ["community-hub-entrypoint"]
CMD ["apache2-foreground"]
