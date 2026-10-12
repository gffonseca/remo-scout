#!/usr/bin/env sh
# Inicia web (FrankenPHP) ou worker (fila + agendador) conforme PROCESS_ROLE.
set -e
cd /app
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize || true

if [ "${PROCESS_ROLE:-web}" = "worker" ]; then
  echo "[remo-scout] worker: queue + scheduler"
  php artisan schedule:work &
  exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600
fi

echo "[remo-scout] web on :${PORT:-8080}"
exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
