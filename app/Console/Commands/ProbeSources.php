<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Sources\SourceClient;
use App\Sources\SourceException;
use Illuminate\Console\Command;

/** Testa a saúde das fontes habilitadas com uma chamada leve e registra o resultado (sem segredos no log). */
class ProbeSources extends Command
{
    protected $signature = 'scout:probe-sources {--only=}';

    protected $description = 'Testa conectividade/acesso das fontes de dados';

    private const PROBES = [
        'api_futebol' => '/v1/campeonatos/10',
        'api_football' => '/status',
        'football_data' => '/notes.txt',
        'dados_futebol' => '/v1/me',
        'sportmonks' => 'https://api.sportmonks.com/v3/my/leagues',
        'transfermarkt' => '/players/search/Ziyech',
        'sofascore' => '/api/v1/search/all?q=remo',
        'sumula_fpf' => 'https://www.fpfpara.com.br/',
        'sumula_cbf' => 'https://conteudo.cbf.com.br/sumulas/2026/1421se.pdf',
        'cbf_atletas' => 'https://www.cbf.com.br/futebol-brasileiro/atletas/campeonato-brasileiro/serie-d/2026',
        'cbf_intermediarios' => 'https://intermediario.cbf.com.br/intermediarios-cadastrados',
    ];

    public function handle(): int
    {
        foreach (DataSource::where('enabled', true)->orderBy('key')->get() as $s) {
            if ($this->option('only') && $s->key !== $this->option('only')) {
                continue;
            }
            $path = self::PROBES[$s->key] ?? null;
            if ($s->transport === 'coletor_local') {
                $this->line("[probe] {$s->key}: coletada pelo coletor local (Mac Mini); saúde vem da última execução");

                continue;
            }
            if (! $path) {
                continue;
            }
            $headers = $s->authHeaders();
            try {
                $body = (new SourceClient($s))->getRaw($path, [], $headers);
                $this->line("[probe] {$s->key}: OK ".strlen($body).'B '.mb_substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 160));
            } catch (SourceException $e) {
                $this->line("[probe] {$s->key}: FALHA ({$e->kind}) ".mb_substr($e->getMessage(), 0, 200));
            } catch (\Throwable $e) {
                $s->update(['health' => 'degradado', 'last_error_at' => now(), 'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)]);
                $this->line("[probe] {$s->key}: ERRO ".class_basename($e).' '.mb_substr($e->getMessage(), 0, 200));
            }
        }

        return self::SUCCESS;
    }
}
