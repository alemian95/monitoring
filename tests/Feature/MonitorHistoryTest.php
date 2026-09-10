<?php

use App\Exceptions\MonitorCheckFailed;
use App\Filament\Resources\Monitors\Pages\EditMonitor;
use App\Filament\Resources\Monitors\Widgets\MonitorUptimeChart;
use App\Filament\Widgets\UptimeOverview;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\User;
use App\Support\MonitorProbe;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config(['discord-alerts.webhook_urls.default' => 'https://discord.com/api/webhooks/000/test']);
    Queue::fake();
});

it('registra un check riuscito nello storico', function () {
    $monitor = Monitor::factory()->create();
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once();

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    expect($monitor->checks()->count())->toBe(1)
        ->and($monitor->checks()->first()->is_up)->toBeTrue();
});

it('registra un fallimento del target come una riga sola, non una per tentativo', function () {
    $monitor = Monitor::factory()->create();

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('giù'));

    expect($monitor->checks()->count())->toBe(1)
        ->and($monitor->checks()->first()->is_up)->toBeFalse();
});

it('NON registra nello storico un guasto di infrastruttura', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);

    (new CheckMonitor($monitor))->failed(new TimeoutExceededException('CheckMonitor has timed out.'));

    expect($monitor->checks()->count())->toBe(0);
});

it('lascia a null i giorni senza dati invece di disegnarli a zero', function () {
    $monitor = Monitor::factory()->create();

    // ieri: 3 check su 4 riusciti. Oggi e i restanti 28 giorni: nessun dato.
    foreach ([true, true, true, false] as $i => $isUp) {
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'is_up' => $isUp,
            'checked_at' => now()->subDay()->startOfDay()->addHours($i),
        ]);
    }

    $series = $monitor->uptimeByDay()->values()->all();

    expect($series)->toHaveCount(30)
        ->and(array_filter($series, fn ($v): bool => $v !== null))->toBe([28 => 75.0])
        ->and($series[29])->toBeNull();
});

it('mostra la KPI row nella dashboard', function () {
    $this->actingAs(User::factory()->create());
    Monitor::factory()->create(['is_up' => true]);
    Monitor::factory()->create(['is_up' => false]);

    Livewire::test(UptimeOverview::class)
        ->assertSee('Target su')
        ->assertSee('Target giù')
        ->assertSee('Uptime 24h')
        ->assertSee('nessun check registrato');
});

it('renderizza il grafico uptime del monitor con le sue etichette', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();
    MonitorCheck::create([
        'monitor_id' => $monitor->id,
        'is_up' => true,
        'checked_at' => now()->subHour(),
    ]);

    Livewire::test(MonitorUptimeChart::class, ['record' => $monitor])
        ->assertSee('Uptime — ultimi 30 giorni')
        ->assertSuccessful();
});

it('registra il grafico come footer widget nella pagina del monitor', function () {
    // Il widget è lazy, quindi non compare nell'HTML della prima richiesta:
    // si verifica la registrazione, non il markup renderizzato.
    $widgets = (new ReflectionMethod(EditMonitor::class, 'getFooterWidgets'))
        ->invoke(new EditMonitor);

    expect($widgets)->toContain(MonitorUptimeChart::class);
});

it('carica la pagina del monitor con il widget registrato', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();

    $this->get(EditMonitor::getUrl(['record' => $monitor], panel: 'admin'))
        ->assertSuccessful();
});

it('espone l azione di export dello storico', function () {
    $this->actingAs(User::factory()->create());
    Monitor::factory()->create();

    $this->get('/admin/monitors')
        ->assertSuccessful()
        ->assertSee('Esporta storico');
});
