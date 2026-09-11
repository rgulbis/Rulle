FROM php:8.5-cli-bookworm
WORKDIR /var/www/html

# Vite bakes VITE_-prefixed vars into the compiled JS at build time, but
# .env is excluded from the build context (see .dockerignore) so real
# secrets never end up baked into image layers. This build arg passes
# through just the one cosmetic value the frontend actually needs.
ARG VITE_APP_NAME=Laravel
ENV VITE_APP_NAME=$VITE_APP_NAME

ARG VITE_REVERB_APP_KEY
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY
ARG VITE_REVERB_PORT=8080
ENV VITE_REVERB_PORT=$VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME=http
ENV VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libsqlite3-dev libzip-dev unzip git curl ca-certificates gnupg \
    && docker-php-ext-install intl pdo_sqlite bcmath pcntl zip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R u+rwX storage bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && npm run build \
    && rm -rf node_modules

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/entrypoint.sh"]
