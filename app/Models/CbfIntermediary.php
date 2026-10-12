<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Intermediário cadastrado na CBF (registro público: nome, tipo de pessoa, data de publicação). */
class CbfIntermediary extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_on' => 'date', 'last_seen_at' => 'datetime'];
    }
}
