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
    public function check(Monitor $monitor): void
    {
        match ($monitor->type) {
            MonitorType::Http => $this->checkHttp($monitor),
            MonitorType::Tcp => $this->checkTcp($monitor),
        };
    }

    private function checkHttp(Monitor $monitor): void
    {
        $response = Http::timeout($monitor->timeout_seconds)->get($monitor->target);

        // ponytail: una lista vuota vale [200]. Il campo e' nullable perche' i
        // monitor TCP non lo compilano, e un monitor HTTP senza codici attesi
        // vuole dire il default, non "qualunque risposta va bene".
        //
        // `intval` perche' il TagsInput del form salva stringhe: senza
        // normalizzare, il confronto stretto con lo status intero fallirebbe
        // sempre, e ogni target risulterebbe giu'.
        $expected = array_map(intval(...), $monitor->expected_statuses ?: [200]);

        if (! in_array($response->status(), $expected, true)) {
            throw new MonitorCheckFailed(
                "HTTP {$response->status()}, attesi ".implode(', ', $expected)
            );
        }

        if (blank($monitor->expected_body_contains)) {
            return;
        }

        if (! str_contains($response->body(), $monitor->expected_body_contains)) {
            throw new MonitorCheckFailed(
                "Corpo della risposta senza «{$monitor->expected_body_contains}»"
            );
        }
    }

    private function checkTcp(Monitor $monitor): void
    {
        // ponytail: TCP connect, non ICMP ping. Il ping vero richiede exec() e
        // privilegi, è filtrato su molti hosting e va parsato a mano; inoltre un
        // kernel vivo con il servizio morto supera il ping. Upgrade path: un caso
        // MonitorType::Icmp con un ramo dedicato, dove i privilegi lo permettono.
        $connection = @fsockopen(
            $monitor->target,
            $monitor->port,
            $errno,
            $errstr,
            $monitor->timeout_seconds,
        );

        if ($connection === false) {
            throw new MonitorCheckFailed("TCP {$monitor->target}:{$monitor->port} — {$errstr} ({$errno})");
        }

        fclose($connection);
    }
}
