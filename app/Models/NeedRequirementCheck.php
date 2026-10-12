<?php

namespace App\Models;

use App\Enums\RequirementStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Situação de um requisito textual obrigatório (disponibilidade, contrato, laudo...) para um candidato.
 * Analista registra evidência; só coordenador/admin define Atende / Não atende / Não se aplica.
 */
class NeedRequirementCheck extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => RequirementStatus::class, 'evidence_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public static function keyFor(string $text): string
    {
        return sha1(Str::of($text)->ascii()->lower()->squish()->toString());
    }

    public function need(): BelongsTo
    {
        return $this->belongsTo(RecruitmentNeed::class, 'recruitment_need_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function evidenceAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evidence_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function hasProof(): bool
    {
        return filled($this->evidence) && (filled($this->evidence_url) || filled($this->evidence_path));
    }
}
