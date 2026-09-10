<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Spatie\DiscordAlerts\Jobs\SendToDiscordChannelJob;

/**
 * Come il job del pacchetto, ma con `->throw()` sulla richiesta.
 *
 * `SendToDiscordChannelJob` chiama `Http::post()` senza `->throw()`, quindi un
 * webhook sbagliato, revocato o rate-limited fa risultare il job completato per
 * sempre: nessuna riga in `failed_jobs`, nessun errore nei log, nessuna
 * differenza osservabile da una consegna riuscita. In un sistema di alerting
 * quello è il fallimento peggiore possibile — l'unico segnale di "tutto bene"
 * e' il silenzio, ed e' esattamente ciò che produce anche un canale rotto.
 *
 * Con `->throw()` un rifiuto di Discord diventa un job fallito, con i suoi
 * retry e la sua riga in `failed_jobs`, dove finisce già tutto il resto.
 */
class SendDiscordAlert extends SendToDiscordChannelJob
{
    public function handle(): void
    {
        $payload = [
            'content' => $this->text,
            'tts' => $this->tts,
        ];

        if (! empty($this->username)) {
            $payload['username'] = $this->username;
        }

        if (! empty($this->avatar_url)) {
            $payload['avatar_url'] = $this->avatar_url;
        }

        if (! empty($this->embeds)) {
            $payload['embeds'] = $this->embeds;
        }

        if (empty($this->attachments)) {
            Http::throw()->post($this->webhookUrl, $payload);

            return;
        }

        $request = Http::asMultipart()->throw();

        foreach (array_values($this->attachments) as $index => $attachment) {
            $request->attach("files[{$index}]", $attachment->contents(), $attachment->name, $attachment->headers());
        }

        $request->post($this->webhookUrl, [
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
