<?php

namespace App\Enums;

use Illuminate\Http\Request;

/**
 * Chi puo' vedere lo stato di un servizio da fuori.
 *
 * Nessuna di queste modalita' mette il servizio in un elenco pubblico: fuori
 * dal pannello si raggiunge una pagina alla volta, con il suo indirizzo.
 *
 * La regola d'accesso vive qui e non nella rotta: e' una proprieta' del
 * servizio, e la pagina, il pannello e i test devono leggerla dallo stesso
 * posto.
 */
enum MonitorVisibility: string
{
    /** Solo dal pannello: la pagina pubblica non ammette che esista. */
    case Private = 'private';

    /** Raggiungibile in chiaro da chi ne conosce l'indirizzo. */
    case Public = 'public';

    /** Raggiungibile solo con un link firmato, che puo' scadere. */
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
