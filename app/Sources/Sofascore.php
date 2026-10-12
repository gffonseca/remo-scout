<?php

namespace App\Sources;

/**
 * Sofascore via endpoints internos (não há API oficial — FAQ do Sofascore).
 * FONTE NÃO OFICIAL — uso autorizado por Gustavo em 11/10/2026; sob demanda, com intervalo entre chamadas.
 * A nota (rating) é métrica proprietária: guardada à parte, nunca usada como filtro principal.
 */
class Sofascore
{
    public const PROVIDER = 'sofascore';

    public const POSITIONS = ['G' => 'GOL', 'D' => 'ZAG', 'M' => 'MC', 'F' => 'CA'];

    public function __construct(private ?SourceClient $client = null)
    {
        $this->client ??= SourceClient::for('sofascore');
    }

    /** @return list<array> jogadores */
    public function search(string $name): array
    {
        $res = $this->client->getJson('/api/v1/search/all', ['q' => $name]);

        return array_values(array_map(fn ($r) => $r['entity'], array_filter($res['results'] ?? [], fn ($r) => ($r['type'] ?? '') === 'player')));
    }

    public function player(string $id): array
    {
        return $this->client->getJson("/api/v1/player/{$id}")['player'] ?? [];
    }

    public function seasons(string $id): array
    {
        return $this->client->getJson("/api/v1/player/{$id}/statistics/seasons")['uniqueTournamentSeasons'] ?? [];
    }

    public function seasonStats(string $id, int $tournamentId, int $seasonId): array
    {
        return $this->client->getJson("/api/v1/player/{$id}/unique-tournament/{$tournamentId}/season/{$seasonId}/statistics/overall")['statistics'] ?? [];
    }
}
