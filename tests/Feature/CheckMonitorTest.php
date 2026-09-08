<?php

use App\Exceptions\MonitorCheckFailed;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Support\MonitorProbe;
use Illuminate\Support\Facades\Queue;
use Spatie\DiscordAlerts\Jobs\SendToDiscordChannelJob;

beforeEach(function () {
    Queue::fake();

    // `DiscordAlert::message()` resolves the webhook URL before dispatching
    // the job, even with Queue::fake() active — it needs a syntactically
    // valid URL configured, or it throws before the fake queue ever sees
    // the job. No real HTTP call is made; SendToDiscordChannelJob is faked.
    config(['discord-alerts.webhook_urls.default' => 'https://discord.com/api/webhooks/000/test']);
});

it('segna il monitor come su e programma il prossimo check', function () {
    $monitor = Monitor::factory()->create(['is_up' => null, 'interval_minutes' => 5]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once();

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    $monitor->refresh();
    expect($monitor->is_up)->toBeTrue()
        ->and($monitor->last_checked_at)->not->toBeNull()
        ->and($monitor->next_check_at->timestamp)->toBeGreaterThan(now()->addMinutes(4)->timestamp);
});

it('non manda nulla su Discord quando il monitor era già su', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once();

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    Queue::assertNotPushed(SendToDiscordChannelJob::class);
});

it('manda il recovery quando un monitor giù torna su', function () {
    $monitor = Monitor::factory()->create(['is_up' => false]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once();

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    Queue::assertPushed(SendToDiscordChannelJob::class);
    expect($monitor->refresh()->is_up)->toBeTrue();
});

it('manda l alert e segna giù al fallimento definitivo', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('HTTP 500, atteso 200'));

    Queue::assertPushed(SendToDiscordChannelJob::class);

    $monitor->refresh();
    expect($monitor->is_up)->toBeFalse()
        ->and($monitor->last_failure_reason)->toBe('HTTP 500, atteso 200');
});

it('non ri-allerta un monitor già noto come giù', function () {
    $monitor = Monitor::factory()->create(['is_up' => false]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('ancora giù'));

    Queue::assertNotPushed(SendToDiscordChannelJob::class);
    expect($monitor->refresh()->last_failure_reason)->toBe('ancora giù');
});

it('programma il prossimo check anche quando fallisce', function () {
    $monitor = Monitor::factory()->create(['is_up' => true, 'next_check_at' => null]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('giù'));

    expect($monitor->refresh()->next_check_at)->not->toBeNull();
});

it('allerta al primo fallimento di un monitor mai controllato', function () {
    $monitor = Monitor::factory()->create(['is_up' => null]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('giù'));

    Queue::assertPushed(SendToDiscordChannelJob::class);
});
