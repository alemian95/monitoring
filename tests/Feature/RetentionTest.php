<?php

use App\Enums\UptimeRange;
use App\Models\Monitor;
use App\Models\MonitorCheck;

function checkAt(Monitor $monitor, string $when): void
{
    $monitor->checks()->create(['is_up' => true, 'checked_at' => now()->parse($when)]);
}

it('cancella solo lo storico oltre la retention configurata', function () {
    config(['monitoring.retention_days' => 365]);
    $monitor = Monitor::factory()->create();

    checkAt($monitor, now()->subDays(400)->toDateTimeString());
    checkAt($monitor, now()->subDays(200)->toDateTimeString());
    checkAt($monitor, now()->subDay()->toDateTimeString());

    test()->artisan('monitor:prune-checks')->assertSuccessful();

    expect(MonitorCheck::count())->toBe(2);
});

it('segue la retention quando la configurazione cambia', function () {
    config(['monitoring.retention_days' => 30]);
    $monitor = Monitor::factory()->create();

    checkAt($monitor, now()->subDays(200)->toDateTimeString());
    checkAt($monitor, now()->subDay()->toDateTimeString());

    test()->artisan('monitor:prune-checks')->assertSuccessful();

    expect(MonitorCheck::count())->toBe(1);
});

it('tiene un anno di default, cosi la finestra piu lunga ha i dati', function () {
    expect(config('monitoring.retention_days'))
        ->toBeGreaterThanOrEqual(UptimeRange::Year->since()->diffInDays(now()));
});

it('allinea i bucket dell anno ai mesi solari', function () {
    $series = Monitor::factory()->create()->uptimeSeries(UptimeRange::Year);

    expect($series)->toHaveCount(12)
        ->and(array_key_first($series->all()))->toBe(now()->subMonths(11)->startOfMonth()->toDateTimeString())
        ->and(now()->parse(array_key_last($series->all()))->format('Y-m'))->toBe(now()->format('Y-m'));
});

it('copre il trimestre giorno per giorno', function () {
    expect(Monitor::factory()->create()->uptimeSeries(UptimeRange::Quarter))->toHaveCount(90);
});

it('conta l uptime del trimestre e dell anno sullo stesso storico', function () {
    $monitor = Monitor::factory()->create();

    $monitor->checks()->create(['is_up' => true, 'checked_at' => now()->subDays(200)]);
    $monitor->checks()->create(['is_up' => false, 'checked_at' => now()->subDays(200)]);
    $monitor->checks()->create(['is_up' => true, 'checked_at' => now()->subDay()]);

    expect($monitor->uptimePercentage(UptimeRange::Quarter))->toBe(100.0)
        ->and($monitor->uptimePercentage(UptimeRange::Year))->toBe(66.67);
});
