# REMO SCOUT — imagem única para web e worker (PROCESS_ROLE decide o processo).
FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions pdo_pgsql pgsql intl zip gd bcmath opcache pcntl \
 && apt-get update && apt-get install -y --no-install-recommends poppler-utils unzip git \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && php artisan filament:assets \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chmod +x scripts/*.sh

ENV PORT=8080
EXPOSE 8080
CMD ["scripts/start.sh"]
