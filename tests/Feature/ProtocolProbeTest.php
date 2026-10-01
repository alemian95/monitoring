<?php

use App\Enums\MonitorType;
use App\Enums\SmtpSecurity;
use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use App\Support\MonitorProbe;

/**
 * Un server vero su una porta effimera, in un processo a parte: il probe
 * blocca sul socket, quindi chi risponde non puo' stare nello stesso processo.
 *
 * Manda il saluto, poi una risposta per ogni comando ricevuto. Un comando RESP
 * (`*2`) occupa piu' righe, e conta comunque come uno.
 *
 * @param  list<string>  $replies
 * @return array{string, int}
 */
function fakeServer(?string $greeting, array $replies): array
{
    $script = <<<'PHP'
        [$greeting, $replies] = json_decode($argv[1], true);
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo stream_socket_get_name($server, false), "\n";
        $connection = stream_socket_accept($server, 5);
        if ($greeting !== null) fwrite($connection, $greeting);
        foreach ($replies as $reply) {
            $line = fgets($connection);
            if ($line === false) break;
            if ($line[0] === '*') for ($i = 0; $i < 2 * (int) substr($line, 1); $i++) fgets($connection);
            fwrite($connection, $reply);
        }
        while (fgets($connection) !== false);
        PHP;

    $process = proc_open(
        [PHP_BINARY, '-r', $script, json_encode([$greeting, $replies])],
        [1 => ['pipe', 'w']],
        $pipes,
    );

    // Il handle va tenuto vivo: distruggerlo chiama proc_close(), che
    // aspetterebbe la fine di un server che sta ancora aspettando noi. Il
    // server esce da solo quando il probe chiude la connessione.
    static $servers = [];
    $servers[] = $process;

    [$host, $port] = explode(':', trim(fgets($pipes[1])));

    return [$host, (int) $port];
}

function redisMonitor(string $url): Monitor
{
    return Monitor::factory()->create(['type' => MonitorType::Redis, 'target' => null, 'connection_url' => $url, 'timeout_seconds' => 2]);
}

function smtpMonitor(string $host, int $port): Monitor
{
    return Monitor::factory()->create([
        'type' => MonitorType::Smtp,
        'target' => $host,
        'port' => $port,
        'smtp_security' => SmtpSecurity::None,
        'timeout_seconds' => 2,
    ]);
}

it('passa quando Redis risponde PONG', function () {
    [$host, $port] = fakeServer(null, ["+PONG\r\n"]);

    expect(app(MonitorProbe::class)->check(redisMonitor("redis://{$host}:{$port}"))->responseTimeMs)->toBeInt();
});

it('si autentica prima del PING quando l URL ha una password', function () {
    [$host, $port] = fakeServer(null, ["+OK\r\n", "+PONG\r\n"]);

    app(MonitorProbe::class)->check(redisMonitor("redis://:pa%20ss@{$host}:{$port}"));
})->throwsNoExceptions();

it('lancia con la risposta di Redis quando rifiuta il comando', function () {
    [$host, $port] = fakeServer(null, ["-NOAUTH Authentication required.\r\n"]);

    app(MonitorProbe::class)->check(redisMonitor("redis://{$host}:{$port}"));
})->throws(MonitorCheckFailed::class, 'Redis PING — -NOAUTH Authentication required.');

it('lancia quando la porta di Redis è chiusa', function () {
    app(MonitorProbe::class)->check(redisMonitor('redis://127.0.0.1:1'));
})->throws(MonitorCheckFailed::class, 'Redis tcp://127.0.0.1:1');

it('passa quando il server SMTP saluta e accetta EHLO', function () {
    [$host, $port] = fakeServer("220 mail.test ESMTP\r\n", ["250-mail.test\r\n250-PIPELINING\r\n250 SIZE 1000\r\n"]);

    app(MonitorProbe::class)->check(smtpMonitor($host, $port));
})->throwsNoExceptions();

it('lancia quando il server SMTP saluta con un errore', function () {
    [$host, $port] = fakeServer("421 Too many connections\r\n", []);

    app(MonitorProbe::class)->check(smtpMonitor($host, $port));
})->throws(MonitorCheckFailed::class, 'SMTP saluto — 421 Too many connections, atteso 220');

it('lancia quando il server SMTP rifiuta STARTTLS', function () {
    [$host, $port] = fakeServer("220 mail.test\r\n", ["250 mail.test\r\n", "454 TLS not available\r\n"]);
    $monitor = smtpMonitor($host, $port);
    $monitor->update(['smtp_security' => SmtpSecurity::Starttls]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class, 'SMTP STARTTLS — 454 TLS not available, atteso 220');

it('lancia quando il server SMTP accetta e poi tace', function () {
    [$host, $port] = fakeServer(null, []);
    $monitor = smtpMonitor($host, $port);
    $monitor->update(['timeout_seconds' => 1]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class, 'SMTP saluto — nessuna risposta');
