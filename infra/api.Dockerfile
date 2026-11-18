FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip libpq-dev icu-dev libzip-dev linux-headers $PHPIZE_DEPS \
        tesseract-ocr tesseract-ocr-data-eng poppler-utils \
 && docker-php-ext-install pdo_pgsql pcntl intl zip bcmath \
 && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/api

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev && php artisan package:discover --ansi \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache

EXPOSE 8030 8031
