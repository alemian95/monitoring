<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Il dead man's switch: l'unico segnale che esce dal monitoring per dire che il
 * monitoring stesso e' vivo.
 *
 * Un ping a ogni giro riuscito dello scheduler, uno sull'endpoint `/fail`
 * quando un job fallisce. Se i ping smettono — scheduler morto, server giu' —
 * avvisa il servizio esterno, che e' l'unico punto della catena a non
 * dipendere da questa macchina.
 *
 * Senza URL configurato non si pinga e non si rompe nulla. Il ping e' sempre
 * `rescue`: un guardiano irraggiungibile non deve diventare lui il guasto.
 */
class Heartbeat
{
    public function ok(): void
    {
        $this->ping('');
    }

    public function failed(): void
    {
        $this->ping('/fail');
    }

    private function ping(string $suffix): void
    {
        $url = config('services.healthchecks.ping_url');

        if (blank($url)) {
            return;
        }

        rescue(fn () => Http::get(rtrim((string) $url, '/').$suffix));
    }
}
