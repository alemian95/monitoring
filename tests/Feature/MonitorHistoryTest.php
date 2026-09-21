<?php

use App\Enums\UptimeRange;
use App\Exceptions\MonitorCheckFailed;
use App\Filament\Resources\Monitors\MonitorResource;
use App\Filament\Resources\Monitors\Pages\EditMonitor;
use App\Filament\Resources\Monitors\Widgets\MonitorIncidents;
use App\Filament\Resources\Monitors\Widgets\MonitorResponseTimeChart;
use App\Filament\Resources\Monitors\Widgets\MonitorUptimeChart;
use App\Filament\Widgets\UptimeOverview;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\User;
use App\Support\MonitorProbe;
use App\Support\ProbeResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config(['discord-alerts.webhook_urls.default' => 'https://discord.com/api/webhooks/000/test']);
    Queue::fake();
});

it('registra un check riuscito nello storico', function () {
    $monitor = Monitor::factory()->create();
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once()->andReturn(new ProbeResult(42, 200));

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

it('lascia a null i bucket senza dati invece di disegnarli a zero', function () {
    $monitor = Monitor::factory()->create();

    // ieri: 3 check su 4 riusciti. Oggi e i restanti 28 giorni: nessun dato.
    foreach ([true, true, true, false] as $i => $isUp) {
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'is_up' => $isUp,
            'checked_at' => now()->subDay()->startOfDay()->addHours($i),
        ]);
    }

    $series = $monitor->uptimeSeries()->values()->all();

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
        ->assertSee('Uptime')
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

it('produce il numero di bucket atteso per ogni finestra', function (UptimeRange $range, int $expected) {
    $monitor = Monitor::factory()->create();

    expect($monitor->uptimeSeries($range))->toHaveCount($expected);
})->with([
    [UptimeRange::Hour, 60],
    [UptimeRange::Day, 24],
    [UptimeRange::Week, 7],
    [UptimeRange::Month, 30],
]);

it('raggruppa i check nel bucket giusto su ogni finestra', function () {
    $monitor = Monitor::factory()->create();

    // Due check nello stesso minuto, uno su e uno giù: 50% in quel bucket,
    // qualunque sia la finestra scelta.
    foreach ([true, false] as $isUp) {
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'is_up' => $isUp,
            'checked_at' => now()->subMinutes(2),
        ]);
    }

    foreach (UptimeRange::cases() as $range) {
        $withData = $monitor->uptimeSeries($range)->filter(fn (?float $v): bool => $v !== null);

        expect($withData->all())->toBe([$withData->keys()->first() => 50.0], "finestra {$range->value}");
    }
});

it('espone tutte le finestre come filtro del grafico', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();

    Livewire::test(MonitorUptimeChart::class, ['record' => $monitor])
        ->assertSee('Ultima ora')
        ->assertSee('Ultime 24 ore')
        ->assertSee('Ultimi 7 giorni')
        ->assertSee('Ultimi 30 giorni')
        ->assertSee('Ultimi 3 mesi')
        ->assertSee('Ultimo anno');
});

it('cambia i dati quando si cambia finestra', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();

    $chart = Livewire::test(MonitorUptimeChart::class, ['record' => $monitor]);

    $chart->set('filter', 'hour');
    expect(invade($chart->instance())->getCachedData()['labels'])->toHaveCount(60);

    $chart->set('filter', 'week');
    expect(invade($chart->instance())->getCachedData()['labels'])->toHaveCount(7);

    // Dodici mesi solari, non 365 bucket giornalieri.
    $chart->set('filter', 'year');
    expect(invade($chart->instance())->getCachedData()['labels'])->toHaveCount(12);
});

it('fissa l asse verticale a 100, che è il massimo che una percentuale può valere', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();

    $options = invade(
        Livewire::test(MonitorUptimeChart::class, ['record' => $monitor])->instance()
    )->getOptions();

    expect($options['scales']['y']['max'])->toBe(100)
        ->and($options['scales']['y']['min'])->toBe(0);
});

it('mostra l uptime di ogni monitor in tabella, con una sola query aggregata', function () {
    $this->actingAs(User::factory()->create());

    $up = Monitor::factory()->create(['name' => 'sempre-su']);
    $flaky = Monitor::factory()->create(['name' => 'intermittente']);
    $mai = Monitor::factory()->create(['name' => 'mai-controllato']);

    foreach ([[$up, [true, true, true, true]], [$flaky, [true, false, true, true]]] as [$monitor, $results]) {
        foreach ($results as $i => $isUp) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'is_up' => $isUp,
                'checked_at' => now()->subHours($i + 1),
            ]);
        }
    }

    $rows = MonitorResource::getEloquentQuery()->get()->keyBy('name');

    expect(round($rows['sempre-su']->uptime_24h * 100, 2))->toBe(100.0)
        ->and(round($rows['intermittente']->uptime_24h * 100, 2))->toBe(75.0)
        ->and($rows['mai-controllato']->uptime_24h)->toBeNull();
});

it('esclude dall uptime in tabella i check piu vecchi di 24 ore', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();

    MonitorCheck::create([
        'monitor_id' => $monitor->id,
        'is_up' => false,
        'checked_at' => now()->subDays(3),
    ]);
    MonitorCheck::create([
        'monitor_id' => $monitor->id,
        'is_up' => true,
        'checked_at' => now()->subHour(),
    ]);

    $row = MonitorResource::getEloquentQuery()->find($monitor->id);

    // Il fallimento di tre giorni fa non deve abbassare la percentuale di oggi.
    expect(round($row->uptime_24h * 100, 2))->toBe(100.0);
});

