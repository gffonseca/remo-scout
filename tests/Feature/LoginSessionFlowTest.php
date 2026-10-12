<?php

namespace Tests\Feature;

use App\Models\Player;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/** Prompt mestre 3.1 — transições reais de sessão (login → desafio MFA → exportação), além de actingAs. */
class LoginSessionFlowTest extends ScoutTestCase
{
    private function login(string $email, string $password = 'secret-password-123')
    {
        return Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
    }

    public function test_admin_without_mfa_logs_in_and_is_sent_to_setup_before_exporting(): void
    {
        $u = $this->user('admin');
        $p = Player::create(['name' => 'Atleta']);
        $this->login($u->email)->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($u);
        $res = $this->get(route('dossie.player', $p))->assertRedirect();
        $this->assertStringContainsString('multi-factor', $res->headers->get('Location'));
    }

    public function test_admin_with_mfa_must_pass_challenge_then_exports(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $u = $this->user('admin');
        $u->forceFill(['app_authentication_secret' => $secret])->save();
        $p = Player::create(['name' => 'Atleta']);

        $lw = $this->login($u->email);
        $this->assertGuest(); // senha certa ainda não basta
        $this->assertNotNull($lw->get('userUndertakingMultiFactorAuthentication'));

        $lw->set('data.multiFactor.app.code', '000000')->call('authenticate');
        $this->assertGuest(); // código errado não entra

        $lw->set('data.multiFactor.app.code', (new Google2FA)->getCurrentOtp($secret))->call('authenticate');
        $this->assertAuthenticatedAs($u->fresh());
        $this->get(route('dossie.player', $p))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_wrong_password_and_inactive_user_never_get_a_session(): void
    {
        $u = $this->user('coordenador');
        $this->login($u->email, 'senha-errada')->assertHasFormErrors(['email']);
        $this->assertGuest();
        $off = $this->user('analista', ['is_active' => false]);
        $this->login($off->email);
        $this->get(route('dossie.player', Player::create(['name' => 'X'])))->assertRedirect();
    }
}
