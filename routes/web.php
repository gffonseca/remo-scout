<?php

use App\Http\Controllers\ExportController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireMfaForAdmins;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\SetUpPanel;
use Illuminate\Support\Facades\Route;

// O painel CIFUT Remo (Filament) ocupa "/". Exportações ficam fora das páginas do painel, mas com a mesma
// política de acesso: painel carregado, sessão autenticada, usuário ativo e MFA obrigatório para admin
// (conta sem MFA cadastrado vai para o cadastramento). O desafio do segundo fator ocorre no login.
// A autorização de cada recurso continua nas policies, dentro do controller.
Route::middleware([SetUpPanel::class.':scout', 'auth', AuthenticateSession::class, EnsureUserIsActive::class, RequireMfaForAdmins::class])
    ->prefix('exportar')->group(function () {
        Route::get('dossie/atleta/{player}', [ExportController::class, 'playerDossier'])->name('dossie.player');
        Route::get('dossie/shortlist/{need}', [ExportController::class, 'shortlistDossier'])->name('dossie.shortlist');
        Route::get('shortlist/{need}.{format}', [ExportController::class, 'shortlistSheet'])->name('export.shortlist');
        Route::get('pesquisa/{need}.csv', [ExportController::class, 'needSearchSheet'])->name('export.need-search');
    });
