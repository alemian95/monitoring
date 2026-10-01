<?php

namespace App\Enums;

enum SmtpSecurity: string
{
    /** In chiaro, tipicamente la 25 fra server. */
    case None = 'none';

    /** Si parte in chiaro e si passa a TLS: la 587. */
    case Starttls = 'starttls';

    /** TLS dal primo byte: la 465. */
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
