<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usuário desativado perde a sessão imediatamente. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            Filament::auth()->logout();
            $request->session()->invalidate();

            return redirect()->to(Filament::getLoginUrl());
        }

        return $next($request);
    }
}
