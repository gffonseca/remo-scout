<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Aba do perfil com a contagem de registros (vazio = sem badge; não mostra "0" como se fosse dado). */
trait RelationCountBadge
{
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $q = $ownerRecord->{static::$relationship}()->getQuery();
        $n = static::scopeBadgeQuery($q)->count();

        return $n > 0 ? (string) $n : null;
    }

    protected static function scopeBadgeQuery(Builder $query): Builder
    {
        return $query;
    }
}
