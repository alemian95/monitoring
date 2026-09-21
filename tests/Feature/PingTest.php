<?php

use App\Models\Monitor;

it('registra il ping di un job vivo', function () {
    $monitor = Monitor::factory()->push()->create(['last_ping_at' => now()->subHours(2)]);

    $this->get($monitor->pingUrl())->assertSuccessful();

    expect($monitor->fresh()->last_ping_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('accetta anche il POST, perche i cron usano curl in entrambi i modi', function () {
    $monitor = Monitor::factory()->push()->create(['last_ping_at' => null]);

    $this->post($monitor->pingUrl())->assertSuccessful();

    expect($monitor->fresh()->last_ping_at)->not->toBeNull();
});

it('registra il fallimento dichiarato dal job, con il corpo come motivo', function () {
    $monitor = Monitor::factory()->push()->create();

    $this->call('POST', $monitor->pingUrl(failure: true), content: 'disco pieno')
        ->assertSuccessful();

    expect($monitor->fresh()->last_ping_failure)->toBe('disco pieno');
});

it('registra il fallimento anche senza corpo', function () {
    $monitor = Monitor::factory()->push()->create();

    $this->get($monitor->pingUrl(failure: true))->assertSuccessful();

    expect($monitor->fresh()->last_ping_failure)->toBe('Il job ha segnalato un fallimento');
});

it('un ping riuscito chiude il fallimento precedente', function () {
    $monitor = Monitor::factory()->push()->create(['last_ping_failure' => 'disco pieno']);

    $this->get($monitor->pingUrl())->assertSuccessful();

    expect($monitor->fresh()->last_ping_failure)->toBeNull();
});

it('non riconosce un token sconosciuto', function () {
    $this->get('/ping/inventato')->assertNotFound();
});

it('non riconosce un suffisso diverso da fail', function () {
    $monitor = Monitor::factory()->push()->create();

    $this->get($monitor->pingUrl().'/ok')->assertNotFound();
});

it('da a ogni monitor un token suo, e rigenerarlo invalida il vecchio', function () {
    $first = Monitor::factory()->push()->create();
    $second = Monitor::factory()->push()->create();

    expect($first->ping_token)->not->toBe($second->ping_token);

    $old = $first->pingUrl();
    $first->update(['ping_token' => 'nuovo-token']);

    $this->get($old)->assertNotFound();
    $this->get($first->pingUrl())->assertSuccessful();
});
