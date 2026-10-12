<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\DataSource;
use App\Models\Player;
use App\Models\SyncRun;
use App\Sources\DadosFutebol;
use App\Sources\SourceException;
use App\Support\SourceRunLock;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** Retrato do elenco atual (Dados Futebol) dos clubes que importam: Remo + clubes dos atletas prioritários. */
class SyncDadosFutebol extends Command
{
    protected $signature = 'scout:sync-dados-futebol {--club= : UUID de um clube} {--max=40} {--user=}';

    protected $description = 'Atualiza o retrato de elenco atual por clube a partir da Dados Futebol';

    public function handle(): int
    {
        $source = DataSource::byKey(DadosFutebol::PROVIDER);
        if (! $source->enabled) {
            $this->warn('Dados Futebol desativada.');

            return self::SUCCESS;
        }
        if (! SourceRunLock::run(DadosFutebol::PROVIDER, fn () => $this->runSync($source))) {
            $this->warn('Dados Futebol: já existe sincronização em andamento.');
        }

        return self::SUCCESS;
    }

    private function runSync(DataSource $source): void
    {
        SyncRun::closeStale($source->id, 40);
        $run = SyncRun::create(['data_source_id' => $source->id, 'job' => 'elencos', 'status' => 'em_execucao', 'started_at' => now(),
            'scope' => ['club' => $this->option('club'), 'max' => (int) $this->option('max')], 'requested_by' => $this->option('user')]);
        $api = new DadosFutebol;
        $done = 0;
        foreach ($this->clubs() as $club) {
            try {
                $api->syncSquad($club, $run) && $done++;
            } catch (SourceException $e) {
                $run->errors++;
                $run->note("{$club->name}: {$e->getMessage()}");
                if (in_array($e->kind, ['cota', 'circuito', 'limite', 'bloqueado', 'credencial', 'desativada'], true)) {
                    break; // problema da fonte, não do clube: para aqui
                }
            }
        }
        $run->note("{$done} elenco(s) atualizados.");
        $run->finish($run->errors > 0 ? ($done > 0 ? 'parcial' : 'falhou') : 'concluido');
        $this->info("Dados Futebol: {$run->status} · requisições {$run->requests_made} · atletas no retrato {$run->received} · erros {$run->errors}");
    }

    /** @return Collection<int, Club> */
    private function clubs()
    {
        if ($id = $this->option('club')) {
            return Club::whereKey($id)->get();
        }
        $ids = Player::where('is_priority', true)->whereNotNull('current_club_id')->distinct()->pluck('current_club_id')
            ->merge(Club::whereRaw('lower(name) in (?, ?)', ['remo', 'clube do remo'])->pluck('id'))->unique();

        return Club::whereIn('id', $ids)->orderBy('name')->limit((int) $this->option('max'))->get();
    }
}
