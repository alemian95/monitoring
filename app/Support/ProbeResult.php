<?php

namespace App\Support;

/**
 * Cosa il probe ha osservato quando il check e' andato a buon fine.
 *
 * Il tempo e' nullable perche' un push monitor non ne ha uno: non abbiamo
 * contattato niente, abbiamo solo guardato l'orologio.
 *
 * Il gemello sul ramo di fallimento e' `MonitorCheckFailed`, che porta gli
 * stessi due valori: cosi' un 500 finisce nello storico *come 500*, e non come
 * un booleano piu' una stringa da parsare.
 */
final readonly class ProbeResult
{
    public function __construct(
        public ?int $responseTimeMs,
        public ?int $statusCode = null,
    ) {}
}
