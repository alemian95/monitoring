<?php

namespace App\Jobs;

use App\Enums\NotificationChannelType;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Consegna un alert a un canale.
 *
 * Ogni richiesta ha `->throw()`: un webhook revocato o un token sbagliato
 * devono diventare un job fallito, con la sua riga in `failed_jobs` e il ping
 * `/fail` dell'heartbeat. Una consegna fallita che risulta riuscita e' il
 * guasto peggiore di un sistema d'allerta, perche' e' identica al silenzio di
 * quando va tutto bene.
 */
class SendAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Un canale o un monitor cancellati mentre l'alert e' in coda: non c'e' piu'
     * nessuno a cui dirlo, o niente di cui parlare.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public NotificationChannel $channel,
        public string $message,
        public ?Monitor $monitor = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $settings = $this->channel->settings;
        $text = $this->channel->type->rendersMarkdown() ? $this->message : str_replace('**', '', $this->message);

        match ($this->channel->type) {
            NotificationChannelType::Discord => Http::throw()->post($settings['webhook_url'], ['content' => $text]),
            NotificationChannelType::Slack => Http::throw()->post($settings['webhook_url'], ['text' => $text]),
            NotificationChannelType::Teams => Http::throw()->post($settings['webhook_url'], $this->adaptiveCard($text)),
            NotificationChannelType::Telegram => Http::throw()->post(
                "https://api.telegram.org/bot{$settings['bot_token']}/sendMessage",
                ['chat_id' => $settings['chat_id'], 'text' => $text],
            ),
            NotificationChannelType::Webhook => Http::throw()->post($settings['url'], [
                'message' => $text,
                'monitor' => $this->monitor?->only(['id', 'name', 'target', 'is_up']),
            ]),
            NotificationChannelType::Ntfy => Http::throw()
                ->withHeaders(['Title' => $this->title($text), 'Markdown' => 'yes'])
                ->when(filled($settings['token'] ?? null), fn ($request) => $request->withToken($settings['token']))
                ->withBody($text, 'text/plain')
                ->post(rtrim($settings['server_url'], '/').'/'.$settings['topic']),
            NotificationChannelType::Gotify => Http::throw()
                ->withHeaders(['X-Gotify-Key' => $settings['app_token']])
                ->post(rtrim($settings['server_url'], '/').'/message', [
                    'title' => $this->title($text),
                    'message' => $text,
                    // Sotto 4 l'app Android non fa suonare il telefono.
                    'priority' => 8,
                    'extras' => ['client::display' => ['contentType' => 'text/markdown']],
                ]),
            NotificationChannelType::Pushover => Http::throw()->asForm()->post('https://api.pushover.net/1/messages.json', [
                'token' => $settings['app_token'],
                'user' => $settings['user_key'],
                'title' => $this->title($text),
                'message' => $text,
            ]),
            NotificationChannelType::Email => Mail::raw($text, fn (Message $mail) => $mail
                ->to($settings['to'])
                ->subject($this->title($text))),
        };
    }

    /**
     * La prima riga dell'alert, che dice gia' chi e cosa: e' l'oggetto della
     * mail e il titolo delle notifiche push.
     */
    private function title(string $text): string
    {
        return Str::limit(str_replace('**', '', Str::before($text, PHP_EOL)), 120);
    }

    /**
     * I webhook di Teams via Workflows vogliono una Adaptive Card: un testo
     * semplice viene accettato e poi non mostrato.
     *
     * @return array<string, mixed>
     */
    private function adaptiveCard(string $text): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [['type' => 'TextBlock', 'text' => $text, 'wrap' => true]],
                ],
            ]],
        ];
    }
}
