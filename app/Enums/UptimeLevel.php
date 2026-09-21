<?php

namespace App\Enums;

/**
 * Quanto sta bene un uptime, in percentuale.
 *
 * Le soglie stavano in tre posti — tabella, widget e pagina pubblica — e
 * nelle prime due erano gia' divergenti nell'unita' (0.99 contro 99). Qui
 * sono definite una volta, e chi le usa non sceglie ne' i numeri ne' i colori.
 */
enum UptimeLevel
{
    /** Nessuna misura: un intervallo senza check non e' downtime. */
    case Unknown;
    case Healthy;
    case Degraded;
    case Down;

    public static function for(?float $percentage): self
    {
        return match (true) {
            $percentage === null => self::Unknown,
            $percentage >= 99 => self::Healthy,
            $percentage >= 95 => self::Degraded,
            default => self::Down,
        };
    }

    /** Nome del colore Filament. */
    public function color(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Healthy => 'success',
            self::Degraded => 'warning',
            self::Down => 'danger',
        };
    }

    /** Classe CSS della pagina di stato. */
    public function cssClass(): string
    {
        return match ($this) {
            self::Unknown => 'none',
            self::Healthy => 'ok',
            self::Degraded => 'warn',
            self::Down => 'down',
        };
    }
}
