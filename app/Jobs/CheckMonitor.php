<?php

namespace App\Jobs;

use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use App\Support\AlertChannel;
use App\Support\MonitorProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CheckMonitor implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Ogni quanto ripetere l'alert finché il target resta giù.
     *
     * ponytail: costante e non colonna per-monitor — la cadenza dei promemoria
     * è una policy di chi riceve gli alert, non una proprietà del target. Se
     * servirà differenziarla, diventa una colonna.
     */
    private const REALERT_AFTER_MINUTES = 60;

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
        $result = $probe->check($this->monitor);

        $wasDown = $this->monitor->is_up === false;

        $this->monitor->update([
            'is_up' => true,
            'last_failure_reason' => null,
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
        ]);

        $this->monitor->recordCheck(
            true,
            statusCode: $result->statusCode,
            responseTimeMs: $result->responseTimeMs,
        );

        if ($wasDown) {
            // Il recovery chiude la finestra dei promemoria: la prossima caduta
            // deve allertare subito, non aspettare la scadenza di questa chiave.
            Cache::forget($this->alertKey());

            $this->alert("✅ **{$this->monitor->name}** è tornato su — {$this->monitor->target}");
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

        $this->monitor->update([
            'is_up' => false,
            'last_failure_reason' => $exception->getMessage(),
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
        ]);

        $this->monitor->recordCheck(
            false,
            statusCode: $exception instanceof MonitorCheckFailed ? $exception->statusCode : null,
            responseTimeMs: $exception instanceof MonitorCheckFailed ? $exception->responseTimeMs : null,
            failureReason: $exception->getMessage(),
        );

        // Primo alert e promemoria sono la stessa riga: `add` scrive solo se la
        // chiave non c'è, e la chiave scade da sola. Così un target caduto alle
        // tre di notte continua a farsi sentire, senza una colonna in più da
        // tenere allineata allo stato.
        if (Cache::add($this->alertKey(), true, now()->addMinutes(self::REALERT_AFTER_MINUTES))) {
            $this->alert(
                "🔴 **{$this->monitor->name}** è giù — {$this->monitor->target}".PHP_EOL.$exception->getMessage()
            );
        }
    }

    private function alertKey(): string
    {
        return "monitor-down:{$this->monitor->id}";
    }

    /**
     * Risolto qui e non iniettato nel costruttore: il job viene serializzato in
     * coda, e `failed()` non riceve dipendenze.
     */
    private function alert(string $message): void
    {
        app(AlertChannel::class)->send($message);
    }
}
