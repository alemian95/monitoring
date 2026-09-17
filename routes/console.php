<?php

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Support\Heartbeat;
use Illuminate\Support\Facades\Schedule;

// Il ping a ogni giro riuscito sposta fuori di qui l'allarme "il monitoring e'
// morto", dove puo' ancora partire. Dettagli in `Heartbeat`.
Schedule::call(function (): void {
    Monitor::due()->each(fn (Monitor $monitor) => CheckMonitor::dispatch($monitor));
})
    ->everyMinute()
    ->name('dispatch-monitor-checks')
    ->withoutOverlapping(2)
    ->onSuccess(fn () => app(Heartbeat::class)->ok());

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
// un minuto alla volta.
//
// `--stop-when-empty-for=25` e non `--stop-when-empty`: quest'ultimo esce appena
// non c'e' un job *disponibile*, e un tentativo in backoff ha `available_at` nel
// futuro. Il worker si spegneva in mezzo alla ritenta e il tentativo successivo
// slittava al minuto dopo, spalmando i quattro tentativi su ~4 minuti. Con 25
// secondi — piu' del backoff massimo — la catena resta dentro un solo giro e
// l'alert torna a partire entro ~35 secondi dal primo errore.
Schedule::command('queue:work --stop-when-empty-for=25 --max-time=55 --tries=3')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping(2);
