<?php

use App\Enums\NotificationChannelType;
use App\Exceptions\MonitorCheckFailed;
use App\Jobs\CheckMonitor;
use App\Jobs\SendAlert;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function channel(NotificationChannelType $type, array $settings): NotificationChannel
{
    return NotificationChannel::factory()->create(['type' => $type, 'settings' => $settings]);
}

it('consegna al canale nella forma che si aspetta', function (NotificationChannelType $type, array $settings, Closure $expected) {
    Http::fake();

    (new SendAlert(channel($type, $settings), "🔴 **sito** è giù\nHTTP 500"))->handle();

    Http::assertSent($expected);
})->with([
    'discord' => [NotificationChannelType::Discord, ['webhook_url' => 'https://discord.test/hook'],
        fn (Request $request): bool => $request->url() === 'https://discord.test/hook'
            && $request['content'] === "🔴 **sito** è giù\nHTTP 500"],
    'slack, senza asterischi' => [NotificationChannelType::Slack, ['webhook_url' => 'https://slack.test/hook'],
        fn (Request $request): bool => $request['text'] === "🔴 sito è giù\nHTTP 500"],
    'teams, come adaptive card' => [NotificationChannelType::Teams, ['webhook_url' => 'https://teams.test/hook'],
        fn (Request $request): bool => $request['attachments'][0]['content']['body'][0]['text'] === "🔴 **sito** è giù\nHTTP 500"],
    'telegram' => [NotificationChannelType::Telegram, ['bot_token' => '123:abc', 'chat_id' => '42'],
        fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123:abc/sendMessage'
            && $request['chat_id'] === '42'],
    'ntfy, con token e titolo' => [NotificationChannelType::Ntfy, ['server_url' => 'https://ntfy.test/', 'topic' => 'ops', 'token' => 'tk'],
        fn (Request $request): bool => $request->url() === 'https://ntfy.test/ops'
            && $request->hasHeader('Authorization', 'Bearer tk')
            && $request->hasHeader('Title', '🔴 sito è giù')
            && $request->body() === "🔴 **sito** è giù\nHTTP 500"],
    'gotify' => [NotificationChannelType::Gotify, ['server_url' => 'https://gotify.test', 'app_token' => 'gt'],
        fn (Request $request): bool => $request->url() === 'https://gotify.test/message'
            && $request->hasHeader('X-Gotify-Key', 'gt')
            && $request['priority'] === 8],
    'pushover' => [NotificationChannelType::Pushover, ['app_token' => 'pt', 'user_key' => 'uk'],
        fn (Request $request): bool => $request['token'] === 'pt'
            && $request['user'] === 'uk'
            && $request['message'] === "🔴 sito è giù\nHTTP 500"],
]);

it('passa al webhook generico anche il monitor di cui parla', function () {
    Http::fake();
    $monitor = Monitor::factory()->create(['name' => 'sito', 'is_up' => false]);

    (new SendAlert(channel(NotificationChannelType::Webhook, ['url' => 'https://hook.test']), 'giù', $monitor))->handle();

    Http::assertSent(fn (Request $request): bool => $request['message'] === 'giù'
        && $request['monitor']['id'] === $monitor->id
        && $request['monitor']['is_up'] === false);
});

it('manda una mail con la prima riga come oggetto', function () {
    Event::fake([MessageSent::class]);

    (new SendAlert(channel(NotificationChannelType::Email, ['to' => 'ops@example.test']), "🔴 **sito** è giù\nHTTP 500"))->handle();

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => $event->message->getSubject() === '🔴 sito è giù'
        && $event->message->getTo()[0]->getAddress() === 'ops@example.test');
});

it('fa fallire il job quando il canale rifiuta la consegna', function () {
    Http::fake(['*' => Http::response(['ok' => false], 401)]);

    (new SendAlert(channel(NotificationChannelType::Telegram, ['bot_token' => 'sbagliato', 'chat_id' => '42']), 'giù'))->handle();
})->throws(RequestException::class);

it('con la connection sync un canale rotto risale nel job del monitor', function () {
    // Fissa il motivo per cui la coda non va messa a `sync`: in produzione
    // (connection `database`) l'alert viene accodato e fallisce per conto suo,
    // lasciando la sua riga in `failed_jobs` senza toccare il job del monitor.
    Http::fake(['*' => Http::response(['message' => 'Unknown Webhook'], 404)]);
    config(['queue.default' => 'sync']);

    $monitor = Monitor::factory()->create(['is_up' => true]);
    $monitor->notificationChannels()->attach(NotificationChannel::factory()->create());

    expect(fn () => (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('HTTP 500')))
        ->toThrow(RequestException::class);

    // Lo stato resta comunque coerente: l'update precede sempre l'invio.
    expect($monitor->refresh()->is_up)->toBeFalse();
});

it('accodato e non eseguito, un alert non fa fallire il job del monitor', function () {
    Queue::fake();
    $monitor = Monitor::factory()->create(['is_up' => true]);
    $monitor->notificationChannels()->attach(NotificationChannel::factory()->create());

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('HTTP 500'));

    Queue::assertPushed(SendAlert::class);
    expect($monitor->refresh()->is_up)->toBeFalse();
});
