# Production image for the API, Horizon, the scheduler and Reverb (same image, different command).
# FrankenPHP serves Laravel's public/ directly (no separate nginx/php-fpm).
#   docker build -f deploy/docker/api.Dockerfile -t extractiq-api ./api
FROM dunglas/frankenphp:1-php8.3-bookworm

RUN install-php-extensions pdo_pgsql pcntl intl zip bcmath opcache \
 && apt-get update && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng tesseract-ocr-deu \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf "upload_max_filesize=25M\npost_max_size=110M\nmemory_limit=512M\nexpose_php=Off\n" > "$PHP_INI_DIR/conf.d/extractiq.ini"

USER www-data
EXPOSE 8030
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS http://127.0.0.1:8030/api/health || exit 1
# Caches are built at start so they see the runtime .env.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan event:cache && frankenphp php-server --root public/ --listen :8030"]
