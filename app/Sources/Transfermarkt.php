<?php

namespace App\Sources;

use App\Models\ClubExternalId;

/**
 * Transfermarkt via API não oficial (felipeall/transfermarkt-api; instância configurável em base_url).
 * FONTE NÃO OFICIAL — termos do Transfermarkt restringem coleta automatizada; uso autorizado por Gustavo em 11/10/2026.
 * Consulta sob demanda, um atleta por vez. Não consultamos /injuries (dado de saúde fica fora da base).
 */
class Transfermarkt
{
    public const PROVIDER = 'transfermarkt';

    public const POSITIONS = [
        'goalkeeper' => 'GOL', 'centre-back' => 'ZAG', 'left-back' => 'LE', 'right-back' => 'LD',
        'defensive midfield' => 'VOL', 'central midfield' => 'MC', 'attacking midfield' => 'MEI',
        'left midfield' => 'ME', 'right midfield' => 'MD', 'left winger' => 'PE', 'right winger' => 'PD',
        'second striker' => 'SA', 'centre-forward' => 'CA',
    ];

    public const EU = ['Austria', 'Belgium', 'Bulgaria', 'Croatia', 'Cyprus', 'Czech Republic', 'Denmark', 'Estonia', 'Finland', 'France', 'Germany', 'Greece', 'Hungary', 'Ireland', 'Italy', 'Latvia', 'Lithuania', 'Luxembourg', 'Malta', 'Netherlands', 'Poland', 'Portugal', 'Romania', 'Slovakia', 'Slovenia', 'Spain', 'Sweden'];

    public const COUNTRY_PT = ['Brazil' => 'Brasil', 'Argentina' => 'Argentina', 'Uruguay' => 'Uruguai', 'Paraguay' => 'Paraguai', 'Colombia' => 'Colômbia', 'Chile' => 'Chile', 'Peru' => 'Peru', 'Ecuador' => 'Equador', 'Bolivia' => 'Bolívia', 'Venezuela' => 'Venezuela', 'Portugal' => 'Portugal', 'Spain' => 'Espanha', 'Italy' => 'Itália', 'Morocco' => 'Marrocos', 'Netherlands' => 'Holanda', 'France' => 'França', 'Germany' => 'Alemanha', 'Angola' => 'Angola', 'Congo' => 'Congo', 'Benin' => 'Benin', 'United States' => 'Estados Unidos', 'Japan' => 'Japão'];

    public function __construct(private ?SourceClient $client = null)
    {
        $this->client ??= SourceClient::for('transfermarkt');
    }

    public function search(string $name): array
    {
        return $this->client->getJson('/players/search/'.rawurlencode($name))['results'] ?? [];
    }

    public function profile(string $id, ?int $cacheSeconds = null): array
    {
        return $this->client->getJson("/players/{$id}/profile", [], [], $cacheSeconds);
    }

    public function stats(string $id): array
    {
        return $this->client->getJson("/players/{$id}/stats")['stats'] ?? [];
    }

    /**
     * Clube de uma linha de estatística pelo ID do Transfermarkt (a fonte manda só o ID).
     * Clube canônico: só pelo vínculo confirmado em club_external_ids — nunca por semelhança de nome.
     * Rótulo da fonte: do perfil do atleta (mesmo ID) ou do perfil do clube na fonte (cache de 30 dias); serve de detalhe/pendência.
     *
     * @return array{club_id:?string, club_name:?string, label:?string}
     */
    public function clubIdentity(string $id, array $labels = [], bool $fetchLabel = true): array
    {
        $map = ClubExternalId::with('club')->where('provider', self::PROVIDER)->where('external_id', $id)->first();
        $label = $labels[$id] ?? $map?->external_name;
        if ($label === null && $fetchLabel && ctype_digit($id)) {
            try {
                $name = $this->client->getJson("/clubs/{$id}/profile", [], [], 30 * 86400)['name'] ?? null;
                $label = is_string($name) && $name !== '' && ! ctype_digit($name) ? $name : null;
            } catch (SourceException) {
                $label = null;
            }
        }

        $trusted = $map?->isTrusted() ?? false; // núcleo/parte do nome sem confirmação não corrige dado

        return ['club_id' => $trusted ? $map->club_id : null, 'club_name' => $trusted ? $map->club?->name : null, 'label' => $label];
    }

    public static function position(?string $tm): ?string
    {
        return self::POSITIONS[mb_strtolower(trim((string) $tm))] ?? null;
    }

    public static function country(?string $c): ?string
    {
        return $c ? (self::COUNTRY_PT[$c] ?? $c) : null;
    }
}
