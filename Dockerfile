# syntax=docker/dockerfile:1
#
# Production image for the Firefly III Data Importer.
#
# Multi-stage build with two final targets:
#   * web  -> nginx + php-fpm serving the web UI (default, port 8080)
#   * cli  -> one-shot CLI auto-importer (`php artisan importer:auto-import`)
#
# Both targets share the `frontend` (Vite asset build) and `base` (PHP runtime
# + composer dependencies) stages. Built and published by
# .github/workflows/docker-publish.yml.

# ---------------------------------------------------------------------------
# Stage 1: build front-end assets with Vite (kept out of the runtime image).
# ---------------------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /app

# Copy the whole source tree (node_modules/vendor are excluded via .dockerignore)
# so the Laravel Vite plugin can resolve inputs and emit into ./public/build.
COPY . .

# Mirror the known-good dev build: install root workspace deps, then build the
# `resources/js/v2` workspace. Output lands in ./public/build (+ manifest).
RUN npm install \
    && cd resources/js/v2 \
    && npm install \
    && npm run build

# ---------------------------------------------------------------------------
# Stage 2: PHP runtime + application code + composer dependencies (shared base).
# ---------------------------------------------------------------------------
FROM php:8.5-fpm-alpine AS base

# Runtime libraries the compiled PHP extensions link against, plus tooling
# composer needs. nginx/supervisor are installed only in the `web` target.
RUN apk add --no-cache \
        bash \
        curl \
        git \
        unzip \
        libstdc++ \
        icu-libs \
        oniguruma \
        libxml2 \
    # Build deps are added and removed in a single layer so they don't bloat
    # the final image. PHP 8.5 ships OPcache as a built-in (do not install it).
    && apk add --no-cache --virtual .build-deps \
        autoconf \
        g++ \
        make \
        linux-headers \
        icu-dev \
        oniguruma-dev \
        libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl xml mbstring \
    && apk del .build-deps

# Composer binary from the official image.
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Application source (excludes vendor/, node_modules/, .env via .dockerignore).
COPY . .

# Built front-end assets from the frontend stage.
COPY --from=frontend /app/public/build ./public/build

# Production composer install (no dev deps, optimized autoloader).
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-progress

# Writable Laravel directories.
RUN mkdir -p \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Build metadata (passed by CI), surfaced as OCI labels in both final targets.
ARG version=develop
ARG isodate
ARG gitrevision

# ---------------------------------------------------------------------------
# Stage 3a: web image (nginx + php-fpm via supervisord).
# ---------------------------------------------------------------------------
FROM base AS web

RUN apk add --no-cache nginx supervisor \
    && mkdir -p /var/log/supervisor /run/php /run/nginx

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

ARG version
ARG isodate
ARG gitrevision
LABEL org.opencontainers.image.title="Firefly III Data Importer (web)" \
      org.opencontainers.image.source="https://github.com/firefly-iii/data-importer" \
      org.opencontainers.image.version="${version}" \
      org.opencontainers.image.revision="${gitrevision}" \
      org.opencontainers.image.created="${isodate}"

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

# ---------------------------------------------------------------------------
# Stage 3b: cli image (one-shot auto-importer).
# ---------------------------------------------------------------------------
FROM base AS cli

ARG version
ARG isodate
ARG gitrevision
LABEL org.opencontainers.image.title="Firefly III Data Importer (cli)" \
      org.opencontainers.image.source="https://github.com/firefly-iii/data-importer" \
      org.opencontainers.image.version="${version}" \
      org.opencontainers.image.revision="${gitrevision}" \
      org.opencontainers.image.created="${isodate}"

# Mount your import configs/files at /import and set IMPORT_DIR_ALLOWLIST=/import.
# Override the directory by passing a different argument to the command.
CMD ["php", "artisan", "importer:auto-import", "/import"]
