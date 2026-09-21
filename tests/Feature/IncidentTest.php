<?php

use App\Enums\UptimeRange;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Support\Incident;
use Illuminate\Support\Carbon;

/**
 * Una riga di storico per ogni carattere: `-` su, `x` giu'. Un check al
 * minuto, il piu' vecchio a sinistra, l'ultimo adesso.
 */
function history(string $pattern, ?string $reason = 'timeout'): Monitor
{
    $monitor = Monitor::factory()->create();
    $length = strlen($pattern);

    MonitorCheck::insert(collect(str_split($pattern))->map(fn (string $state, int $index): array => [
        'monitor_id' => $monitor->id,
        'is_up' => $state === '-',
        'response_time_ms' => 100,
        'failure_reason' => $state === '-' ? null : $reason,
        'checked_at' => now()->subMinutes($length - 1 - $index)->startOfMinute()->toDateTimeString(),
    ])->all());

    return $monitor;
}

it('non trova incidenti in uno storico tutto verde', function () {
    expect(history('-----')->incidents())->toBeEmpty();
});

it('ricostruisce un incidente chiuso, con inizio, fine e durata', function () {
    $incidents = history('--xxx--')->incidents();

    expect($incidents)->toHaveCount(1);

    $incident = $incidents->first();

    expect($incident)->toBeInstanceOf(Incident::class)
        ->and($incident->isOngoing())->toBeFalse()
        ->and($incident->reason)->toBe('timeout')
        ->and($incident->durationMinutes())->toBe(3)
        ->and($incident->duration())->toBe('3 minuti');
});

it('lascia aperto un incidente ancora in corso', function () {
    $incident = history('---xx')->incidents()->first();

    expect($incident->isOngoing())->toBeTrue()
        ->and($incident->endedAt)->toBeNull();
});

it('separa incidenti distinti e mette per primo il piu recente', function () {
    $incidents = history('-x--xxx--')->incidents();

    expect($incidents)->toHaveCount(2)
        ->and($incidents->first()->durationMinutes())->toBe(3)
        ->and($incidents->last()->durationMinutes())->toBe(1)
        ->and($incidents->first()->startedAt->greaterThan($incidents->last()->startedAt))->toBeTrue();
});

it('vede un incidente gia in corso all inizio della finestra', function () {
    $incident = history('xx--')->incidents()->first();

    expect($incident->durationMinutes())->toBe(2)
        ->and($incident->isOngoing())->toBeFalse();
});

it('guarda solo dentro la finestra richiesta', function () {
    $monitor = history('--xx--');

    expect($monitor->incidents(UptimeRange::Month))->toHaveCount(1)
        ->and($monitor->incidents(UptimeRange::Hour))->toHaveCount(1);

    MonitorCheck::where('monitor_id', $monitor->id)
        ->update(['checked_at' => now()->subDays(40)]);

    expect($monitor->incidents(UptimeRange::Month))->toBeEmpty();
});

it('tiene separati gli incidenti di monitor diversi', function () {
    $down = history('-xx-');
    history('----');

    expect($down->incidents())->toHaveCount(1);
});

it('scrive la durata in italiano, al massimo con due unita', function () {
    $start = Carbon::parse('2026-09-01 00:00:00');

    expect((new Incident($start, $start->copy()->addSeconds(20), null))->duration())->toBe('meno di un minuto')
        ->and((new Incident($start, $start->copy()->addMinute(), null))->duration())->toBe('1 minuto')
        ->and((new Incident($start, $start->copy()->addMinutes(47), null))->duration())->toBe('47 minuti')
        ->and((new Incident($start, $start->copy()->addMinutes(123), null))->duration())->toBe('2 ore e 3 minuti')
        ->and((new Incident($start, $start->copy()->addDay(), null))->duration())->toBe('1 giorno')
        ->and((new Incident($start, $start->copy()->addMinutes(1500), null))->duration())->toBe('1 giorno e 1 ora');
});
