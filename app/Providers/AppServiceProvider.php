<?php

namespace App\Providers;

use App\Support\Heartbeat;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Queue;
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

        // Un job fallito e' il punto cieco del sistema: un `SendDiscordAlert`
        // che non riesce a consegnare finisce in `failed_jobs` e li' resta,
        // perche' l'alert che dice "l'alert non e' partito" non puo' passare
        // per Discord. Il segnale esce dall'unico canale che non dipende da
        // questa macchina.
        Queue::failing(fn () => app(Heartbeat::class)->failed());

        // Nessuna pipeline frontend: Filament serve asset gia' compilati e il
        // progetto non ha node_modules, quindi `npm run dev` fallirebbe.
        DevCommands::except('vite');
    }
}
