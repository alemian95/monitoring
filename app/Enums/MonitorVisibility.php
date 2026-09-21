<?php

namespace App\Enums;

use Illuminate\Http\Request;

/**
 * Chi puo' vedere lo stato di un servizio fuori dal pannello.
 *
 * La regola d'accesso vive qui e non nella rotta: e' una proprieta' del
 * servizio, e la pagina, il pannello e i test devono leggerla dallo stesso
 * posto.
 */
enum MonitorVisibility: string
{
    /** Solo dal pannello: la pagina pubblica non ammette che esista. */
    case Private = 'private';

    /** In chiaro, ed elencato nell'indice. */
    case Public = 'public';

    /** Raggiungibile solo con un link firmato, e mai elencato. */
    case Signed = 'signed';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Solo dal pannello',
            self::Public => 'Pubblica',
            self::Signed => 'Solo con link firmato',
        };
    }

    /**
     * Se il servizio compare nell'indice di `/status`.
     */
    public function isListed(): bool
    {
        return $this === self::Public;
    }

    /**
     * Lo status HTTP da restituire quando la richiesta non puo' vedere la
     * pagina, o `null` se puo'.
     *
     * 404 e non 403 per i servizi privati: uno che non hai pubblicato non deve
     * nemmeno confermare di esistere. 403 invece per una firma assente o
     * scaduta, perche' li' il link e' legittimo ma vecchio, e chi lo apre deve
     * capire che e' quello il problema.
     */
    public function denialStatus(Request $request): ?int
    {
        return match ($this) {
            self::Private => 404,
            self::Public => null,
            self::Signed => $request->hasValidSignature() ? null : 403,
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
