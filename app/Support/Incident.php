<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Un disservizio, ricostruito dallo storico: una serie ininterrotta di check
 * falliti fra la prima caduta e il primo check tornato buono.
 *
 * Non ha un'identita' nel database perche' non ne ha bisogno: e' una lettura
 * di `monitor_checks`, non un'entita' a se'. Il giorno in cui si volesse
 * annotare un post-mortem servira' una tabella, perche' una nota va appesa a
 * qualcosa che resta.
 */
final readonly class Incident
{
    /** Le unita' della durata, dalla piu' grande, con singolare e plurale. */
    private const UNITS = [
        [1440, 'giorno', 'giorni'],
        [60, 'ora', 'ore'],
        [1, 'minuto', 'minuti'],
    ];

    public function __construct(
        public Carbon $startedAt,
        public ?Carbon $endedAt,
        public ?string $reason,
    ) {}

    /** Un incidente senza fine e' in corso adesso. */
    public function isOngoing(): bool
    {
        return $this->endedAt === null;
    }

    /**
     * La fine e' l'istante del primo check tornato buono, non quello in cui il
     * target e' guarito davvero: fra i due c'e' al massimo un intervallo di
     * check, ed e' l'unica cosa che abbiamo davvero osservato.
     */
    public function durationMinutes(): int
    {
        return (int) $this->startedAt->diffInMinutes($this->endedAt ?? now());
    }

    /** La durata in italiano, al massimo due unita': «2 ore e 3 minuti». */
    public function duration(): string
    {
        $remaining = $this->durationMinutes();

        if ($remaining < 1) {
            return 'meno di un minuto';
        }

        $parts = [];

        foreach (self::UNITS as [$size, $singular, $plural]) {
            $count = intdiv($remaining, $size);
            $remaining %= $size;

            if ($count > 0) {
                $parts[] = $count.' '.($count === 1 ? $singular : $plural);
            }

            if (count($parts) === 2) {
                break;
            }
        }

        return implode(' e ', $parts);
    }
}
