<?php

namespace App\Support;

use App\Jobs\SendAlert;
use App\Models\Monitor;
use Illuminate\Support\Facades\Log;

/**
 * L'unico punto da cui esce un alert: un job per ogni canale del monitor.
 *
 * Un job per canale e non uno per alert, cosi' un Telegram che rifiuta il token
 * fallisce e ritenta per conto suo senza ripetere il messaggio su Discord.
 *
 * Un monitor senza canali non fa fallire nulla — lo stato del monitor e' gia'
 * scritto — ma non resta muto: in un sistema d'allerta un target che nessuno
 * ascolta e' esattamente cio' che non deve passare inosservato.
 */
class AlertChannel
{
    public function send(Monitor $monitor, string $message): void
    {
        $channels = $monitor->notificationChannels;

        if ($channels->isEmpty()) {
            Log::warning('Alert non inviato: il monitor non ha canali di notifica.', [
                'monitor' => $monitor->name,
                'message' => $message,
            ]);

            return;
        }

        foreach ($channels as $channel) {
            SendAlert::dispatch($channel, $message, $monitor);
        }
    }
}
