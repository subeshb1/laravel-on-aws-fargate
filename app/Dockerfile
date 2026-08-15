# syntax=docker/dockerfile:1
#
# Production image for Laravel 13 on FrankenPHP (Octane worker mode).
#
# Three stages so the runtime image carries neither Composer nor Node:
#   vendor  -> PHP dependencies, production only
#   assets  -> Vite build output
#   runtime -> FrankenPHP + the application

# ---------------------------------------------------------------- 1. vendor --
FROM composer:2 AS vendor
WORKDIR /app

# Copy the manifests first so this layer is cached until dependencies change.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------- 2. assets --
FROM node:22-alpine AS assets
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# --------------------------------------------------------------- 3. runtime --
FROM dunglas/frankenphp:1-php8.4-alpine AS runtime

# pdo_pgsql for RDS, pcntl because Octane needs it, opcache because the whole
# point of worker mode is to keep compiled code in memory.
RUN install-php-extensions \
        pdo_pgsql \
        pcntl \
        opcache \
        intl \
        zip \
        bcmath \
    && apk add --no-cache postgresql17-client

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# FrankenPHP runs as a normal user. Caddy wants somewhere to write its data, and
# a read-only $HOME will fail the very first request, so point XDG at /tmp.
ENV XDG_CONFIG_HOME=/tmp \
    XDG_DATA_HOME=/tmp \
    OCTANE_SERVER=frankenphp \
    OCTANE_WORKERS=2 \
    MAX_REQUESTS=1000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

RUN chmod +x /usr/local/bin/entrypoint \
    && addgroup -g 10001 -S app \
    && adduser -u 10001 -S -G app app \
    && chown -R app:app storage bootstrap/cache \
    && rm -rf .env .env.example

USER 10001:10001

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
