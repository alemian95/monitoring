<?php

use App\Enums\MonitorType;
use App\Models\Monitor;

it('include i monitor mai controllati', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => null]);

    expect(Monitor::due()->pluck('id'))->toContain($monitor->id);
});

it('include i monitor la cui scadenza è passata', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => now()->subMinute()]);

    expect(Monitor::due()->pluck('id'))->toContain($monitor->id);
});

it('esclude i monitor non ancora scaduti', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => now()->addMinutes(5)]);

    expect(Monitor::due()->pluck('id'))->not->toContain($monitor->id);
});

it('esclude i monitor in pausa anche se scaduti', function () {
    $monitor = Monitor::factory()->create([
        'is_active' => false,
        'next_check_at' => now()->subHour(),
    ]);

    expect(Monitor::due()->pluck('id'))->not->toContain($monitor->id);
});

it('castta il tipo a enum', function () {
    $monitor = Monitor::factory()->tcp()->create();

    expect($monitor->fresh()->type)->toBe(MonitorType::Tcp);
});
