<?php

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Monitor::due()->each(fn (Monitor $monitor) => CheckMonitor::dispatch($monitor));
})->everyMinute()->name('dispatch-monitor-checks')->withoutOverlapping(2);

Schedule::command('queue:prune-failed --hours=168')->daily();

// Retention dello storico dei check: 30 giorni. A intervallo di un minuto sono
// ~1.440 righe al giorno per target, quindi senza potatura la tabella cresce
// senza limite.
Schedule::call(function (): void {
    MonitorCheck::where('checked_at', '<', now()->subDays(30))->delete();
})->daily()->name('prune-monitor-checks');
