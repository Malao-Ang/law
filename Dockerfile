# syntax=docker/dockerfile:1

# ===========================================================
# Stage 1: PHP dependencies builder
# ===========================================================
FROM php:8.4-fpm AS php-builder

ENV DEBIAN_FRONTEND=noninteractive

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev unzip libxml2-dev libpng-dev \
        libjpeg-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install zip xml pdo pdo_mysql mbstring gd pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first (layer cache: only re-run when composer.json changes)
COPY src/composer.json src/composer.lock ./

RUN --mount=type=cache,target=/root/.composer \
    composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --prefer-dist

# ===========================================================
# Stage 2: Node / Frontend builder
# ===========================================================
FROM node:20-slim AS node-builder

WORKDIR /app

COPY src/package.json src/package-lock.json ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci --prefer-offline

COPY src/ .

RUN npm run build

# ===========================================================
# Stage 3: Final production runtime (slim image)
# ===========================================================
FROM php:8.4-fpm AS runtime

ENV DEBIAN_FRONTEND=noninteractive

# Only runtime system libs (no build tools)
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        libzip1 libxml2 libpng16-16 libjpeg62-turbo libfreetype6 libonig5 \
        poppler-utils \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install zip xml pdo pdo_mysql mbstring gd pcntl

WORKDIR /var/www/html

# Copy app source
COPY src/ .

# Copy pre-built vendor and public/build from builders
COPY --from=php-builder /var/www/html/vendor ./vendor
COPY --from=node-builder /app/public/build ./public/build

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
