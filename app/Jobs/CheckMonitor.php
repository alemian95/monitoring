<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Support\MonitorProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
     */
    public function failed(?Throwable $exception): void
    {
        $wasUp = $this->monitor->is_up !== false;

        $this->monitor->update([
            'is_up' => false,
            'last_failure_reason' => $exception?->getMessage(),
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
        ]);

        if ($wasUp) {
            DiscordAlert::message(
                "🔴 **{$this->monitor->name}** è giù — {$this->monitor->target}".PHP_EOL.$exception?->getMessage()
            );
        }
    }
}
