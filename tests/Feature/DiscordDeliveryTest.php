<?php

use App\Exceptions\MonitorCheckFailed;
use App\Jobs\CheckMonitor;
use App\Jobs\SendDiscordAlert;
use App\Models\Monitor;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\DiscordAlerts\Facades\DiscordAlert;

function webhook(): string
{
    return 'https://discord.com/api/webhooks/000/test';
}

beforeEach(function () {
    config(['discord-alerts.webhook_urls.default' => webhook()]);
});

it('fa fallire il job quando Discord rifiuta il webhook', function () {
    Http::fake([webhook() => Http::response(['message' => 'Unknown Webhook'], 404)]);

    (new SendDiscordAlert(text: 'target giù', webhookUrl: webhook()))->handle();
})->throws(RequestException::class);

it('fa fallire il job quando Discord risponde con un rate limit', function () {
    Http::fake([webhook() => Http::response(['retry_after' => 5], 429)]);

    (new SendDiscordAlert(text: 'target giù', webhookUrl: webhook()))->handle();
})->throws(RequestException::class);

it('non fa fallire il job su una consegna riuscita', function () {
    Http::fake([webhook() => Http::response('', 204)]);

    (new SendDiscordAlert(text: 'target giù', webhookUrl: webhook()))->handle();

    Http::assertSent(fn ($request): bool => $request['content'] === 'target giù');
});

it('è il job che il pacchetto dispatcha davvero', function () {
    // Se questa asserzione cade, gli alert tornano a fallire in silenzio:
    // il job del pacchetto non chiama ->throw().
    expect(config('discord-alerts.job'))->toBe(SendDiscordAlert::class);

    Http::fake([webhook() => Http::response('', 204)]);
    Queue::fake();

    DiscordAlert::message('prova');

    Queue::assertPushed(SendDiscordAlert::class);
});

it('con la connection sync un webhook rotto risale nel job del monitor', function () {
    // Fissa il motivo per cui `discord-alerts.queue_connection` non va messo a
    // `sync`: in produzione (connection `database`) l'alert viene accodato e
    // fallisce per conto suo, lasciando la sua riga in `failed_jobs` senza
    // toccare il job del monitor.
    Http::fake([webhook() => Http::response(['message' => 'Unknown Webhook'], 404)]);
    config(['discord-alerts.queue_connection' => 'sync']);

    $monitor = Monitor::factory()->create(['is_up' => true]);

    expect(fn () => (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('HTTP 500')))
        ->toThrow(RequestException::class);

    // Lo stato resta comunque coerente: l'update precede sempre l'invio.
    expect($monitor->refresh()->is_up)->toBeFalse();
});

it('accodato e non eseguito, un alert non fa fallire il job del monitor', function () {
    Queue::fake();
    $monitor = Monitor::factory()->create(['is_up' => true]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('HTTP 500'));

    Queue::assertPushed(SendDiscordAlert::class);
    expect($monitor->refresh()->is_up)->toBeFalse();
});
