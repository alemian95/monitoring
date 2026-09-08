<?php

use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use App\Support\MonitorProbe;
use Illuminate\Support\Facades\Http;

it('passa quando lo status HTTP è quello atteso', function () {
    Http::fake(['https://example.test/*' => Http::response('ok', 200)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_status' => 200,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('lancia quando lo status HTTP non è quello atteso', function () {
    Http::fake(['https://example.test/*' => Http::response('boom', 500)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_status' => 200,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class, 'HTTP 500');

it('rispetta uno status atteso diverso da 200', function () {
    Http::fake(['https://example.test/*' => Http::response('', 301)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_status' => 301,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('passa quando la porta TCP accetta connessioni', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) explode(':', stream_socket_get_name($server, false))[1];

    $monitor = Monitor::factory()->tcp()->create([
        'target' => '127.0.0.1',
        'port' => $port,
    ]);

    try {
        app(MonitorProbe::class)->check($monitor);
    } finally {
        fclose($server);
    }
})->throwsNoExceptions();

it('lancia quando la porta TCP è chiusa', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) explode(':', stream_socket_get_name($server, false))[1];
    fclose($server);

    $monitor = Monitor::factory()->tcp()->create([
        'target' => '127.0.0.1',
        'port' => $port,
        'timeout_seconds' => 1,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class);
