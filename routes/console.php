<?php

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

// Dead man's switch: se lo scheduler — o l'intero server — muore, non arriva
// piu' niente, e il silenzio e' indistinguibile da "tutto ok". Il ping a ogni
// giro sposta quell'allarme fuori di qui, dove puo' ancora partire.
//
// Senza URL configurato non si pinga e non si rompe nulla: resta il monitoring
// di sempre, solo senza guardiano.
Schedule::call(function (): void {
    Monitor::due()->each(fn (Monitor $monitor) => CheckMonitor::dispatch($monitor));
})
    ->everyMinute()
    ->name('dispatch-monitor-checks')
    ->withoutOverlapping(2)
    ->onSuccess(function (): void {
        if (filled($url = config('services.healthchecks.ping_url'))) {
            // `rescue` perche' il guardiano irraggiungibile non deve diventare
            // lui il guasto: l'errore va segnalato, non propagato addosso a un
            // giro di check gia' andato a buon fine.
            rescue(fn () => Http::get($url));
        }
    });

// Un certificato cambia una volta ogni tre mesi: una lettura al giorno basta,
// e il comando resta lanciabile a mano quando serve.
Schedule::command('monitor:certificates')->daily();

Schedule::command('queue:prune-failed --hours=168')->daily();

// Retention dello storico dei check: 30 giorni. A intervallo di un minuto sono
// ~1.440 righe al giorno per target, quindi senza potatura la tabella cresce
// senza limite.
Schedule::call(function (): void {
    MonitorCheck::where('checked_at', '<', now()->subDays(30))->delete();
})->daily()->name('prune-monitor-checks');

// In produzione non gira un supervisor: il worker lo avvia lo scheduler stesso,
// un minuto alla volta, e si spegne appena la coda e' vuota.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping(2);
