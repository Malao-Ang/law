FROM node:20-bookworm-slim AS frontend

WORKDIR /var/www/html

# Reproducible install from the committed lock file.
COPY package.json package-lock.json ./

RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json ./

RUN npm run build

FROM php:8.4-cli

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    curl \
    libreoffice-core \
    libreoffice-writer \
    fontconfig \
    fonts-thai-tlwg \
    imagemagick \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mbstring zip pcntl \
    && pecl install redis \
    && pecl install mongodb \
    && docker-php-ext-enable redis mongodb \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application code first
COPY . .

COPY --from=frontend /var/www/html/public/build ./public/build

COPY docker/fonts/ /usr/share/fonts/truetype/th-sarabun-psk/
COPY docker/fontconfig/local.conf /etc/fonts/local.conf
RUN fc-cache -f

# Install production dependencies only (no phpunit/dev tooling in the image).
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host", "0.0.0.0", "--port", "8000"]
