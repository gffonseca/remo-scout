# REMO SCOUT — piloto

Sistema interno de prospecção do Departamento de Análise de Mercado (futebol profissional) do Clube do Remo.
Uso interno — piloto. Especificação: `docs/PROMPT-v2.md`. Estado: `CHECKPOINT.md`.

## Stack
Laravel 13 · Filament 5 (Livewire 4) · PostgreSQL 16+ · FrankenPHP (Docker) · Railway (web, worker, Postgres, bucket S3).

## Rodar localmente
```bash
composer install
cp .env.example .env && php artisan key:generate   # configure DB_* para um PostgreSQL local
php artisan migrate --seed                          # catálogo, clubes 2026, carga inicial; admin via ADMIN_EMAIL/ADMIN_INITIAL_PASSWORD
php artisan db:seed --class=DemoSeeder              # opcional: dados SINTÉTICOS (usuários *@demo.local)
php artisan serve
php artisan test                                    # usa o banco remo_scout_test (phpunit.xml)
```

## Deploy (Railway)
- Imagem única (`Dockerfile`); `PROCESS_ROLE=web|worker` escolhe o processo (`scripts/start.sh`).
- Serviço `web`: pre-deploy `sh scripts/predeploy.sh` (migrações + seeds idempotentes), healthcheck `/up`.
- Serviço `worker`: fila em banco + `schedule:work` (única estratégia de agendamento; não usar cron do Railway em paralelo).
- Segredos só em variáveis do Railway. Nunca commitar `.env`.

## Princípios
Dado ausente ≠ zero · "não avaliado" ≠ "padrão" · origem e data em toda informação · avaliação humana separada do dado ·
nenhuma inferência psicológica/médica · CPF nunca é gravado · exportações respeitam permissões e ficam auditadas.
