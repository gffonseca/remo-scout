<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/** MFA obrigatório para administradores; demais perfis podem ativar no perfil (recomendado). */
class RequireMfaForAdmins extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();
        if (! $user || ! $user->isAdmin()) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
