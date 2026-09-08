<?php

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Illuminate\Support\Facades\Queue;

it('dispatcha un job per ogni monitor scaduto e nessuno per gli altri', function () {
    Queue::fake();

    $due = Monitor::factory()->create(['next_check_at' => now()->subMinute()]);
    $notDue = Monitor::factory()->create(['next_check_at' => now()->addHour()]);
    $paused = Monitor::factory()->create(['is_active' => false, 'next_check_at' => null]);

    $this->artisan('schedule:test --name=dispatch-monitor-checks')->assertSuccessful();

    Queue::assertPushed(CheckMonitor::class, 1);
    Queue::assertPushed(CheckMonitor::class, fn (CheckMonitor $job) => $job->monitor->is($due));
});
