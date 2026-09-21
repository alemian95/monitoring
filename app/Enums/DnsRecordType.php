<?php

namespace App\Enums;

/**
 * I tipi di record che il probe sa interrogare.
 *
 * La lista sta qui e non nel form perche' e' la stessa che il probe traduce in
 * costante `DNS_*`: tenerla in due posti vorrebbe dire poter scegliere dal
 * pannello un tipo che il probe non sa risolvere.
 */
enum DnsRecordType: string
{
    case A = 'A';
    case Aaaa = 'AAAA';
    case Cname = 'CNAME';
    case Mx = 'MX';
    case Txt = 'TXT';
    case Ns = 'NS';

    /** La costante `DNS_*` corrispondente, da passare a `dns_get_record()`. */
    public function flag(): int
    {
        return match ($this) {
            self::A => DNS_A,
            self::Aaaa => DNS_AAAA,
            self::Cname => DNS_CNAME,
            self::Mx => DNS_MX,
            self::Txt => DNS_TXT,
            self::Ns => DNS_NS,
        };
    }
}
