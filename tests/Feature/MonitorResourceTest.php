<?php

use App\Enums\DnsRecordType;
use App\Enums\MonitorType;
use App\Filament\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\Resources\Monitors\Pages\EditMonitor;
use App\Filament\Resources\Monitors\Pages\ListMonitors;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\User;
use Filament\Forms\Components\Select;
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

it('mostra port solo per tcp ed expected_statuses solo per http', function () {
    $page = Livewire::test(CreateMonitor::class);

    $page->assertFormFieldVisible('expected_statuses')
        ->assertFormFieldHidden('port');

    $page->fillForm(['type' => MonitorType::Tcp->value])
        ->assertFormFieldVisible('port')
        ->assertFormFieldHidden('expected_statuses');

    $page->fillForm(['type' => MonitorType::Http->value])
        ->assertFormFieldVisible('expected_statuses')
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

it('mostra i campi giusti per DNS e per il push monitor', function () {
    $page = Livewire::test(CreateMonitor::class);

    $page->fillForm(['type' => MonitorType::Dns->value])
        ->assertFormFieldVisible('dns_record_type')
        ->assertFormFieldVisible('expected_body_contains')
        ->assertFormFieldHidden('expected_statuses')
        ->assertFormFieldHidden('http_method');

    $page->fillForm(['type' => MonitorType::Push->value])
        ->assertFormFieldVisible('grace_minutes')
        // Non c'e' niente da contattare: nessun indirizzo, nessun timeout.
        ->assertFormFieldHidden('target')
        ->assertFormFieldHidden('timeout_seconds');
});

it('mostra il corpo della richiesta solo per i metodi che ne hanno uno', function () {
    $page = Livewire::test(CreateMonitor::class);

    $page->fillForm(['type' => MonitorType::Http->value, 'http_method' => 'GET'])
        ->assertFormFieldHidden('http_body');

    $page->fillForm(['http_method' => 'POST'])
        ->assertFormFieldVisible('http_body');
});

it('rilegge header cifrati e tipo di record nel form di modifica', function () {
    $http = Monitor::factory()->create([
        'http_method' => 'POST',
        'http_headers' => ['Authorization' => 'Bearer segreto'],
    ]);
    $dns = Monitor::factory()->dns()->create();

    Livewire::test(EditMonitor::class, ['record' => $http->getRouteKey()])
        ->assertFormSet([
            'http_method' => 'POST',
            'http_headers' => ['Authorization' => 'Bearer segreto'],
        ]);

    Livewire::test(EditMonitor::class, ['record' => $dns->getRouteKey()])
        ->assertFormSet(['dns_record_type' => DnsRecordType::A]);
});

it('per un database chiede la connection URL al posto del target', function () {
    Livewire::test(CreateMonitor::class)
        ->fillForm(['type' => MonitorType::Database->value])
        ->assertFormFieldVisible('connection_url')
        ->assertFormFieldHidden('target')
        ->fillForm(['name' => 'db', 'connection_url' => 'sqlite:///etc/passwd'])
        ->call('create')
        ->assertHasFormErrors(['connection_url' => 'regex']);
});

it('mostra il valore JSON atteso solo quando c e un campo da leggere', function () {
    Livewire::test(CreateMonitor::class)
        ->assertFormFieldHidden('json_expected_value')
        ->fillForm(['json_path' => 'data.status'])
        ->assertFormFieldVisible('json_expected_value');
});

it('chiede porta e sicurezza per SMTP, e una URL redis per Redis', function () {
    Livewire::test(CreateMonitor::class)
        ->fillForm(['type' => MonitorType::Smtp->value])
        ->assertFormFieldVisible('port')
        ->assertFormFieldVisible('smtp_security')
        ->assertFormFieldHidden('connection_url')
        ->fillForm(['type' => MonitorType::Redis->value])
        ->assertFormFieldHidden('target')
        ->assertFormFieldHidden('smtp_security')
        ->fillForm(['name' => 'cache', 'connection_url' => 'mysql://root@db/app'])
        ->call('create')
        ->assertHasFormErrors(['connection_url' => 'regex']);
});

it('propone come gruppo solo i gruppi, e mai il monitor stesso', function () {
    $group = Monitor::factory()->group()->create(['name' => 'piattaforma']);
    $other = Monitor::factory()->group()->create(['name' => 'interni']);
    Monitor::factory()->create(['name' => 'sito']);

    Livewire::test(EditMonitor::class, ['record' => $group->getRouteKey()])
        ->assertFormFieldHidden('target')
        ->assertFormFieldHidden('timeout_seconds')
        ->assertFormFieldVisible('silences_children')
        ->assertFormFieldExists('parent_id', fn (Select $field): bool => $field->getOptions() === [$other->id => 'interni']);
});
