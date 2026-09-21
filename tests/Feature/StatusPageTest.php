<?php

use App\Enums\MonitorVisibility;
use App\Models\Monitor;

it('reindirizza la radice alla pagina di stato', function () {
    $this->get('/')->assertRedirect('/status');
});

it('elenca solo i servizi pubblici', function () {
    Monitor::factory()->create(['name' => 'Sito vetrina', 'visibility' => MonitorVisibility::Public]);
    Monitor::factory()->create(['name' => 'Gestionale interno', 'visibility' => MonitorVisibility::Private]);
    Monitor::factory()->create(['name' => 'Servizio del cliente', 'visibility' => MonitorVisibility::Signed]);

    $this->get(route('status.index'))
        ->assertOk()
        ->assertSee('Sito vetrina')
        ->assertDontSee('Gestionale interno')
        ->assertDontSee('Servizio del cliente');
});

it('regge un indice senza nemmeno un servizio pubblicato', function () {
    Monitor::factory()->create(['visibility' => MonitorVisibility::Private]);

    $this->get(route('status.index'))
        ->assertOk()
        ->assertSee('Nessun servizio pubblicato');
});

it('non ammette che un servizio privato esista', function () {
    $monitor = Monitor::factory()->create(['visibility' => MonitorVisibility::Private]);

    // 404 e non 403: un 403 confermerebbe che a quell'id c'e' qualcosa.
    $this->get(route('status.monitor', $monitor))->assertNotFound();
    $this->get($monitor->statusUrl())->assertNotFound();
});

it('apre in chiaro la pagina di un servizio pubblico', function () {
    $monitor = Monitor::factory()->create([
        'name' => 'API pagamenti',
        'visibility' => MonitorVisibility::Public,
        'is_up' => true,
    ]);

    $this->get(route('status.monitor', $monitor))
        ->assertOk()
        ->assertSee('API pagamenti')
        ->assertSee('Operativo');
});

it('non apre un servizio a link firmato senza firma', function () {
    $monitor = Monitor::factory()->create(['visibility' => MonitorVisibility::Signed]);

    $this->get(route('status.monitor', $monitor))->assertForbidden();
});

it('apre un servizio a link firmato con il suo link', function () {
    $monitor = Monitor::factory()->create([
        'name' => 'Sito del cliente',
        'visibility' => MonitorVisibility::Signed,
    ]);

    $this->get($monitor->statusUrl())
        ->assertOk()
        ->assertSee('Sito del cliente');
});

it('chiude un link firmato quando la scadenza e passata', function () {
    $monitor = Monitor::factory()->create(['visibility' => MonitorVisibility::Signed]);
    $url = $monitor->statusUrl(expiresInDays: 7);

    $this->get($url)->assertOk();

    $this->travel(8)->days();

    $this->get($url)->assertForbidden();
});

it('con il link di un servizio non ne apre un altro', function () {
    $mine = Monitor::factory()->create(['visibility' => MonitorVisibility::Signed]);
    $other = Monitor::factory()->create(['visibility' => MonitorVisibility::Signed]);

    $swapped = str_replace("/status/{$mine->id}", "/status/{$other->id}", $mine->statusUrl());

    $this->get($swapped)->assertForbidden();
});

it('disegna una barra per giorno della finestra e la percentuale di uptime', function () {
    $monitor = Monitor::factory()->create(['visibility' => MonitorVisibility::Public]);
    $monitor->checks()->createMany([
        ['is_up' => true, 'checked_at' => now()->subDay()],
        ['is_up' => true, 'checked_at' => now()->subDay()],
        ['is_up' => false, 'checked_at' => now()->subDay()],
        ['is_up' => true, 'checked_at' => now()],
    ]);

    $response = $this->get(route('status.monitor', $monitor))->assertOk();

    expect(substr_count($response->getContent(), 'class="bar '))->toBe(30);
    $response->assertSee('75% di uptime');
});