it('lascia respiro sopra il 100 senza estendere la scala oltre il dominio', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();

    $options = invade(
        Livewire::test(MonitorUptimeChart::class, ['record' => $monitor])->instance()
    )->getOptions();

    expect($options['scales']['y']['max'])->toBe(100)
        ->and($options['layout']['padding']['top'])->toBeGreaterThan(0);
});

it('salva status e tempo di risposta del check riuscito', function () {
    $monitor = Monitor::factory()->create();
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once()->andReturn(new ProbeResult(137, 204));

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    $check = $monitor->checks()->first();

    expect($check->status_code)->toBe(204)
        ->and($check->response_time_ms)->toBe(137)
        ->and($check->failure_reason)->toBeNull();
});

it('salva status, tempo e motivo anche quando il check fallisce', function () {
    $monitor = Monitor::factory()->create();

    (new CheckMonitor($monitor))->failed(
        new MonitorCheckFailed('HTTP 503, attesi 200', 503, 88)
    );

    $check = $monitor->checks()->first();

    expect($check->is_up)->toBeFalse()
        ->and($check->status_code)->toBe(503)
        ->and($check->response_time_ms)->toBe(88)
        ->and($check->failure_reason)->toBe('HTTP 503, attesi 200');
});

it('registra il motivo ma non status ne tempo quando non c e stata risposta', function () {
    $monitor = Monitor::factory()->create();

    (new CheckMonitor($monitor))->failed(new ConnectionException('Connection timed out'));

    $check = $monitor->checks()->first();

    expect($check->failure_reason)->toBe('Connection timed out')
        ->and($check->status_code)->toBeNull()
        ->and($check->response_time_ms)->toBeNull();
});

it('media i tempi di risposta per bucket e lascia a null quelli vuoti', function () {
    $monitor = Monitor::factory()->create();

    foreach ([100, 300] as $ms) {
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'is_up' => true,
            'response_time_ms' => $ms,
            'checked_at' => now()->subDay()->startOfDay(),
        ]);
    }

    $series = $monitor->responseTimeSeries()->values()->all();

    expect($series)->toHaveCount(30)
        ->and(array_filter($series, fn ($v): bool => $v !== null))->toBe([28 => 200.0])
        ->and($series[29])->toBeNull();
});

it('esclude dalla media i check senza tempo, invece di contarli come zero', function () {
    $monitor = Monitor::factory()->create();

    MonitorCheck::create([
        'monitor_id' => $monitor->id,
        'is_up' => true,
        'response_time_ms' => 200,
        'checked_at' => now()->subMinutes(2),
    ]);

    // Un timeout: nessun tempo da registrare.
    MonitorCheck::create([
        'monitor_id' => $monitor->id,
        'is_up' => false,
        'response_time_ms' => null,
        'checked_at' => now()->subMinutes(2),
    ]);

    $withData = $monitor->responseTimeSeries(UptimeRange::Hour)
        ->filter(fn (?float $v): bool => $v !== null);

    expect($withData->first())->toBe(200.0);
});

it('renderizza il grafico dei tempi di risposta e lo registra nella pagina', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();
    MonitorCheck::create([
        'monitor_id' => $monitor->id,
        'is_up' => true,
        'response_time_ms' => 120,
        'checked_at' => now()->subHour(),
    ]);

    Livewire::test(MonitorResponseTimeChart::class, ['record' => $monitor])
        ->assertSee('Tempo di risposta')
        ->assertSuccessful();

    $widgets = (new ReflectionMethod(EditMonitor::class, 'getFooterWidgets'))
        ->invoke(new EditMonitor);

    expect($widgets)->toContain(MonitorResponseTimeChart::class);
});

it('lascia libero verso l alto l asse dei millisecondi, che non hanno un tetto', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create();

    $options = invade(
        Livewire::test(MonitorResponseTimeChart::class, ['record' => $monitor])->instance()
    )->getOptions();

    expect($options['scales']['y'])->toBe(['beginAtZero' => true]);
});

it('elenca i disservizi nel pannello, motivo compreso', function () {
    $monitor = Monitor::factory()->create();

    $monitor->checks()->create(['is_up' => true, 'checked_at' => now()->subMinutes(10)]);
    $monitor->checks()->create(['is_up' => false, 'failure_reason' => 'HTTP 503', 'checked_at' => now()->subMinutes(9)]);
    $monitor->checks()->create(['is_up' => true, 'checked_at' => now()->subMinutes(7)]);

    Livewire::test(MonitorIncidents::class, ['record' => $monitor])
        ->assertSee('2 minuti')
        // Dentro il pannello il motivo si mostra: non c'e' nessun estraneo.
        ->assertSee('HTTP 503')
        ->assertSuccessful();
});

it('dice esplicitamente quando non ci sono disservizi', function () {
    $monitor = Monitor::factory()->create();
    $monitor->checks()->create(['is_up' => true, 'checked_at' => now()->subMinute()]);

    Livewire::test(MonitorIncidents::class, ['record' => $monitor])
        ->assertSee('Nessun disservizio nel periodo');
});
