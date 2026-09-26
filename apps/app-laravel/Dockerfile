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

# PHP 8.4's bundled pecl is unusable (its ancient PEAR HTTP client returns an
# empty body from pecl.php.net), so redis/mongodb are built from source via
# phpize instead of `pecl install`. $PHPIZE_DEPS pulls autoconf/gcc/make.
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
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd mbstring zip pcntl \
    && for ext in redis mongodb; do \
         curl -fsSL "https://pecl.php.net/get/$ext" -o "/tmp/$ext.tgz" \
         && mkdir -p "/tmp/$ext" \
         && tar -xzf "/tmp/$ext.tgz" -C "/tmp/$ext" --strip-components=1 \
         && (cd "/tmp/$ext" && phpize && ./configure && make -j"$(nproc)" && make install); \
       done \
    && docker-php-ext-enable redis mongodb \
    && rm -rf /tmp/redis* /tmp/mongodb* /var/lib/apt/lists/*

RUN printf "upload_max_filesize=100M\npost_max_size=100M\nmemory_limit=512M\n" \
    > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application code first
COPY . .
RUN rm -f public/hot \
    && mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs

COPY --from=frontend /var/www/html/public/build ./public/build

COPY docker/fonts/ /usr/share/fonts/truetype/th-sarabun-psk/
COPY docker/fontconfig/local.conf /etc/fonts/local.conf
RUN fc-cache -f

# Install production dependencies only (no phpunit/dev tooling in the image).
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

EXPOSE 8000

# Built-in server is single-process unless this is set (Unix/Docker only).
# Needed so e-sign can POST /api/esign/callback while SendDocumentSign waits on Kong.
ENV PHP_CLI_SERVER_WORKERS=8

CMD ["php", "artisan", "serve", "--host", "0.0.0.0", "--port", "8000"]
