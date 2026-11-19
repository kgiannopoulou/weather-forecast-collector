# --- Stage 1: install PHP dependencies (no dev packages) ---------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- Stage 2: runtime ----------------------------------------------------------
FROM php:8.4-cli-alpine

RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

WORKDIR /app
COPY --from=vendor /app /app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

# sed strips CRLF in case the repo was checked out on Windows.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint \
    && php artisan package:discover --ansi \
    && addgroup -S app && adduser -S app -G app \
    && chown -R app:app storage bootstrap/cache

USER app
EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
