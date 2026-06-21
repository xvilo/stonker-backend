# syntax=docker/dockerfile:1
# Backend image: Symfony API served by FrankenPHP (single process, HTTP + PHP).
# The same image runs the web server, the Messenger worker and the migration job.
FROM dunglas/frankenphp:1-php8.4 AS base

ENV APP_ENV=prod \
    APP_DEBUG=0

WORKDIR /app

# PHP extensions required by the app (Postgres, intl, opcache, sodium for
# credential encryption, apcu for metadata caches).
RUN install-php-extensions pdo_pgsql intl opcache zip sodium apcu

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install dependencies first (cached unless composer files change).
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --no-progress

# Application code.
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p var/cache var/log \
    # Warm the prod cache with a throwaway secret (real values come from env at runtime).
    && APP_SECRET=build-time php bin/console cache:warmup --no-optional-warmers || true \
    && chmod -R 777 var

COPY docker/Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 8080
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
