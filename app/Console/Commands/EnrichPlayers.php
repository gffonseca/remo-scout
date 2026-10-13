<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\Player;
use App\Models\ProspectCase;
use App\Models\SyncRun;
use App\Models\WatchlistItem;
use App\Services\Collector\SofascoreTasks;
use App\Services\DataCoverage;
use App\Services\PlayerEnrichment;
use App\Sources\SourceException;
use App\Support\SourceRunLock;
use Illuminate\Console\Command;

class EnrichPlayers extends Command
{
    /** Prioridade de cada atleta na fila (só exibição; nunca vai ao modelo, que é salvo depois). @var array<string,string> */
    private array $ranks = [];

    protected $signature = 'scout:enrich {provider : transfermarkt|sofascore} {--scope=priority : priority|shortlist|missing|cobertura|scout} {--limit=30} {--user=} {--actor= : user:<id> (botões da tela)} {--dry-run : só lista quem seria processado, sem chamar a fonte}';

    protected $description = 'Vincula e enriquece atletas por fonte externa (sob demanda, em lote pequeno)';

    public function handle(): int
    {
        $provider = $this->argument('provider');
        if (! $this->option('user') && str_starts_with((string) $this->option('actor'), 'user:')) {
            $this->input->setOption('user', substr($this->option('actor'), 5));
        }
        abort_unless(in_array($provider, ['transfermarkt', 'sofascore'], true), 1, 'Provedor inválido');
        $source = DataSource::byKey($provider);
        if ($this->option('dry-run')) {
            $this->table(['Atleta', 'Clube', 'Prioridade'], $this->candidates($provider)->map(fn ($p) => [$p->name, $p->currentClub?->name ?? '—', $this->ranks[$p->id] ?? ($p->is_priority ? 'lista prioritária' : '')])->all());
            $this->line('Prévia: nenhuma chamada à fonte.');

            return self::SUCCESS;
        }
        if (! SourceRunLock::run($provider, fn () => $this->enrich($provider, $source))) {
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

        foreach ($this->candidates($provider) as $p) {
            if (microtime(true) > $deadline) {
                $run->note('Prazo da execução atingido; continua na próxima.');
                break;
            }
            if ($source->transport === 'coletor_local') { // Sofascore: pedido ao coletor do Mac, concluído nas próximas rodadas
                $t = SofascoreTasks::request($p, $this->option('user') ? (int) $this->option('user') : null);
                $run->note("{$p->name}: ".($t->status === 'aguardando_coletor' ? 'pedido enviado ao coletor do Mac' : ($t->result ?? $t->status)));
                $t->status === 'falhou' ? $run->errors++ : $run->updated++;
                $run->save();

                continue;
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

    /**
     * Fila do lote. "cobertura": atletas sem estatística utilizável (estado da Cobertura de dados), priorizando
     * watchlist ativa → candidatos de necessidades abertas → lista prioritária. Retomável: quem foi tentado há menos de 14 dias sai da fila.
     */
    private function candidates(string $provider)
    {
        $q = Player::query()->with(['currentClub', 'externalIds'])
            ->where(fn ($w) => $w->whereNull('enriched_at')->orWhere('enriched_at', '<', now()->subDays(14)))
            ->whereDoesntHave('externalIds', fn ($e) => $e->where('provider', $provider)->where('external_id', 'like', 'nao-encontrado%'));
        if (in_array($this->option('scope'), ['cobertura', 'scout'], true)) {
            $states = DataCoverage::rows()->keyBy('id');
            // cobertura: só quem está sem estatística utilizável. scout: todos os atletas do scout (perfil, carreira, valor, contrato),
            // menos quem já tem candidatos desta fonte esperando revisão humana (buscar de novo só repetiria a fila).
            $want = $this->option('scope') === 'cobertura' ? ['aguardando_vinculo', 'fora_cobertura', 'desatualizado', 'nao_coletado', 'sem_participacao'] : null;
            $ids = $states->filter(fn ($r) => $want === null || in_array($r->stats_state, $want, true))->keys();
            $watch = WatchlistItem::where('status', 'ativo')->pluck('player_id')->flip();
            $needs = ProspectCase::whereHas('need', fn ($n) => $n->where('status', 'aberta'))->pluck('player_id')->flip();
            $order = ['watchlist', 'necessidade aberta', 'lista prioritária', 'base'];

            // quem ganhou nascimento (ex.: CBF confirmada) volta à fila mesmo com candidatos pendentes e tentativa recente:
            // agora o vínculo automático por nome + nascimento pode resolver sozinho
            $retry = Player::query()->with(['currentClub', 'externalIds'])->whereIn('id', $ids)->whereNotNull('birth_date')
                ->whereDoesntHave('externalIds', fn ($e) => $e->where('provider', $provider))
                ->whereHas('identityCandidates', fn ($c) => $c->where('provider', $provider)->where('status', 'pendente'))
                ->where(fn ($w) => $w->whereNull('enriched_at')->orWhereColumn('enriched_at', '<', 'updated_at')->orWhere('enriched_at', '<', now()->subDay()));

            return $q->whereIn('id', $ids)
                ->whereDoesntHave('identityCandidates', fn ($c) => $c->where('provider', $provider)->where('status', 'pendente'))
                ->get()->merge($retry->get())->unique('id')
                ->each(function ($p) use ($watch, $needs) {
                    $this->ranks[$p->id] = $watch->has($p->id) ? 'watchlist' : ($needs->has($p->id) ? 'necessidade aberta' : ($p->is_priority ? 'lista prioritária' : 'base'));
                })
                ->sortBy(fn ($p) => array_search($this->ranks[$p->id], $order, true).'|'.($p->enriched_at ? '1'.$p->enriched_at->format('YmdHis') : '0'))
                ->take((int) $this->option('limit'))->values();
        }
        match ($this->option('scope')) {
            'shortlist' => $q->whereHas('prospectCases.stage', fn ($s) => $s->where('is_shortlist', true)),
            'missing' => $q->whereNull('birth_date'),
            default => $q->where('is_priority', true),
        };

        return $q->orderByDesc('is_priority')->limit((int) $this->option('limit'))->get();
    }
}
