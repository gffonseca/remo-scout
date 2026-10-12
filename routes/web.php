<?php

use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

// O painel CIFUT Remo (Filament) ocupa "/". Exportações autenticadas abaixo.
Route::middleware(['auth'])->prefix('exportar')->group(function () {
    Route::get('dossie/atleta/{player}', [ExportController::class, 'playerDossier'])->name('dossie.player');
    Route::get('dossie/shortlist/{need}', [ExportController::class, 'shortlistDossier'])->name('dossie.shortlist');
    Route::get('shortlist/{need}.{format}', [ExportController::class, 'shortlistSheet'])->name('export.shortlist');
});
