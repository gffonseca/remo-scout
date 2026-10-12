<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Coletor local (Mac Mini) autenticado por token de serviço (variável COLLECTOR_TOKEN no Railway). */
class AuthenticateCollector
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('scout.collector_token');
        $given = (string) $request->bearerToken();
        if (strlen($expected) < 32 || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['erro' => 'não autorizado'], 401);
        }

        return $next($request);
    }
}
