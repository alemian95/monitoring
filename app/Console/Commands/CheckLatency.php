<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Support\AlertChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Avvisa quando un target rallenta rispetto alla propria normalita'.
 *
 * `max_response_time_ms` e' un numero scelto a mano: stretto fa rumore, largo
 * non scatta mai. Qui la soglia la scrive il target stesso — quello che ha
 * fatto nell'ultima settimana — e il confronto e' con l'ultima ora.
 *
 * E' un segnale accanto alla soglia, non al suo posto: non tocca `is_up`,
 * perche' un target lento non e' un target giu' e trattarlo come tale
 * falserebbe l'uptime.
 */
class CheckLatency extends Command
{
    protected $signature = 'monitor:latency';

    protected $description = 'Avvisa quando un target rallenta rispetto alla sua normalita';

    private const PERCENTILE = 0.95;

    private const WINDOW_HOURS = 1;

    private const BASELINE_DAYS = 7;

    /** Quante volte la normalita' deve essere superata perche' sia un segnale. */
    private const MULTIPLIER = 3;

    /**
     * Il solo moltiplicatore non basta: su un target da 30 ms il triplo sono
     * 90 ms, che non se ne accorge nessuno. Serve anche uno scarto assoluto,
     * o la baseline diventa la fonte di rumore che doveva eliminare.
     */
    private const MIN_DEGRADATION_MS = 250;

    /**
     * ponytail: soglie fisse sui campioni, non proporzionali all'intervallo.
     * Un monitor controllato ogni ora non raggiunge i dieci campioni orari e
     * resta senza questo segnale — ha comunque la soglia fissa. Upgrade path:
     * una finestra che scala con `interval_minutes`.
     */
    private const MIN_WINDOW_SAMPLES = 10;

    private const MIN_BASELINE_SAMPLES = 100;

    private const REALERT_AFTER_HOURS = 6;

    public function handle(AlertChannel $alerts): int
    {
        Monitor::query()
            ->where('is_active', true)
            ->each(fn (Monitor $monitor) => $this->checkLatency($monitor, $alerts));

        return self::SUCCESS;
    }

    private function checkLatency(Monitor $monitor, AlertChannel $alerts): void
    {
        // Un target gia' giu' sta gia' allertando: dire che e' anche lento
        // aggiunge un messaggio e nessuna informazione.
        if ($monitor->is_up === false) {
            return;
        }

        $windowStart = now()->subHours(self::WINDOW_HOURS);

        $current = $monitor->responseTimePercentile(
            self::PERCENTILE,
            $windowStart,
            minimumSamples: self::MIN_WINDOW_SAMPLES,
        );

        $baseline = $monitor->responseTimePercentile(
            self::PERCENTILE,
            now()->subDays(self::BASELINE_DAYS),
            $windowStart,
            self::MIN_BASELINE_SAMPLES,
        );

        if ($current === null || $baseline === null) {
            return;
        }

        $key = "monitor-slow:{$monitor->id}";

        if ($current < $baseline * self::MULTIPLIER || $current - $baseline < self::MIN_DEGRADATION_MS) {
            // Tornato normale: la chiave va via subito, o un rallentamento fra
            // due ore resterebbe muto fino alla scadenza di questa.
            Cache::forget($key);

            return;
        }

        if (Cache::add($key, true, now()->addHours(self::REALERT_AFTER_HOURS))) {
            $alerts->send(
                "🐢 **{$monitor->name}** sta rallentando — p95 di {$current} ms nell'ultima ora, "
                ."contro i {$baseline} ms della settimana precedente."
            );
        }
    }
}
