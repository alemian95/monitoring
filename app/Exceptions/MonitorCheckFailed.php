<?php

namespace App\Exceptions;

use RuntimeException;

class MonitorCheckFailed extends RuntimeException
{
    /**
     * Status e tempo viaggiano con l'eccezione perche' `CheckMonitor::failed()`
     * riceve solo questa: senza, lo storico di un fallimento resterebbe muto su
     * cosa il target avesse effettivamente risposto.
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?int $responseTimeMs = null,
    ) {
        parent::__construct($message);
    }
}
