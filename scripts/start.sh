#!/usr/bin/env sh
# Inicia web (FrankenPHP) ou worker (fila + agendador) conforme PROCESS_ROLE.
set -e
cd /app
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize || true

if [ "${PROCESS_ROLE:-web}" = "worker" ]; then
  echo "[cifut] worker: queue + scheduler (supervisionados no container)"
  # Cada processo roda em laço: se sair (max-time, erro, OOM do PHP), volta em 5 s.
  # Antes, queue:work com --max-time era o processo principal: ao completar 1 h o container
  # encerrava com código 0 e o Railway não reiniciava (worker parado 01:52–06:15 em 12/10/2026).
  set +e  # daqui em diante uma saída com erro não pode derrubar o laço
  ( while true; do php artisan schedule:work; echo "[cifut] scheduler saiu (código $?); reiniciando em 5 s"; sleep 5; done ) &
  while true; do
    php artisan queue:work --sleep=3 --tries=3 --max-time=3600
    echo "[cifut] queue:work saiu (código $?); reiniciando em 5 s"
    sleep 5
  done
fi

echo "[remo-scout] web on :${PORT:-8080}"
exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
