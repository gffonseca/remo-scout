<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\RecruitmentNeed;

/** F01 — exportações seguem a mesma política de acesso do painel (usuário ativo + MFA obrigatório de admin). */
class ExportAccessTest extends ScoutTestCase
{
    private function adminWithMfa(): \App\Models\User
    {
        $u = $this->user('admin');
        $u->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        return $u->fresh();
    }

    public function test_admin_without_mfa_is_sent_to_mfa_setup_on_every_export(): void
    {
        $p = Player::create(['name' => 'Atleta']);
        $n = RecruitmentNeed::create(['title' => 'Lateral', 'position' => 'LE']);
        $this->actingAs($this->user('admin'));
        foreach ([route('dossie.player', $p), route('dossie.shortlist', $n), route('export.shortlist', [$n, 'csv'])] as $url) {
            $res = $this->get($url);
            $res->assertRedirect();
            $this->assertStringContainsString('multi-factor', $res->headers->get('Location'), $url);
        }
    }

    public function test_admin_with_mfa_exports(): void
    {
        $p = Player::create(['name' => 'Atleta']);
        $this->actingAs($this->adminWithMfa());
        $this->get(route('dossie.player', $p))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_non_admin_roles_follow_policy_without_forced_mfa(): void
    {
        $p = Player::create(['name' => 'Atleta']);
        foreach (['coordenador', 'analista', 'leitor'] as $role) {
            $this->actingAs($this->user($role));
            $this->get(route('dossie.player', $p))->assertOk();
        }
    }

    public function test_inactive_user_cannot_export_even_with_open_session(): void
    {
        $p = Player::create(['name' => 'Atleta']);
        $this->actingAs($this->user('coordenador', ['is_active' => false]));
        $this->get(route('dossie.player', $p))->assertRedirect('/login');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $p = Player::create(['name' => 'Atleta']);
        $this->get(route('dossie.player', $p))->assertRedirect('/login');
    }
}
