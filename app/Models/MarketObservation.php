<?php

namespace App\Models;

use App\Enums\Confirmation;
use App\Enums\MarketKind;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Componente financeiro observado. Valor de mercado é referência, nunca preço negociado nem salário. */
class MarketObservation extends Model
{
    use Auditable, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => MarketKind::class,
            'confirmation' => Confirmation::class,
            'amount' => 'decimal:2',
            'amount_max' => 'decimal:2',
            'observed_on' => 'date',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
