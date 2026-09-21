<?php

namespace App\Support;

use App\Enums\DnsRecordType;

/**
 * Interroga il DNS.
 *
 * Una classe per una riga sola perche' `dns_get_record()` e' una funzione
 * globale che parla con il resolver di sistema: senza questo involucro il ramo
 * DNS del probe sarebbe verificabile solo con una query vera, e quindi solo
 * finche' la rete collabora.
 */
class DnsResolver
{
    /**
     * I record trovati, lista vuota se non ce ne sono o se la query fallisce.
     *
     * @return list<array<string, mixed>>
     */
    public function records(string $host, DnsRecordType $type): array
    {
        return @dns_get_record($host, $type->flag()) ?: [];
    }
}
