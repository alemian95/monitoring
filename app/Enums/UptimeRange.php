<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum UptimeRange: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Ultima ora',
            self::Day => 'Ultime 24 ore',
            self::Week => 'Ultimi 7 giorni',
            self::Month => 'Ultimi 30 giorni',
        };
    }

    /**
     * Inizio della finestra, allineato al bucket cosi' che il primo intervallo
     * non risulti troncato.
     */
    public function since(): Carbon
    {
        return match ($this) {
            self::Hour => now()->subMinutes(59)->startOfMinute(),
            self::Day => now()->subHours(23)->startOfHour(),
            self::Week => now()->subDays(6)->startOfDay(),
            self::Month => now()->subDays(29)->startOfDay(),
        };
    }

    /**
     * Ampiezza di un bucket, come argomenti di `Carbon::add()`.
     *
     * @return array{int, string}
     */
    public function bucket(): array
    {
        return match ($this) {
            self::Hour => [1, 'minute'],
            self::Day => [1, 'hour'],
            self::Week, self::Month => [1, 'day'],
        };
    }

    /**
     * Formato `strftime` con cui il database raggruppa le righe nel bucket.
     *
     * ponytail: sintassi SQLite. Su MySQL o Postgres va tradotto in DATE_FORMAT
     * o to_char, e questo e' l'unico punto da toccare.
     */
    public function sqlFormat(): string
    {
        return match ($this) {
            self::Hour => '%Y-%m-%d %H:%M',
            self::Day => '%Y-%m-%d %H',
            self::Week, self::Month => '%Y-%m-%d',
        };
    }

    /**
     * Formato Carbon equivalente a `sqlFormat()`, per far combaciare le chiavi
     * generate lato PHP con i gruppi restituiti dal database.
     */
    public function carbonFormat(): string
    {
        return match ($this) {
            self::Hour => 'Y-m-d H:i',
            self::Day => 'Y-m-d H',
            self::Week, self::Month => 'Y-m-d',
        };
    }

    /**
     * Formato dell'etichetta sull'asse X.
     */
    public function labelFormat(): string
    {
        return match ($this) {
            self::Hour => 'H:i',
            self::Day => 'H:00',
            self::Week => 'D d/m',
            self::Month => 'd/m',
        };
    }

    /**
     * Se collegare i punti attraverso i bucket vuoti.
     *
     * Sull'ora i bucket sono da un minuto mentre i check arrivano ogni uno o
     * due: i vuoti sono un artefatto della granularita', non dati mancanti, e
     * lasciarli spezzerebbe la linea senza dire nulla di vero. Sulle finestre
     * piu' larghe un bucket vuoto significa un'ora o un giorno interi senza
     * alcun check — un buco reale, che va visto.
     */
    public function connectsGaps(): bool
    {
        return $this === self::Hour;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $range): array => [$range->value => $range->label()])
            ->all();
    }
}
