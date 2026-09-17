<?php

use App\Jobs\SendDiscordAlert;
use App\Models\Monitor;
use App\Support\CertificateReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['discord-alerts.webhook_urls.default' => 'https://discord.com/api/webhooks/000/test']);
});

/**
 * @param  list<Carbon|null>  $expiries  una lettura per ogni giro del comando
 */
function fakeReader(array $expiries): void
{
    $mock = test()->mock(CertificateReader::class);

    foreach ($expiries as $expiry) {
        $mock->shouldReceive('expiresAt')->once()->andReturn($expiry);
    }
}

it('allerta quando il certificato scade entro la soglia', function () {
    $monitor = Monitor::factory()->create(['target' => 'https://example.test']);
    $expiry = now()->addDays(3)->startOfSecond();
    fakeReader([$expiry]);

    test()->artisan('monitor:certificates')->assertSuccessful();

    Queue::assertPushed(SendDiscordAlert::class, 1);

    $monitor->refresh();
    expect($monitor->certificate_expires_at->timestamp)->toBe($expiry->timestamp)
        ->and($monitor->certificate_alerted_at)->not->toBeNull();
});

it('registra la scadenza senza allertare quando è lontana', function () {
    $monitor = Monitor::factory()->create(['target' => 'https://example.test']);
    fakeReader([now()->addDays(60)->startOfSecond()]);

    test()->artisan('monitor:certificates')->assertSuccessful();

    Queue::assertNotPushed(SendDiscordAlert::class);

    $monitor->refresh();
    expect($monitor->certificate_expires_at)->not->toBeNull()
        ->and($monitor->certificate_alerted_at)->toBeNull();
});

it('allerta una volta sola per la stessa scadenza', function () {
    Monitor::factory()->create(['target' => 'https://example.test']);
    $expiry = now()->addDays(3)->startOfSecond();
    fakeReader([$expiry, $expiry]);

    test()->artisan('monitor:certificates')->assertSuccessful();
    test()->artisan('monitor:certificates')->assertSuccessful();

    Queue::assertPushed(SendDiscordAlert::class, 1);
});

it('riparte da zero quando il certificato viene rinnovato', function () {
    $monitor = Monitor::factory()->create(['target' => 'https://example.test']);
    fakeReader([now()->addDays(3)->startOfSecond(), now()->addDays(90)->startOfSecond()]);

    test()->artisan('monitor:certificates')->assertSuccessful();
    test()->artisan('monitor:certificates')->assertSuccessful();

    Queue::assertPushed(SendDiscordAlert::class, 1);
    expect($monitor->refresh()->certificate_alerted_at)->toBeNull();
});

it('non allerta quando il certificato non è leggibile', function () {
    $monitor = Monitor::factory()->create(['target' => 'https://example.test']);
    fakeReader([null]);

    test()->artisan('monitor:certificates')->assertSuccessful();

    Queue::assertNotPushed(SendDiscordAlert::class);
    expect($monitor->refresh()->certificate_expires_at)->toBeNull();
});

it('guarda solo i monitor attivi con un target https', function () {
    Monitor::factory()->create(['target' => 'http://example.test']);
    Monitor::factory()->create(['target' => 'https://pausa.test', 'is_active' => false]);
    Monitor::factory()->tcp()->create();
    fakeReader([]);

    test()->artisan('monitor:certificates')->assertSuccessful();

    Queue::assertNotPushed(SendDiscordAlert::class);
});

// Il reader vero, non il mock. Copre solo le due uscite che non richiedono un
// server TLS: il resto lo si vede in produzione, alla prima lettura.
it('il reader restituisce null su un target irraggiungibile o non valido', function () {
    $reader = new CertificateReader;

    expect($reader->expiresAt('https://127.0.0.1:1'))->toBeNull()
        ->and($reader->expiresAt('non-un-url'))->toBeNull();
});
