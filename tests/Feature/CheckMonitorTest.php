<?php

use App\Exceptions\MonitorCheckFailed;
use App\Jobs\CheckMonitor;
use App\Jobs\SendDiscordAlert;
use App\Models\Monitor;
use App\Support\MonitorProbe;
use App\Support\ProbeResult;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

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
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once()->andReturn(new ProbeResult(42, 200));

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    $monitor->refresh();
    expect($monitor->is_up)->toBeTrue()
        ->and($monitor->last_checked_at)->not->toBeNull()
        ->and($monitor->next_check_at->timestamp)->toBeGreaterThan(now()->addMinutes(4)->timestamp);
});

it('non manda nulla su Discord quando il monitor era già su', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once()->andReturn(new ProbeResult(42, 200));

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    Queue::assertNotPushed(SendDiscordAlert::class);
});

it('manda il recovery quando un monitor giù torna su', function () {
    $monitor = Monitor::factory()->create(['is_up' => false]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once()->andReturn(new ProbeResult(42, 200));

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    Queue::assertPushed(SendDiscordAlert::class);
    expect($monitor->refresh()->is_up)->toBeTrue();
});

it('manda l alert e segna giù al fallimento definitivo', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('HTTP 500, atteso 200'));

    Queue::assertPushed(SendDiscordAlert::class);

    $monitor->refresh();
    expect($monitor->is_up)->toBeFalse()
        ->and($monitor->last_failure_reason)->toBe('HTTP 500, atteso 200');
});

it('non ri-allerta un monitor già noto come giù', function () {
    $monitor = Monitor::factory()->create(['is_up' => false]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('ancora giù'));

    Queue::assertNotPushed(SendDiscordAlert::class);
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

    Queue::assertPushed(SendDiscordAlert::class);
});

it(
    "protegge il contratto di retry del design: 3 retry a 20 secondi tengono l'alert entro ~60s da un nginx reload",
    function () {
        $job = new CheckMonitor(Monitor::factory()->create());

        expect($job->tries)->toBe(4)
            ->and($job->backoff)->toBe(20);
    }
);

it('un ConnectionException del probe (timeout/DNS) allerta e segna il monitor giù, come MonitorCheckFailed', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);

    (new CheckMonitor($monitor))->failed(new ConnectionException('Connection timed out'));

    Queue::assertPushed(SendDiscordAlert::class);
    expect($monitor->refresh()->is_up)->toBeFalse();
});

it('un fallimento di infrastruttura non tocca is_up (partendo da su) e non allerta Discord', function () {
    Exceptions::fake();

    $monitor = Monitor::factory()->create(['is_up' => true, 'last_failure_reason' => null]);
    $exception = new QueryException(
        'sqlite',
        'select * from monitors',
        [],
        new PDOException('database is locked'),
    );

    (new CheckMonitor($monitor))->failed($exception);

    Queue::assertNotPushed(SendDiscordAlert::class);

    $monitor->refresh();
    expect($monitor->is_up)->toBeTrue()
        ->and($monitor->last_failure_reason)->toBeNull()
        ->and($monitor->next_check_at)->not->toBeNull();

    Exceptions::assertReported($exception::class);
});

it('un fallimento di infrastruttura non tocca is_up (partendo da mai controllato) e non allerta Discord', function () {
    Exceptions::fake();

    $monitor = Monitor::factory()->create(['is_up' => null]);

    (new CheckMonitor($monitor))->failed(new TimeoutExceededException('CheckMonitor has timed out.'));

    Queue::assertNotPushed(SendDiscordAlert::class);
    expect($monitor->refresh()->is_up)->toBeNull();
});

it('non fa fallire il job quando il webhook Discord non e configurato', function () {
    config(['discord-alerts.webhook_urls.default' => null]);
    Log::spy();
    $monitor = Monitor::factory()->create(['is_up' => true]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('HTTP 500, atteso 200'));

    Queue::assertNotPushed(SendDiscordAlert::class);
    Log::shouldHaveReceived('warning')->once();

    $monitor->refresh();
    expect($monitor->is_up)->toBeFalse()
        ->and($monitor->last_failure_reason)->toBe('HTTP 500, atteso 200');
});

it('non fa fallire il recovery quando il webhook Discord non e configurato', function () {
    config(['discord-alerts.webhook_urls.default' => null]);
    Log::spy();
    $monitor = Monitor::factory()->create(['is_up' => false]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once()->andReturn(new ProbeResult(42, 200));

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    Queue::assertNotPushed(SendDiscordAlert::class);
    Log::shouldHaveReceived('warning')->once();
    expect($monitor->refresh()->is_up)->toBeTrue();
});
