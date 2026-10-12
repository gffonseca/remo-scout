#!/usr/bin/env sh
# Executado só no serviço web antes de cada deploy: migrações + seeds idempotentes (catálogo, clubes, admin, carga inicial).
set -e
cd /app
php artisan migrate --force
php artisan db:seed --force
