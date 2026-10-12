<?php

namespace App\Sources;

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

    public function profile(string $id): array
    {
        return $this->client->getJson("/players/{$id}/profile");
    }

    public function stats(string $id): array
    {
        return $this->client->getJson("/players/{$id}/stats")['stats'] ?? [];
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
