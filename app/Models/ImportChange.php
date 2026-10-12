<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Uma alteração feita por um lote de importação (antes/depois em valor bruto do banco). */
class ImportChange extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_value' => 'json', 'new_value' => 'json'];
    }
}
