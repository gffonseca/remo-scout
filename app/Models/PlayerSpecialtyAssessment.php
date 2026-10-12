<?php

namespace App\Models;

use App\Enums\Confidence;
use App\Enums\Level;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerSpecialtyAssessment extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['level' => Level::class, 'confidence' => Confidence::class];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a) {
            $a->specialty_version = $a->specialty_version ?: (Specialty::find($a->specialty_id)?->version ?? 1);
        });
        static::saving(function (self $a) {
            if ($a->sample_successes !== null && $a->sample_attempts !== null && $a->sample_successes > $a->sample_attempts) {
                throw new \InvalidArgumentException('Sucessos não podem exceder tentativas.');
            }
        });
    }

    /** Taxa só com denominador > 0; caso contrário null (indisponível), nunca zero. */
    protected function successRate(): Attribute
    {
        return Attribute::get(fn () => ($this->sample_attempts ?? 0) > 0 && $this->sample_successes !== null
            ? round($this->sample_successes / $this->sample_attempts, 4)
            : null);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
