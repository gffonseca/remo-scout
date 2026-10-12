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
        [$club, $method] = static::match($name, $state);
        if (! $club && $createIfMissing && filled($name)) {
            $club = Club::create(['name' => trim($name), 'country' => 'Brasil', 'state' => $state]);
            $method = 'criado';
        }
        if ($club && $externalId) {
            ClubExternalId::firstOrCreate(['provider' => $provider, 'external_id' => $externalId], ['club_id' => $club->id, 'external_name' => $name, 'method' => $method]);
        }

        return $club;
    }

    public static function byName(?string $name, ?string $state = null): ?Club
    {
        return static::match($name, $state)[0];
    }

    /** @return array{0: ?Club, 1: ?string} clube e método (nome_exato | nucleo | subconjunto) */
    public static function match(?string $name, ?string $state = null): array
    {
        if (! filled($name)) {
            return [null, null];
        }
        static::$cache ??= Club::query()->get(['id', 'name', 'aliases', 'state'])->all();
        $norm = NameMatcher::normalize($name);
        $core = NameMatcher::clubCore($name);
        $exact = array_values(array_filter(static::$cache, fn ($c) => NameMatcher::normalize($c->name) === $norm
            || in_array($norm, array_map([NameMatcher::class, 'normalize'], $c->aliases ?? []), true)));
        if (count($exact) === 1) {
            return [Club::find($exact[0]->id), 'nome_exato'];
        }
        $coreHits = array_values(array_filter(static::$cache, fn ($c) => $core !== '' && NameMatcher::clubCore($c->name) === $core && (! $state || ! $c->state || $c->state === $state)));

        if (count($coreHits) === 1) {
            return [Club::find($coreHits[0]->id), 'nucleo'];
        }
        // subconjunto inequívoco ("Vasco" → "Vasco da Gama"; "Bragantino" → "Red Bull Bragantino")
        $tokens = array_filter(explode(' ', $core), fn ($t) => mb_strlen($t) >= 4);
        if ($tokens) {
            $subset = array_values(array_filter(static::$cache, function ($c) use ($tokens, $state) {
                $ct = explode(' ', NameMatcher::clubCore($c->name));

                return ! array_diff($tokens, $ct) && (! $state || ! $c->state || $c->state === $state);
            }));
            if (count($subset) === 1) {
                return [Club::find($subset[0]->id), 'subconjunto'];
            }
        }

        return [null, null];
    }

    public static function flush(): void
    {
        static::$cache = null;
    }
}
