# syntax=docker/dockerfile:1.7

############################################
# Stage 1 — Build frontend assets with Node
############################################
FROM node:20-alpine AS assets
WORKDIR /app

RUN corepack enable && corepack prepare pnpm@9.15.0 --activate

COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public

RUN pnpm run build

############################################
# Stage 2 — PHP runtime (FrankenPHP + Octane)
############################################
FROM serversideup/php:8.5-frankenphp

# Match the runtime Octane server to the base image so config/octane.php
# resolves correctly without a per-environment override.
ENV OCTANE_SERVER=frankenphp

USER root

RUN install-php-extensions intl sockets gd exif

USER www-data

COPY --chown=www-data:www-data . /var/www/html

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

