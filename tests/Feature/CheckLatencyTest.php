<?php

use App\Jobs\SendDiscordAlert;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['discord-alerts.webhook_urls.default' => 'https://discord.com/api/webhooks/000/test']);
});

/**
 * Tempi di risposta tutti uguali: il p95 di una lista costante e' quella
 * costante, cosi' il test parla della soglia e non dell'aritmetica.
 */
function seedChecks(Monitor $monitor, int $count, int $responseTimeMs, Carbon $endingAt): void
{
    MonitorCheck::insert(array_map(fn (int $index): array => [
        'monitor_id' => $monitor->id,
        'is_up' => true,
        'response_time_ms' => $responseTimeMs,
        'checked_at' => $endingAt->copy()->subSeconds($index)->toDateTimeString(),
    ], range(0, $count - 1)));
}

function monitorWithBaseline(int $baselineMs = 100): Monitor
{
    $monitor = Monitor::factory()->create(['is_up' => true]);

    seedChecks($monitor, 120, $baselineMs, now()->subDay());

    return $monitor;
}

it('allerta quando il p95 dell ultima ora tripla la settimana precedente', function () {
    $monitor = monitorWithBaseline();
    seedChecks($monitor, 20, 600, now()->subMinutes(30));

    test()->artisan('monitor:latency')->assertSuccessful();

    Queue::assertPushed(SendDiscordAlert::class, 1);
    expect($monitor->fresh()->is_up)->toBeTrue();
});

it('non allerta quando il rallentamento resta sotto il moltiplicatore', function () {
    $monitor = monitorWithBaseline();
    seedChecks($monitor, 20, 250, now()->subMinutes(30));

    test()->artisan('monitor:latency')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('non allerta su un target veloce, dove il triplo sono pochi millisecondi', function () {
    $monitor = monitorWithBaseline(baselineMs: 30);
    seedChecks($monitor, 20, 150, now()->subMinutes(30));

    test()->artisan('monitor:latency')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('tace finche la settimana precedente non ha campioni a sufficienza', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);
    seedChecks($monitor, 50, 100, now()->subDay());
    seedChecks($monitor, 20, 600, now()->subMinutes(30));

    test()->artisan('monitor:latency')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('tace finche l ultima ora non ha campioni a sufficienza', function () {
    $monitor = monitorWithBaseline();
    seedChecks($monitor, 5, 600, now()->subMinutes(30));

    test()->artisan('monitor:latency')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('non ripete l alert a ogni giro', function () {
    $monitor = monitorWithBaseline();
    seedChecks($monitor, 20, 600, now()->subMinutes(30));

    test()->artisan('monitor:latency')->assertSuccessful();
    test()->artisan('monitor:latency')->assertSuccessful();

    Queue::assertPushed(SendDiscordAlert::class, 1);
});

it('riapre la finestra degli alert appena i tempi rientrano', function () {
    $monitor = monitorWithBaseline();
    seedChecks($monitor, 20, 100, now()->subMinutes(30));
    Cache::put("monitor-slow:{$monitor->id}", true, now()->addHours(6));

    test()->artisan('monitor:latency')->assertSuccessful();

    expect(Cache::has("monitor-slow:{$monitor->id}"))->toBeFalse();
    Queue::assertNothingPushed();
});

it('non parla di lentezza per un target gia giu', function () {
    $monitor = monitorWithBaseline();
    seedChecks($monitor, 20, 600, now()->subMinutes(30));
    $monitor->update(['is_up' => false]);

    test()->artisan('monitor:latency')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('ignora i monitor in pausa', function () {
    $monitor = monitorWithBaseline();
    seedChecks($monitor, 20, 600, now()->subMinutes(30));
    $monitor->update(['is_active' => false]);

    test()->artisan('monitor:latency')->assertSuccessful();

    Queue::assertNothingPushed();
});
