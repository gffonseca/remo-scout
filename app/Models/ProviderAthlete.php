<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Cadastro do atleta no provedor (não é cadastro do scout). Serve de evidência de identidade (nascimento). */
class ProviderAthlete extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'fetched_at' => 'datetime'];
    }
}
