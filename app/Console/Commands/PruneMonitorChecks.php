<?php

namespace App\Console\Commands;

use App\Models\MonitorCheck;
use Illuminate\Console\Command;

/**
 * Pota lo storico dei check oltre la retention configurata.
 *
 * A un check al minuto sono ~1.440 righe al giorno per target: senza potatura
 * la tabella cresce senza limite, e con una retention troppo corta i grafici
 * sulle finestre lunghe mostrano buchi che non sono disservizi.
 */
class PruneMonitorChecks extends Command
{
    protected $signature = 'monitor:prune-checks';

    protected $description = 'Cancella lo storico dei check oltre la retention configurata';

    public function handle(): int
    {
        $days = (int) config('monitoring.retention_days');

        $deleted = MonitorCheck::where('checked_at', '<', now()->subDays($days))->delete();

        $this->info("Cancellate {$deleted} righe oltre i {$days} giorni.");

        return self::SUCCESS;
    }
}
