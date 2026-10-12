#!/usr/bin/env sh
# Executado só no serviço web antes de cada deploy: backup + migrações + seeds idempotentes + teste de fontes (não bloqueante).
set -e
cd /app
# Backup antes de migrar. Se falhar, o deploy é abortado e a versão atual continua no ar.
php artisan scout:backup-db --tag=predeploy
php artisan migrate --force
php artisan db:seed --force
php artisan scout:probe-sources || echo "[predeploy] teste de fontes falhou (não bloqueante)"
php artisan scout:sync-crests || true
