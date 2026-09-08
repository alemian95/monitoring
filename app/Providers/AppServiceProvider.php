<?php

namespace App\Providers;

use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Il monitoring vive di due processi: senza schedule:work nessun check
        // parte, senza il worker nessun alert. `php artisan dev` li avvia
        // entrambi, così non e' possibile dimenticarne uno.
        DevCommands::artisan('schedule:work', 'schedule');

        // Nessuna pipeline frontend: Filament serve asset gia' compilati e il
        // progetto non ha node_modules, quindi `npm run dev` fallirebbe.
        DevCommands::except('vite');
    }
}
