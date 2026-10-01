<?php

namespace App\Enums;

/**
 * Come parlare con un server di posta. La porta non basta a dedurlo: una 587
 * senza STARTTLS e' un server configurato male, ed e' cio' che il check deve
 * saper vedere.
 */
enum TlsMode: string
{
    /** In chiaro: la 25 fra server, la 143 e la 110 senza cifratura. */
    case None = 'none';

    /** Si parte in chiaro e si passa a TLS: 587, 143, 110. */
    case Starttls = 'starttls';

    /** TLS dal primo byte: 465, 993, 995. */
    case Tls = 'tls';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Nessuna',
            self::Starttls => 'STARTTLS',
            self::Tls => 'TLS implicito',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
