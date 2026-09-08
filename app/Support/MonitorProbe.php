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
        $status = Http::timeout($monitor->timeout_seconds)
            ->get($monitor->target)
            ->status();

        if ($status !== $monitor->expected_status) {
            throw new MonitorCheckFailed("HTTP {$status}, atteso {$monitor->expected_status}");
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
