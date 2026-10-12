<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\SyncRun;
use Database\Seeders\DataSourcesSeeder;

/** F20 — "Operacional" só com ingestão ponta a ponta + cobertura conferida + uso autorizado. */
class IntegrationMatrixTest extends ScoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DataSourcesSeeder::class);
    }

    public function test_stages_progress_only_with_evidence(): void
    {
        $s = DataSource::byKey('api_futebol');
        $s->update(['last_success_at' => null, 'coverage_checked_at' => null]);
        $this->assertSame('cadastrada', $s->fresh()->integrationStage());
        $s->update(['last_success_at' => now()]);
        $this->assertSame('testada', $s->fresh()->integrationStage()); // saúde OK não é integração
        SyncRun::create(['data_source_id' => $s->id, 'job' => 'x', 'status' => 'concluido', 'received' => 0, 'finished_at' => now()]);
        $this->assertSame('testada', $s->fresh()->integrationStage()); // execução vazia não prova ingestão
        SyncRun::create(['data_source_id' => $s->id, 'job' => 'x', 'status' => 'parcial', 'received' => 40, 'finished_at' => now()]);
        $this->assertSame('ingerindo', $s->fresh()->integrationStage());
        $this->assertSame(['cobertura não conferida'], $s->fresh()->integrationGaps());
        $s->update(['coverage_checked_at' => now(), 'coverage_note' => 'amostra']);
        $this->assertSame('operacional', $s->fresh()->integrationStage());
        $s->update(['usage_authorization' => 'pendente']);
        $this->assertSame('ingerindo', $s->fresh()->integrationStage());
    }

    public function test_seeded_decisions_and_manual_and_unauthorized_sources(): void
    {
        $this->assertSame('nao_autorizada', DataSource::byKey('sportmonks')->integrationStage());
        $this->assertSame('manual', DataSource::byKey('bid_cbf')->integrationStage());
        $this->assertSame('restrito', DataSource::byKey('sofascore')->usage_authorization);
        $this->assertNotNull(DataSource::byKey('api_football')->coverage_checked_at);
        // seeder não sobrescreve decisão posterior do admin
        DataSource::byKey('transfermarkt')->update(['usage_authorization' => 'nao_autorizado']);
        $this->seed(DataSourcesSeeder::class);
        $this->assertSame('nao_autorizado', DataSource::byKey('transfermarkt')->usage_authorization);
    }

    public function test_sources_page_shows_matrix(): void
    {
        $this->actingAs($this->user('coordenador'));
        $this->get('/fontes')->assertOk()->assertSee('Integração')->assertSee('Não autorizada')->assertSee('Consulta manual');
    }
}
