#!/usr/bin/env sh
# Executado só no serviço web antes de cada deploy: migrações + seeds idempotentes + teste de fontes (não bloqueante).
set -e
cd /app
php artisan migrate --force
php artisan db:seed --force
php artisan scout:probe-sources || echo "[predeploy] teste de fontes falhou (não bloqueante)"
