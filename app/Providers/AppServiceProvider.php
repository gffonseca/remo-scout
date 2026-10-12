<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Banco em UTC; exibição em America/Belem.
        FilamentTimezone::set(config('scout.display_timezone'));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
