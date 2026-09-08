<?php

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Monitor::due()->each(fn (Monitor $monitor) => CheckMonitor::dispatch($monitor));
})->everyMinute()->name('dispatch-monitor-checks')->withoutOverlapping();
