<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Spatie\DiscordAlerts\Facades\DiscordAlert;

/**
 * L'unico punto da cui esce un alert.
 *
 * Senza webhook configurato `DiscordAlert::message()` lancerebbe
 * `WebhookDoesNotExist`, facendo fallire un chiamante il cui lavoro vero — la
 * scrittura dello stato del monitor — è già andato a buon fine. La guardia
 * evita il fallimento ma non il silenzio: un webhook mancante resta visibile
 * nei log, perché in un sistema d'allerta un canale scollegato è esattamente
 * ciò che non deve passare inosservato.
 */
class AlertChannel
{
    public function send(string $message): void
    {
        if (blank(config('discord-alerts.webhook_urls.default'))) {
            Log::warning('Alert non inviato: webhook Discord non configurato.', [
                'message' => $message,
            ]);

            return;
        }

        DiscordAlert::message($message);
    }
}
