<?php

namespace App\Jobs;

use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use App\Support\MonitorProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Throwable;

class CheckMonitor implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Un check più tre retry. */
    public int $tries = 4;

    /** Secondi tra i retry: l'alert parte a ~60 secondi dal primo fallimento. */
    public int $backoff = 20;

    /**
     * Il lock dura più della catena di retry, così lo scheduler non
     * ri-dispatcha lo stesso monitor mentre è ancora in ritenta.
     */
    public int $uniqueFor = 300;

    /**
     * Se il monitor viene cancellato mentre il job è in retry, il job va
     * scartato invece di finire in `failed_jobs`: non c'è più nulla da
     * segnare come giù.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Monitor $monitor) {}

    public function uniqueId(): string
    {
        return (string) $this->monitor->id;
    }

    public function handle(MonitorProbe $probe): void
    {
        $probe->check($this->monitor);

        $wasDown = $this->monitor->is_up === false;

        $this->monitor->update([
            'is_up' => true,
            'last_failure_reason' => null,
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
        ]);

        if ($wasDown) {
            DiscordAlert::message("✅ **{$this->monitor->name}** è tornato su — {$this->monitor->target}");
        }
    }

    /**
     * Invocato dopo l'ultimo tentativo fallito: è qui che parte l'alert.
     *
     * Solo un fallimento del probe (`MonitorCheckFailed` o
     * `ConnectionException`, quest'ultima per timeout/DNS lasciata propagare
     * dal probe) significa "il target è giù". Qualunque altra eccezione
     * terminale — lock del database, un `DiscordAlert::message()` che lancia
     * nel ramo di recovery, un timeout del worker, troppi tentativi — è un
     * problema di infrastruttura, non del target: non deve toccare `is_up`
     * né generare un falso allarme (seguito, al ciclo dopo, da un falso
     * recovery).
     */
    public function failed(?Throwable $exception): void
    {
        if (! $exception instanceof MonitorCheckFailed && ! $exception instanceof ConnectionException) {
            if ($exception) {
                report($exception);
            }

            $this->monitor->update([
                'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
            ]);

            return;
        }

        $wasUp = $this->monitor->is_up !== false;

        $this->monitor->update([
            'is_up' => false,
            'last_failure_reason' => $exception->getMessage(),
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
        ]);

        if ($wasUp) {
            DiscordAlert::message(
                "🔴 **{$this->monitor->name}** è giù — {$this->monitor->target}".PHP_EOL.$exception->getMessage()
            );
        }
    }
}
