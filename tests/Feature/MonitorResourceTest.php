<?php

use App\Enums\MonitorType;
use App\Filament\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\Resources\Monitors\Pages\ListMonitors;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('mostra i monitor nella tabella', function () {
    $monitor = Monitor::factory()->create(['name' => 'sito-produzione']);

    Livewire::test(ListMonitors::class)
        ->assertCanSeeTableRecords([$monitor]);
});

it('mostra port solo per tcp ed expected_status solo per http', function () {
    $page = Livewire::test(CreateMonitor::class);

    $page->assertFormFieldVisible('expected_status')
        ->assertFormFieldHidden('port');

    $page->fillForm(['type' => MonitorType::Tcp->value])
        ->assertFormFieldVisible('port')
        ->assertFormFieldHidden('expected_status');

    $page->fillForm(['type' => MonitorType::Http->value])
        ->assertFormFieldVisible('expected_status')
        ->assertFormFieldHidden('port');
});

it('accoda il check invece di eseguirlo', function () {
    Queue::fake();
    $monitor = Monitor::factory()->create();

    Livewire::test(ListMonitors::class)
        ->callTableAction('checkNow', $monitor)
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(CheckMonitor::class);
});

it('permette a un utente autenticato di accedere alla pagina dei monitor via HTTP', function () {
    $this->get('/admin/monitors')->assertSuccessful();
});
