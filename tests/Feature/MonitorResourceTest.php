<?php

use App\Enums\MonitorType;
use App\Filament\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\Resources\Monitors\Pages\ListMonitors;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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

it('rende il campo type reattivo lato client con wire:model.live', function () {
    Livewire::test(CreateMonitor::class)
        ->assertSeeHtml('wire:model.live="data.type"');
});

it('accoda il check per il monitor corretto invece di eseguirlo', function () {
    Queue::fake();
    $monitor = Monitor::factory()->create();

    Livewire::test(ListMonitors::class)
        ->callTableAction('checkNow', $monitor)
        ->assertHasNoTableActionErrors();

    Queue::assertPushed(CheckMonitor::class, fn (CheckMonitor $job): bool => $job->monitor->is($monitor));
});

it('permette a un utente autenticato di accedere alla pagina dei monitor via HTTP e mostra lo stato mai controllato', function () {
    Monitor::factory()->create(['is_up' => null]);
    Monitor::factory()->create(['is_up' => true]);
    Monitor::factory()->create(['is_up' => false]);

    $this->get('/admin/monitors')
        ->assertSuccessful()
        ->assertSee('mai controllato');
});

it('non espone una rotta di registrazione nel panel admin', function () {
    $this->get('/admin/register')->assertNotFound();
});

it('non espone una rotta di registrazione globale, come quella montata da uno starter kit', function () {
    expect(Route::has('register'))->toBeFalse();

    $this->get('/register')->assertNotFound();
});
