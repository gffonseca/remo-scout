<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OddsMatch extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['played_on' => 'date', 'odds' => 'array', 'implied' => 'array', 'fetched_at' => 'datetime'];
    }
}
