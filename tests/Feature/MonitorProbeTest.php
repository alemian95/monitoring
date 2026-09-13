<?php

use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use App\Support\MonitorProbe;
use App\Support\ProbeResult;
use Illuminate\Support\Facades\Http;

it('passa quando lo status HTTP è quello atteso', function () {
    Http::fake(['https://example.test/*' => Http::response('ok', 200)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => [200],
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('lancia quando lo status HTTP non è quello atteso', function () {
    Http::fake(['https://example.test/*' => Http::response('boom', 500)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => [200],
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class, 'HTTP 500');

it('rispetta uno status atteso diverso da 200', function () {
    Http::fake(['https://example.test/*' => Http::response('', 301)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => [301],
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('accetta uno qualsiasi degli status attesi', function () {
    Http::fake(['https://example.test/*' => Http::response('', 301)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => [200, 301],
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('lancia quando lo status non e in lista, elencando quelli attesi', function () {
    Http::fake(['https://example.test/*' => Http::response('', 500)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => [200, 204],
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class, 'HTTP 500, attesi 200, 204');

it('confronta gli status come interi anche quando il form li salva come stringhe', function () {
    Http::fake(['https://example.test/*' => Http::response('', 204)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => ['200', '204'],
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('senza status attesi ricade sul 200', function () {
    Http::fake(['https://example.test/*' => Http::response('', 200)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => null,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('passa quando il corpo contiene il testo atteso', function () {
    Http::fake(['https://example.test/*' => Http::response('<h1>Benvenuto</h1>', 200)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_body_contains' => 'Benvenuto',
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('lancia su un 200 che non contiene il testo atteso', function () {
    Http::fake(['https://example.test/*' => Http::response('Database connection failed', 200)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_body_contains' => 'Benvenuto',
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class, 'Corpo della risposta senza «Benvenuto»');

it('non guarda il corpo quando il testo atteso non e configurato', function () {
    Http::fake(['https://example.test/*' => Http::response('qualunque cosa', 200)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_body_contains' => null,
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

it('riporta status e tempo di risposta del check riuscito', function () {
    Http::fake(['https://example.test/*' => Http::response('ok', 204)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => [204],
    ]);

    $result = app(MonitorProbe::class)->check($monitor);

    expect($result)->toBeInstanceOf(ProbeResult::class)
        ->and($result->statusCode)->toBe(204)
        ->and($result->responseTimeMs)->toBeGreaterThanOrEqual(0);
});

it('riporta il tempo di connessione TCP, senza status', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) explode(':', stream_socket_get_name($server, false))[1];

    $monitor = Monitor::factory()->tcp()->create(['target' => '127.0.0.1', 'port' => $port]);

    try {
        $result = app(MonitorProbe::class)->check($monitor);
    } finally {
        fclose($server);
    }

    expect($result->statusCode)->toBeNull()
        ->and($result->responseTimeMs)->toBeGreaterThanOrEqual(0);
});

it('lancia quando la risposta supera il tempo massimo configurato', function () {
    Http::fake(function () {
        usleep(30_000);

        return Http::response('ok', 200);
    });

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'max_response_time_ms' => 1,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class, 'oltre il limite di 1 ms');

it('non guarda il tempo quando la soglia non e configurata', function () {
    Http::fake(function () {
        usleep(30_000);

        return Http::response('ok', 200);
    });

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'max_response_time_ms' => null,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('porta status e tempo dentro l eccezione, non solo nel messaggio', function () {
    Http::fake(['https://example.test/*' => Http::response('boom', 503)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_statuses' => [200],
    ]);

    try {
        app(MonitorProbe::class)->check($monitor);
    } catch (MonitorCheckFailed $exception) {
        expect($exception->statusCode)->toBe(503)
            ->and($exception->responseTimeMs)->toBeGreaterThanOrEqual(0);

        return;
    }

    $this->fail('il probe non ha lanciato');
});
