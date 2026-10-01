<?php

use App\Enums\NotificationChannelType;
use App\Filament\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\Resources\NotificationChannels\Pages\CreateNotificationChannel;
use App\Filament\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Jobs\SendAlert;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\AlertChannel;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('accoda un alert per ogni canale del monitor, e solo per quelli', function () {
    Queue::fake();
    [$discord, $telegram, $other] = NotificationChannel::factory()->count(3)->create();
    $monitor = Monitor::factory()->create();
    $monitor->notificationChannels()->attach([$discord->id, $telegram->id]);

    app(AlertChannel::class)->send($monitor, 'giù');

    Queue::assertPushed(SendAlert::class, 2);
    Queue::assertNotPushed(SendAlert::class, fn (SendAlert $job): bool => $job->channel->is($other));
});

it('collega i canali predefiniti ai monitor nuovi', function () {
    $default = NotificationChannel::factory()->default()->create();
    NotificationChannel::factory()->create();

    $monitor = Monitor::factory()->create();

    expect($monitor->notificationChannels->modelKeys())->toBe([$default->id]);
});

it('nel form del monitor propone i predefiniti ma salva la scelta dell utente', function () {
    $this->actingAs(User::factory()->create());
    $default = NotificationChannel::factory()->default()->create();
    $chosen = NotificationChannel::factory()->create();

    Livewire::test(CreateMonitor::class)
        ->assertFormSet(['notificationChannels' => [$default->id]])
        ->fillForm(['name' => 'sito', 'target' => 'https://example.test', 'notificationChannels' => [$chosen->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Monitor::sole()->notificationChannels->modelKeys())->toBe([$chosen->id]);
});

it('salva solo le impostazioni del tipo scelto, cifrate', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateNotificationChannel::class)
        ->fillForm([
            'name' => 'ops',
            'type' => NotificationChannelType::Telegram->value,
            'settings' => ['bot_token' => '123:abc', 'chat_id' => '42'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $channel = NotificationChannel::sole();

    expect($channel->settings)->toBe(['bot_token' => '123:abc', 'chat_id' => '42'])
        ->and($channel->getRawOriginal('settings'))->not->toContain('123:abc');
});

it('il test di un canale rotto lo dice subito, senza lasciare un job fallito', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*' => Http::response(['message' => 'Unknown Webhook'], 404)]);
    $channel = NotificationChannel::factory()->create();

    Livewire::test(ListNotificationChannels::class)
        ->callAction(TestAction::make('sendTest')->table($channel))
        ->assertNotified('Consegna fallita');

    $this->assertDatabaseEmpty('failed_jobs');
});
