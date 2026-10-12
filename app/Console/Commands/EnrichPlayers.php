<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\Player;
use App\Models\SyncRun;
use App\Services\PlayerEnrichment;
use App\Sources\SourceException;
use Illuminate\Console\Command;

class EnrichPlayers extends Command
{
    protected $signature = 'scout:enrich {provider : transfermarkt|sofascore} {--scope=priority : priority|shortlist|missing} {--limit=30} {--user=}';

    protected $description = 'Vincula e enriquece atletas por fonte externa (sob demanda, em lote pequeno)';

    public function handle(): int
    {
        $provider = $this->argument('provider');
        abort_unless(in_array($provider, ['transfermarkt', 'sofascore'], true), 1, 'Provedor inválido');
        $source = DataSource::byKey($provider);
        if (! \App\Support\SourceRunLock::run($provider, fn () => $this->enrich($provider, $source))) {
            $this->warn("{$provider}: já existe enriquecimento em andamento; esta execução foi ignorada.");
        }

        return self::SUCCESS;
    }

    private function enrich(string $provider, DataSource $source): void
    {
        SyncRun::closeStale($source->id, 40);
        $deadline = microtime(true) + 1200; // abaixo do lock por fonte e do timeout do job
        $run = SyncRun::create(['data_source_id' => $source->id, 'job' => 'enriquecimento', 'status' => 'em_execucao', 'started_at' => now(),
            'scope' => ['scope' => $this->option('scope'), 'limit' => (int) $this->option('limit')], 'requested_by' => $this->option('user')]);

        $q = Player::query()->with(['currentClub', 'externalIds'])
            ->where(fn ($w) => $w->whereNull('enriched_at')->orWhere('enriched_at', '<', now()->subDays(14)))
            ->whereDoesntHave('externalIds', fn ($e) => $e->where('provider', $provider)->where('external_id', 'like', 'nao-encontrado%'));
        match ($this->option('scope')) {
            'shortlist' => $q->whereHas('prospectCases.stage', fn ($s) => $s->where('is_shortlist', true)),
            'missing' => $q->whereNull('birth_date'),
            default => $q->where('is_priority', true),
        };
        foreach ($q->orderByDesc('is_priority')->limit((int) $this->option('limit'))->get() as $p) {
            if (microtime(true) > $deadline) {
                $run->note('Prazo da execução atingido; continua na próxima.');
                break;
            }
            try {
                $link = $provider === 'transfermarkt' ? PlayerEnrichment::linkTransfermarkt($p) : PlayerEnrichment::linkSofascore($p);
                $run->requests_made++;
                if (in_array($link['status'], ['vinculado', 'ja_vinculado'], true)) {
                    $res = $provider === 'transfermarkt' ? PlayerEnrichment::enrichTransfermarkt($p->fresh()) : PlayerEnrichment::enrichSofascore($p->fresh());
                    $run->updated++;
                    $run->note("{$p->name}: {$res['message']}");
                } else {
                    $run->discarded++;
                    $run->note("{$p->name}: {$link['message']}");
                    $p->update(['enriched_at' => now()]); // evita repetir diariamente; revisão manual pendente
                }
            } catch (SourceException $e) {
                $run->errors++;
                $run->note("{$p->name}: {$e->getMessage()}");
                if (in_array($e->kind, ['bloqueado', 'cota', 'desativada', 'credencial'], true)) {
                    break;
                }
            }
            $run->save();
        }
        $run->finish();
        $this->info("{$provider}: {$run->status} · enriquecidos {$run->updated} · a revisar {$run->discarded} · erros {$run->errors}");
    }
}
