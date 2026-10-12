<?php

namespace App\Providers\Filament;

use App\Filament\Pages\CentralDeMercado;
use App\Http\Middleware\EnsureUserIsActive;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('scout')
            ->path('')
            ->login()
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(\App\Http\Middleware\RequireMfaForAdmins::class)
            ->brandName('REMO SCOUT — piloto')
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                'primary' => Color::hex('#79B9F2'),
                'gray' => Color::hex('#4A6286'),
                'warning' => Color::hex('#E9B65B'),
                'danger' => Color::Rose,
                'success' => Color::Emerald,
                'info' => Color::Sky,
            ])
            ->font(null)
            ->sidebarWidth('15rem')
            ->maxContentWidth(Width::Full)
            ->navigationGroups([
                NavigationGroup::make('Mercado'),
                NavigationGroup::make('Jogadores'),
                NavigationGroup::make('Prospecção'),
                NavigationGroup::make('Papéis e especialidades'),
                NavigationGroup::make('Dados e importação'),
                NavigationGroup::make('Administração')->collapsed(),
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->databaseNotifications(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([CentralDeMercado::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Blade::render('<style>{!! file_get_contents(resource_path("css/scout.css")) !!}</style>'))
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn () => view('scout.topbar-context'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureUserIsActive::class,
            ]);
    }
}
