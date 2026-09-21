<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum UptimeRange: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Ultima ora',
            self::Day => 'Ultime 24 ore',
            self::Week => 'Ultimi 7 giorni',
            self::Month => 'Ultimi 30 giorni',
            self::Quarter => 'Ultimi 3 mesi',
            self::Year => 'Ultimo anno',
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
            self::Quarter => now()->subDays(89)->startOfDay(),
            // Mesi solari e non 365 giorni: un bucket mensile che comincia a
            // meta' settembre non e' settembre, ed e' quello che l'etichetta
            // direbbe.
            self::Year => now()->subMonths(11)->startOfMonth(),
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
            self::Week, self::Month, self::Quarter => [1, 'day'],
            self::Year => [1, 'month'],
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
            self::Week, self::Month, self::Quarter => '%Y-%m-%d',
            self::Year => '%Y-%m',
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
            self::Week, self::Month, self::Quarter => 'Y-m-d',
            self::Year => 'Y-m',
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
            self::Month, self::Quarter => 'd/m',
            // Numerico e non 'M Y': il nome del mese seguirebbe il locale
            // dell'applicazione, che e' inglese.
            self::Year => 'm/Y',
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
