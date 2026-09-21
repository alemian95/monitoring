<?php

use App\Enums\DnsRecordType;
use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use App\Support\DnsResolver;
use App\Support\MonitorProbe;
use App\Support\ProbeResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * @param  list<array<string, mixed>>  $records
 */
function fakeResolver(array $records): void
{
    test()->mock(DnsResolver::class)->shouldReceive('records')->andReturn($records);
}

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

it('usa metodo, header e corpo configurati', function () {
    Http::fake(['https://example.test/*' => Http::response('', 200)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/webhook',
        'http_method' => 'POST',
        'http_headers' => ['Authorization' => 'Bearer segreto'],
        'http_body' => '{"ping":true}',
    ]);

    app(MonitorProbe::class)->check($monitor);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer segreto')
        && $request->body() === '{"ping":true}');
});

it('cifra gli header a riposo', function () {
    $monitor = Monitor::factory()->create(['http_headers' => ['Authorization' => 'Bearer segreto']]);

    $stored = (string) DB::table('monitors')->where('id', $monitor->id)->value('http_headers');

    expect($stored)->not->toContain('segreto')
        ->and($monitor->fresh()->http_headers)->toBe(['Authorization' => 'Bearer segreto']);
});

it('passa quando il record DNS esiste', function () {
    fakeResolver([['host' => 'example.test', 'type' => 'A', 'ip' => '93.184.216.34']]);

    app(MonitorProbe::class)->check(Monitor::factory()->dns()->create());
})->throwsNoExceptions();

it('lancia quando il record DNS non esiste', function () {
    fakeResolver([]);

    app(MonitorProbe::class)->check(Monitor::factory()->dns()->create());
})->throws(MonitorCheckFailed::class, 'Nessun record A per example.test');

it('lancia quando il record DNS punta altrove', function () {
    fakeResolver([['host' => 'example.test', 'type' => 'A', 'ip' => '10.0.0.9']]);

    app(MonitorProbe::class)->check(Monitor::factory()->dns()->create([
        'expected_body_contains' => '93.184.216.34',
    ]));
})->throws(MonitorCheckFailed::class, 'Record A «10.0.0.9» senza «93.184.216.34»');

it('non confonde il tipo del record con il suo valore', function () {
    fakeResolver([['host' => 'example.test', 'type' => 'A', 'ip' => '10.0.0.9']]);

    app(MonitorProbe::class)->check(Monitor::factory()->dns()->create([
        'expected_body_contains' => 'A',
    ]));
})->throws(MonitorCheckFailed::class, 'senza «A»');

/**
 * Il resolver vero, non il mock: garantisce che una query fallita diventi una
 * lista vuota e non un `false` da controllare a valle.
 *
 * Il nome e' vuoto e non inesistente perche' molti resolver — quelli degli ISP
 * in testa — rispondono a un NXDOMAIN con un indirizzo di cortesia, e il test
 * passerebbe o no a seconda della rete.
 */
it('traduce una query DNS fallita in una lista vuota', function () {
    expect(app(DnsResolver::class)->records('', DnsRecordType::A))->toBe([]);
});

it('passa quando il push monitor ha pingato di recente', function () {
    app(MonitorProbe::class)->check(Monitor::factory()->push()->create([
        'grace_minutes' => 60,
        'last_ping_at' => now()->subMinutes(59),
    ]));
})->throwsNoExceptions();

it('non riporta un tempo di risposta per il push monitor', function () {
    $result = app(MonitorProbe::class)->check(Monitor::factory()->push()->create());

    expect($result->responseTimeMs)->toBeNull()
        ->and($result->statusCode)->toBeNull();
});

it('lancia quando il push monitor e in ritardo', function () {
    app(MonitorProbe::class)->check(Monitor::factory()->push()->create([
        'grace_minutes' => 60,
        'last_ping_at' => now()->subMinutes(61),
    ]));
})->throws(MonitorCheckFailed::class, 'atteso ogni 60 min');

it('lancia quando il push monitor non ha mai pingato', function () {
    app(MonitorProbe::class)->check(Monitor::factory()->push()->create(['last_ping_at' => null]));
})->throws(MonitorCheckFailed::class, 'Nessun ping ricevuto');

it('lancia subito quando il job si e dichiarato fallito, anche se il ping e fresco', function () {
    app(MonitorProbe::class)->check(Monitor::factory()->push()->create([
        'last_ping_at' => now(),
        'last_ping_failure' => 'backup: disco pieno',
    ]));
})->throws(MonitorCheckFailed::class, 'backup: disco pieno');
