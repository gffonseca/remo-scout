<?php

namespace Tests\Feature;

use App\Models\MarketObservation;
use App\Models\Player;
use App\Models\RecruitmentNeed;

class AccessTest extends ScoutTestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/atletas')->assertRedirect('/login');
        $p = Player::create(['name' => 'X']);
        $this->get(route('dossie.player', $p))->assertRedirect('/login');
    }

    public function test_health_endpoint(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_pages_render_for_coordinator(): void
    {
        $this->actingAs($this->user('coordenador'));
        $p = Player::create(['name' => 'Render']);
        $n = RecruitmentNeed::create(['title' => 'Vaga', 'position' => 'LE']);
        foreach (['/', '/atletas', '/atletas/create', "/atletas/{$p->id}", '/necessidades', "/necessidades/{$n->id}", '/relatorios', '/relatorios/create',
            '/papeis', '/especialidades', '/quadro-prospeccao', '/importar-planilha', '/importacoes', '/clubes', '/competicoes', '/empresarios', '/auditoria'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_reader_cannot_create_or_import_or_see_finances(): void
    {
        $reader = $this->user('leitor');
        $this->actingAs($reader);
        $p = Player::create(['name' => 'Leitura', 'salary_min' => 50000]);
        $this->get('/atletas')->assertOk();
        $this->get('/atletas/create')->assertForbidden();
        $this->get("/atletas/{$p->id}/edit")->assertForbidden();
        $this->get('/importar-planilha')->assertForbidden();
        $this->get('/usuarios')->assertForbidden();
        $this->assertFalse($reader->can('viewAny', MarketObservation::class));
        $this->get("/atletas/{$p->id}")->assertOk()->assertDontSee('50.000');
        $pdf = $this->get(route('dossie.player', $p));
        $pdf->assertOk();
    }

    public function test_reader_with_financial_permission_sees_salary(): void
    {
        $this->actingAs($this->user('leitor', ['can_view_financial' => true]));
        $p = Player::create(['name' => 'Financeiro', 'salary_min' => 50000]);
        $this->get("/atletas/{$p->id}")->assertOk()->assertSee('50.000');
    }

    public function test_inactive_user_is_logged_out(): void
    {
        $this->actingAs($this->user('analista', ['is_active' => false]));
        $this->get('/atletas')->assertStatus(403);
    }

    public function test_shortlist_csv_export(): void
    {
        $this->actingAs($this->user('coordenador'));
        $n = RecruitmentNeed::create(['title' => 'Exportável', 'position' => 'CA']);
        $res = $this->get(route('export.shortlist', [$n, 'csv']));
        $res->assertOk();
        $this->assertStringContainsString('Uso interno', $res->streamedContent());
        $this->get(route('export.shortlist', [$n, 'pdf']))->assertNotFound();
    }

    public function test_mfa_required_for_admin_only(): void
    {
        $this->actingAs($this->user('admin'));
        $res = $this->get('/atletas');
        $res->assertRedirect();
        $this->assertStringContainsString('multi-factor', $res->headers->get('Location'));
    }

    public function test_mfa_not_forced_for_coordinator(): void
    {
        $this->actingAs($this->user('coordenador'));
        $this->get('/atletas')->assertOk();
    }
}
