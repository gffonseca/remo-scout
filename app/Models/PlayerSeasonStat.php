<?php

namespace App\Models;

use App\Support\PlayerStatsView;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estatística por temporada e competição, sempre atrelada ao provedor (não somar provedores). */
class PlayerSeasonStat extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['extra' => 'array', 'fetched_at' => 'datetime', 'rating' => 'decimal:2'];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** Clube canônico (vínculo confirmado). Sem ele, a tela mostra "Clube não identificado". */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * Identidade do clube na linha (parte do unique): o ID na fonte vence; depois o clube canônico; depois o rótulo da fonte.
     * Duas passagens na mesma temporada/competição ficam em linhas distintas.
     */
    public static function clubKey(?string $externalId, ?string $clubId = null, ?string $sourceName = null): string
    {
        return match (true) {
            filled($externalId) => 'ext:'.$externalId,
            filled($clubId) => 'club:'.$clubId,
            filled($sourceName) => 'name:'.mb_strtolower(trim($sourceName)),
            default => '',
        };
    }

    /** Nome exibível: só o clube canônico. ID e rótulo da fonte ficam para o detalhe de suporte. */
    public function clubLabel(): ?string
    {
        return $this->club?->name;
    }

    public function hasClubReference(): bool
    {
        return $this->club_id !== null || filled($this->club_external_id) || filled($this->club_source_name);
    }

    /** Detalhe de suporte para clube não identificado (rótulo e ID da fonte). */
    public function clubSupportDetail(): string
    {
        $src = PlayerStatsView::providerLabel($this->provider);

        return trim($src.': '.($this->club_source_name ?: 'sem nome na fonte').($this->club_external_id ? ' (ID '.$this->club_external_id.')' : '')).' — vínculo pendente em Cobertura de dados.';
    }

    /** por90 = total × 90 / minutos; null quando minutos ausentes ou zero. */
    public static function per90(?int $total, ?int $minutes): ?float
    {
        if ($total === null || ! $minutes) {
            return null;
        }

        return round($total * 90 / $minutes, 2);
    }
}
