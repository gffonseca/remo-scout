<?php

namespace App\Support;

use App\Models\Club;
use App\Models\ClubExternalId;

/** Resolve clube de um provedor para o cadastro interno: id externo → nome exato/alias → núcleo do nome. Nunca cria por similaridade fraca. */
class ClubResolver
{
    private static ?array $cache = null;

    public static function resolve(string $provider, ?string $externalId, ?string $name, bool $createIfMissing = false, ?string $state = null): ?Club
    {
        if ($externalId !== null && $externalId !== '') {
            $map = ClubExternalId::where('provider', $provider)->where('external_id', $externalId)->first();
            if ($map) {
                return $map->club;
            }
        }
        $club = static::byName($name, $state);
        if (! $club && $createIfMissing && filled($name)) {
            $club = Club::create(['name' => trim($name), 'country' => 'Brasil', 'state' => $state]);
        }
        if ($club && $externalId) {
            ClubExternalId::firstOrCreate(['provider' => $provider, 'external_id' => $externalId], ['club_id' => $club->id, 'external_name' => $name]);
        }

        return $club;
    }

    public static function byName(?string $name, ?string $state = null): ?Club
    {
        if (! filled($name)) {
            return null;
        }
        static::$cache ??= Club::query()->get(['id', 'name', 'aliases', 'state'])->all();
        $norm = NameMatcher::normalize($name);
        $core = NameMatcher::clubCore($name);
        $exact = array_values(array_filter(static::$cache, fn ($c) => NameMatcher::normalize($c->name) === $norm
            || in_array($norm, array_map([NameMatcher::class, 'normalize'], $c->aliases ?? []), true)));
        if (count($exact) === 1) {
            return Club::find($exact[0]->id);
        }
        $coreHits = array_values(array_filter(static::$cache, fn ($c) => $core !== '' && NameMatcher::clubCore($c->name) === $core && (! $state || ! $c->state || $c->state === $state)));

        return count($coreHits) === 1 ? Club::find($coreHits[0]->id) : null;
    }

    public static function flush(): void
    {
        static::$cache = null;
    }
}
