<?php

use App\Enums\MonitorVisibility;
use App\Models\Monitor;
use App\Models\User;

it('reindirizza la radice alla pagina di stato', function () {
    $this->get('/')->assertRedirect('/status');
});

it('non mostra il riepilogo a chi non e autenticato', function () {
    Monitor::factory()->create(['visibility' => MonitorVisibility::Public]);

    // Fuori non deve esistere un elenco: chi arriva da fuori puo' vedere il
    // servizio di cui ha l'indirizzo, non sapere quali altri ce ne sono.
    $this->get(route('status.index'))->assertRedirect(route('filament.admin.auth.login'));
});

it('mostra tutti i servizi nel riepilogo a chi e autenticato', function () {
    Monitor::factory()->create(['name' => 'Sito vetrina', 'visibility' => MonitorVisibility::Public]);
    Monitor::factory()->create(['name' => 'Gestionale interno', 'visibility' => MonitorVisibility::Private]);
    Monitor::factory()->create(['name' => 'Servizio del cliente', 'visibility' => MonitorVisibility::Signed]);

    $this->actingAs(User::factory()->create())
        ->get(route('status.index'))
        ->assertOk()
        ->assertSee('Sito vetrina')
        ->assertSee('Gestionale interno')
        ->assertSee('Servizio del cliente');
});

it('regge un riepilogo senza nemmeno un servizio', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('status.index'))
        ->assertOk()
        ->assertSee('Nessun servizio configurato');
});

it('non ammette che un servizio privato esista', function () {
    $monitor = Monitor::factory()->create(['visibility' => MonitorVisibility::Private]);

    // 404 e non 403: un 403 confermerebbe che a quell'id c'e' qualcosa.
    $this->get(route('status.monitor', $monitor))->assertNotFound();
    $this->get($monitor->statusUrl())->assertNotFound();
});

it('apre a chi e autenticato anche la pagina di un servizio privato', function () {
    $monitor = Monitor::factory()->create([
        'name' => 'Gestionale interno',
        'visibility' => MonitorVisibility::Private,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('status.monitor', $monitor))
        ->assertOk()
        ->assertSee('Gestionale interno');
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

it('elenca i disservizi con durata, senza rivelarne il motivo', function () {
    $monitor = Monitor::factory()->create(['visibility' => MonitorVisibility::Public]);

    foreach ([true, false, false, false, true] as $index => $isUp) {
        $monitor->checks()->create([
            'is_up' => $isUp,
            'failure_reason' => $isUp ? null : 'TCP 10.0.0.5:5432 — Connection refused',
            'checked_at' => now()->subMinutes(10 - $index),
        ]);
    }

    $this->get(route('status.monitor', $monitor))
        ->assertOk()
        ->assertSee('3 minuti di disservizio')
        // Un estraneo puo' sapere quanto e' durato, non com'e' fatta la rete.
        ->assertDontSee('10.0.0.5');
});

it('dice quando un disservizio e ancora in corso', function () {
    $monitor = Monitor::factory()->create(['visibility' => MonitorVisibility::Public]);

    $monitor->checks()->create(['is_up' => true, 'checked_at' => now()->subMinutes(5)]);
    $monitor->checks()->create(['is_up' => false, 'checked_at' => now()->subMinutes(4)]);

    $this->get(route('status.monitor', $monitor))
        ->assertOk()
        ->assertSee('disservizio in corso da 4 minuti');
});

it('conta i disservizi oltre i primi cinque invece di elencarli tutti', function () {
    $monitor = Monitor::factory()->create(['visibility' => MonitorVisibility::Public]);

    // Sette cadute alternate a sette riprese: cinque in elenco, due contate.
    foreach (range(0, 13) as $index) {
        $monitor->checks()->create([
            'is_up' => $index % 2 === 1,
            'checked_at' => now()->subMinutes(20 - $index),
        ]);
    }

    $this->get(route('status.monitor', $monitor))
        ->assertOk()
        ->assertSee('e altri 2 nel periodo');
});
