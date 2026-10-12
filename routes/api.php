<?php

use App\Http\Controllers\Api\CollectorController;
use App\Http\Middleware\AuthenticateCollector;
use Illuminate\Support\Facades\Route;

// Coletor local (Mac Mini, IP brasileiro) → REMO SCOUT. Token de serviço; sem sessão.
Route::middleware([AuthenticateCollector::class, 'throttle:240,1'])->prefix('coletor')->group(function () {
    Route::get('plano', [CollectorController::class, 'plan']);
    Route::get('cbf_atletas/pendentes', [CollectorController::class, 'pending']);
    Route::post('{source}/inicio', [CollectorController::class, 'start']);
    Route::post('{source}/lote', [CollectorController::class, 'batch']);
    Route::post('{source}/fim', [CollectorController::class, 'finish']);
});
