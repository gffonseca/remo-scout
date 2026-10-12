<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubExternalId extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** Métodos que bastam para corrigir dados sem revisão: confirmação humana, nome exato/sinônimo cadastrado, ou legado (já em uso). */
    public const TRUSTED = ['manual', 'nome_exato', 'criado', 'legado'];

    public const METHODS = ['manual' => 'Confirmado por pessoa', 'nome_exato' => 'Nome exato ou sinônimo', 'nucleo' => 'Núcleo do nome (a confirmar)',
        'subconjunto' => 'Parte do nome (a confirmar)', 'legado' => 'Anterior ao registro do método', 'criado' => 'Clube criado a partir da fonte'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function isTrusted(): bool
    {
        return $this->confirmed_at !== null || in_array($this->method, self::TRUSTED, true);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
