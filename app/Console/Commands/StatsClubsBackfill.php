<?php

namespace App\Console\Commands;

use App\Models\ClubExternalId;
use App\Models\PlayerSeasonStat;
use App\Services\DataFix;
use App\Sources\Transfermarkt;
use App\Support\PlayerStatsView;
use Illuminate\Console\Command;

/**
 * Backfill do clube nas estatísticas (linhas com ID do clube da fonte e sem clube canônico).
 * Padrão = prévia (não grava). --apply grava com trilha (lote) e --revert=<lote> desfaz.
 * Clube canônico só por vínculo confiável do ID (club_external_ids); nunca por semelhança de nome.
 */
class StatsClubsBackfill extends Command
{
    protected $signature = 'scout:stats-clubs {--apply : grava (sem isso é só prévia)} {--labels : busca o rótulo do clube na fonte para os não vinculados (Transfermarkt, com cache)}
        {--revert= : desfaz um lote aplicado} {--actor= : quem autorizou (registrado na trilha)}';

    protected $description = 'Vincula o clube canônico às estatísticas pelo ID do clube na fonte (prévia, aplicação e reversão)';

    /** provedor da estatística → provedor do mapeamento de clubes */
    private const MAP_PROVIDER = ['transfermarkt' => 'transfermarkt', 'cbf' => 'cbf'];

    public function handle(): int
    {
        if ($batch = $this->option('revert')) {
            $r = DataFix::revert($batch);
            $this->info("Revertidas: {$r['reverted']}; já revertidas: {$r['already']}; conflitos (editadas depois): ".count($r['conflicts']).'.');
            foreach ($r['conflicts'] as $c) {
                $this->warn("  conflito: {$c}");
            }

            return self::SUCCESS;
        }
        $apply = (bool) $this->option('apply');
        $rows = PlayerSeasonStat::whereNull('club_id')->whereNotNull('club_external_id')->with('player:id,name')->get();
        $this->line(($apply ? 'APLICAR' : 'PRÉVIA (nada é gravado)').": {$rows->count()} linha(s) com ID de clube da fonte e sem clube canônico.");
        $batch = $apply ? DataFix::newBatch('stats_club') : null;
        $tm = $this->option('labels') ? new Transfermarkt : null;
        $stats = ['vincular' => 0, 'rotulo' => 0, 'sem_vinculo' => 0, 'vinculo_a_confirmar' => 0];
        $preview = [];
        foreach ($rows as $r) {
            $mapProvider = self::MAP_PROVIDER[$r->provider] ?? $r->provider;
            $map = ClubExternalId::with('club')->where('provider', $mapProvider)->where('external_id', $r->club_external_id)->first();
            $after = [];
            if ($map && $map->isTrusted()) {
                $after = ['club_id' => $map->club_id, 'club_name' => $map->club?->name];
                $stats['vincular']++;
            } else {
                $stats[$map ? 'vinculo_a_confirmar' : 'sem_vinculo']++;
            }
            if (! $r->club_source_name) {
                $label = $map?->external_name;
                if (! $label && $tm && $r->provider === 'transfermarkt') {
                    $label = $tm->clubIdentity($r->club_external_id, [], true)['label'];
                }
                if ($label && ! ctype_digit($label)) {
                    $after['club_source_name'] = $label;
                    $stats['rotulo']++;
                }
            }
            if ($after) {
                if (count($preview) < 15) {
                    $preview[] = [$r->player?->name, PlayerStatsView::providerLabel($r->provider), $r->season, $r->club_external_id,
                        ($r->club_source_name ?? '—').' → '.($after['club_source_name'] ?? $r->club_source_name ?? '—'), $after['club_name'] ?? 'não identificado'];
                }
                if ($apply) {
                    DataFix::apply($batch, 'stats_club', 'player_season_stats', $r->id, $after, $this->option('actor') ?: 'cli');
                }
            }
        }
        if ($preview) {
            $this->table(['Atleta', 'Fonte', 'Temp.', 'ID clube', 'Rótulo da fonte', 'Clube canônico'], $preview);
        }
        $this->info("Vincular ao clube canônico: {$stats['vincular']} · rótulo da fonte: {$stats['rotulo']} · sem vínculo do ID: {$stats['sem_vinculo']} · vínculo por semelhança a confirmar: {$stats['vinculo_a_confirmar']}.");
        $this->line($apply ? "Lote {$batch} gravado. Para desfazer: php artisan scout:stats-clubs --revert={$batch}" : 'Para gravar: --apply (com autorização).');

        return self::SUCCESS;
    }
}
