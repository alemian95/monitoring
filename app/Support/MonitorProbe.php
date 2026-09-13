<?php

namespace App\Support;

use App\Enums\MonitorType;
use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use Illuminate\Support\Facades\Http;

class MonitorProbe
{
    /**
     * @throws MonitorCheckFailed quando il target non risponde come atteso.
     */
    public function check(Monitor $monitor): ProbeResult
    {
        return match ($monitor->type) {
            MonitorType::Http => $this->checkHttp($monitor),
            MonitorType::Tcp => $this->checkTcp($monitor),
        };
    }

    private function checkHttp(Monitor $monitor): ProbeResult
    {
        $startedAt = hrtime(true);
        $response = Http::timeout($monitor->timeout_seconds)->get($monitor->target);
        $elapsedMs = $this->elapsedMs($startedAt);

        $status = $response->status();

        // ponytail: una lista vuota vale [200]. Il campo e' nullable perche' i
        // monitor TCP non lo compilano, e un monitor HTTP senza codici attesi
        // vuole dire il default, non "qualunque risposta va bene".
        //
        // `intval` perche' il TagsInput del form salva stringhe: senza
        // normalizzare, il confronto stretto con lo status intero fallirebbe
        // sempre, e ogni target risulterebbe giu'.
        $expected = array_map(intval(...), $monitor->expected_statuses ?: [200]);

        if (! in_array($status, $expected, true)) {
            throw new MonitorCheckFailed(
                "HTTP {$status}, attesi ".implode(', ', $expected),
                $status,
                $elapsedMs,
            );
        }

        if (filled($monitor->expected_body_contains)
            && ! str_contains($response->body(), $monitor->expected_body_contains)) {
            throw new MonitorCheckFailed(
                "Corpo della risposta senza «{$monitor->expected_body_contains}»",
                $status,
                $elapsedMs,
            );
        }

        $this->assertWithinThreshold($monitor, $elapsedMs, $status);

        return new ProbeResult($elapsedMs, $status);
    }

    private function checkTcp(Monitor $monitor): ProbeResult
    {
        // ponytail: TCP connect, non ICMP ping. Il ping vero richiede exec() e
        // privilegi, è filtrato su molti hosting e va parsato a mano; inoltre un
        // kernel vivo con il servizio morto supera il ping. Upgrade path: un caso
        // MonitorType::Icmp con un ramo dedicato, dove i privilegi lo permettono.
        $startedAt = hrtime(true);

        $connection = @fsockopen(
            $monitor->target,
            $monitor->port,
            $errno,
            $errstr,
            $monitor->timeout_seconds,
        );

        $elapsedMs = $this->elapsedMs($startedAt);

        if ($connection === false) {
            throw new MonitorCheckFailed(
                "TCP {$monitor->target}:{$monitor->port} — {$errstr} ({$errno})",
                responseTimeMs: $elapsedMs,
            );
        }

        fclose($connection);

        $this->assertWithinThreshold($monitor, $elapsedMs, null);

        return new ProbeResult($elapsedMs);
    }

    /**
     * Un target troppo lento e' un target giu'. Con i quattro tentativi del job
     * un singolo picco non fa scattare nulla: serve lentezza continuata per
     * circa un minuto. Soglia nulla significa "registra il tempo e basta".
     *
     * @throws MonitorCheckFailed
     */
    private function assertWithinThreshold(Monitor $monitor, int $elapsedMs, ?int $statusCode): void
    {
        if ($monitor->max_response_time_ms === null || $elapsedMs <= $monitor->max_response_time_ms) {
            return;
        }

        throw new MonitorCheckFailed(
            "Risposta in {$elapsedMs} ms, oltre il limite di {$monitor->max_response_time_ms} ms",
            $statusCode,
            $elapsedMs,
        );
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
