# Monitoring uptime — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sapere entro ~60 secondi quando un dominio o un VPS non risponde, con alert su Discord e gestione dei target via Filament.

**Architecture:** Un model `Monitor`, un job `CheckMonitor` che delega l'I/O di rete a `MonitorProbe`, e uno scheduler che dispatcha i monitor scaduti ogni minuto. Retry, backoff e deduplica sono feature native della queue di Laravel: `$tries = 4`, `$backoff = 20`, `ShouldBeUnique`. L'alert Discord parte da `failed()`, il recovery dal ramo di successo di `handle()`.

**Tech Stack:** Laravel 13.30, PHP 8.4, SQLite, Filament v5.8, `spatie/laravel-discord-alerts` 1.10, Pest.

**Spec:** [`docs/superpowers/specs/2026-09-08-monitoring-uptime-design.md`](../specs/2026-09-08-monitoring-uptime-design.md)

## Global Constraints

- **Le dipendenze sono già installate.** `filament/filament ^5.8`, `spatie/laravel-discord-alerts ^1.10`, `laravel/boost ^2.7` (dev) e il panel Filament (`filament:install --panels`) sono stati installati durante il design. Non reinstallare nulla.
- **API di Filament v5, non v3/v4.** `Filament\Resources\Resource` espone `form(Schema $schema): Schema` e `table(Table $table): Table`. Le classi vivono in: `Filament\Schemas\Schema`, `Filament\Tables\Table`, `Filament\Forms\Components\{TextInput, Select, Toggle}`, `Filament\Tables\Columns\{TextColumn, IconColumn, ToggleColumn}`, `Filament\Actions\Action`. v5 genera form e table in **classi separate** sotto `Schemas/` e `Tables/`: adattare il codice generato, non assumere la struttura di v4.
- **Ogni comando Artisan con `--no-interaction`.** Creare i file con `php artisan make:`, non a mano.
- **`vendor/bin/pint --dirty --format agent`** dopo ogni modifica a file PHP, prima del commit.
- **PHP:** graffe sempre, anche per corpi di una riga. Constructor property promotion. Return type e type hint espliciti su ogni metodo. Chiavi enum in TitleCase. PHPDoc invece di commenti inline, tranne per logica eccezionalmente complessa.
- **Test:** Pest, creati con `php artisan make:test --pest {Nome}` (senza directory nel nome). Eseguire il set più ristretto che copre la modifica: `php artisan test --compact --filter=nomeTest`.
- **`DiscordAlert::message()` dispatcha un job**, non fa una richiesta HTTP. Nei test asserire `Queue::assertPushed(Spatie\DiscordAlerts\Jobs\SendToDiscordChannelJob::class)`.
- **`.env` già corretto:** `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `DB_CONNECTION=sqlite`. `CACHE_STORE` non deve diventare `array`, altrimenti `ShouldBeUnique` perde il lock.
- **Non creare file di documentazione** oltre a questo piano e alla spec.

---

## Struttura dei file

| File | Responsabilità |
|---|---|
| `database/migrations/…_create_monitors_table.php` | Schema della tabella `monitors` |
| `app/Enums/MonitorType.php` | I due tipi di check, `http` e `tcp` |
| `app/Models/Monitor.php` | Cast, factory, scope `due()` |
| `database/factories/MonitorFactory.php` | Dati di test, stato `tcp()` |
| `app/Exceptions/MonitorCheckFailed.php` | Il segnale che innesca il retry della queue |
| `app/Support/MonitorProbe.php` | L'unica classe con I/O di rete |
| `app/Jobs/CheckMonitor.php` | Policy di retry, transizioni di stato, alert Discord |
| `routes/console.php` | Dispatch dei monitor scaduti, ogni minuto |
| `app/Filament/Resources/Monitors/**` | CRUD dei target (generato, poi adattato) |

Confine centrale: `MonitorProbe` sa parlare in rete ma non sa cosa sia un alert; `CheckMonitor` decide chi avvisare ma non apre socket. Si testano separatamente.

---

## Task 1: Model, enum, migration, factory

**Files:**
- Create: `app/Enums/MonitorType.php`
- Create: `app/Models/Monitor.php` (via `make:model`)
- Create: `database/migrations/…_create_monitors_table.php` (via `make:model -m`)
- Create: `database/factories/MonitorFactory.php` (via `make:model -f`)
- Test: `tests/Feature/MonitorTest.php`

**Interfaces:**
- Consumes: niente, è il primo task.
- Produces: `App\Enums\MonitorType` con i casi `Http` e `Tcp` (backed string `'http'`/`'tcp'`); `App\Models\Monitor` con le colonne elencate sotto, i cast su `type`/`is_active`/`is_up`/`next_check_at`/`last_checked_at`, lo scope `due()`, e `Monitor::factory()` con lo stato `tcp()`.

- [ ] **Step 1: Genera i file con Artisan**

```bash
php artisan make:model Monitor -mf --no-interaction
php artisan make:class Enums/MonitorType --no-interaction
```

- [ ] **Step 2: Scrivi l'enum**

Sostituire interamente `app/Enums/MonitorType.php`:

```php
<?php

namespace App\Enums;

enum MonitorType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
}
```

- [ ] **Step 3: Scrivi la migration**

Nel metodo `up()` della migration generata:

```php
Schema::create('monitors', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('type')->default('http');
    $table->string('target');
    $table->unsignedSmallInteger('port')->nullable();
    $table->unsignedSmallInteger('expected_status')->default(200);
    $table->unsignedSmallInteger('timeout_seconds')->default(10);
    $table->unsignedSmallInteger('interval_minutes')->default(1);
    $table->boolean('is_active')->default(true);
    $table->boolean('is_up')->nullable();
    $table->timestamp('next_check_at')->nullable()->index();
    $table->timestamp('last_checked_at')->nullable();
    $table->text('last_failure_reason')->nullable();
    $table->timestamps();
});
```

`is_up` è nullable a tre valori: `null` = mai controllato, `true` = su, `false` = giù. L'indice su `next_check_at` serve alla query dello scheduler, che gira ogni minuto.

- [ ] **Step 4: Scrivi il test dello scope `due()`**

`tests/Feature/MonitorTest.php`:

```php
<?php

use App\Models\Monitor;

it('include i monitor mai controllati', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => null]);

    expect(Monitor::due()->pluck('id'))->toContain($monitor->id);
});

it('include i monitor la cui scadenza è passata', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => now()->subMinute()]);

    expect(Monitor::due()->pluck('id'))->toContain($monitor->id);
});

it('esclude i monitor non ancora scaduti', function () {
    $monitor = Monitor::factory()->create(['next_check_at' => now()->addMinutes(5)]);

    expect(Monitor::due()->pluck('id'))->not->toContain($monitor->id);
});

it('esclude i monitor in pausa anche se scaduti', function () {
    $monitor = Monitor::factory()->create([
        'is_active' => false,
        'next_check_at' => now()->subHour(),
    ]);

    expect(Monitor::due()->pluck('id'))->not->toContain($monitor->id);
});

it('castta il tipo a enum', function () {
    $monitor = Monitor::factory()->tcp()->create();

    expect($monitor->fresh()->type)->toBe(App\Enums\MonitorType::Tcp);
});
```

- [ ] **Step 5: Esegui il test per verificare che falliscano**

```bash
php artisan test --compact tests/Feature/MonitorTest.php
```

Atteso: FAIL — lo scope `due()` non esiste e la factory non ha lo stato `tcp()`.

- [ ] **Step 6: Scrivi il model**

`app/Models/Monitor.php`:

```php
<?php

namespace App\Models;

use App\Enums\MonitorType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Monitor extends Model
{
    /** @use HasFactory<\Database\Factories\MonitorFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * I monitor attivi da controllare adesso: mai controllati, o con la
     * scadenza già passata.
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', now());
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'is_active' => 'boolean',
            'is_up' => 'boolean',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
```

L'attributo `#[Scope]` è la convenzione di Laravel 13 (verificato: `Illuminate\Database\Eloquent\Attributes\Scope` esiste), e sostituisce il prefisso `scopeDue`. Il raggruppamento della closure `orWhere` non è cosmetico: senza le parentesi, `is_active` finirebbe in OR con la scadenza e i monitor in pausa verrebbero controllati.

`$guarded = []` perché l'unico punto di scrittura è il panel Filament autenticato, che controlla già i campi del form. Non ci sono endpoint pubblici che scrivono su questa tabella.

- [ ] **Step 7: Scrivi la factory**

`database/factories/MonitorFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\MonitorType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->domainWord(),
            'type' => MonitorType::Http,
            'target' => 'https://'.fake()->domainName(),
            'port' => null,
            'expected_status' => 200,
            'timeout_seconds' => 10,
            'interval_minutes' => 1,
            'is_active' => true,
            'is_up' => null,
            'next_check_at' => null,
        ];
    }

    public function tcp(): static
    {
        return $this->state(fn (): array => [
            'type' => MonitorType::Tcp,
            'target' => '127.0.0.1',
            'port' => 22,
        ]);
    }
}
```

- [ ] **Step 8: Esegui migration e test**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/MonitorTest.php
```

Atteso: 5 test PASS.

- [ ] **Step 9: Pint e commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/ database/ tests/
git commit -m "feat: add Monitor model with due scope"
```

---

## Task 2: MonitorProbe

**Files:**
- Create: `app/Exceptions/MonitorCheckFailed.php`
- Create: `app/Support/MonitorProbe.php`
- Test: `tests/Feature/MonitorProbeTest.php`

**Interfaces:**
- Consumes: `App\Models\Monitor`, `App\Enums\MonitorType` dal Task 1.
- Produces: `App\Support\MonitorProbe` con il solo metodo pubblico `check(Monitor $monitor): void`, che lancia `App\Exceptions\MonitorCheckFailed` (estende `RuntimeException`) al fallimento e non ritorna nulla al successo.

- [ ] **Step 1: Genera i file**

```bash
php artisan make:exception MonitorCheckFailed --no-interaction
php artisan make:class Support/MonitorProbe --no-interaction
```

- [ ] **Step 2: Scrivi l'exception**

`app/Exceptions/MonitorCheckFailed.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class MonitorCheckFailed extends RuntimeException
{
}
```

- [ ] **Step 3: Scrivi i test**

`tests/Feature/MonitorProbeTest.php`. Il test TCP di successo apre un vero server socket su una porta libera: è deterministico e non dipende dalla rete esterna.

```php
<?php

use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use App\Support\MonitorProbe;
use Illuminate\Support\Facades\Http;

it('passa quando lo status HTTP è quello atteso', function () {
    Http::fake(['https://example.test/*' => Http::response('ok', 200)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_status' => 200,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('lancia quando lo status HTTP non è quello atteso', function () {
    Http::fake(['https://example.test/*' => Http::response('boom', 500)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_status' => 200,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class, 'HTTP 500');

it('rispetta uno status atteso diverso da 200', function () {
    Http::fake(['https://example.test/*' => Http::response('', 301)]);

    $monitor = Monitor::factory()->create([
        'target' => 'https://example.test/health',
        'expected_status' => 301,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throwsNoExceptions();

it('passa quando la porta TCP accetta connessioni', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) explode(':', stream_socket_get_name($server, false))[1];

    $monitor = Monitor::factory()->tcp()->create([
        'target' => '127.0.0.1',
        'port' => $port,
    ]);

    try {
        app(MonitorProbe::class)->check($monitor);
    } finally {
        fclose($server);
    }
})->throwsNoExceptions();

it('lancia quando la porta TCP è chiusa', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) explode(':', stream_socket_get_name($server, false))[1];
    fclose($server);

    $monitor = Monitor::factory()->tcp()->create([
        'target' => '127.0.0.1',
        'port' => $port,
        'timeout_seconds' => 1,
    ]);

    app(MonitorProbe::class)->check($monitor);
})->throws(MonitorCheckFailed::class);
```

Nel quinto test la porta viene aperta e subito chiusa: garantisce che nessun altro processo la stia ascoltando, senza indovinare un numero.

- [ ] **Step 4: Esegui i test per verificare che falliscano**

```bash
php artisan test --compact tests/Feature/MonitorProbeTest.php
```

Atteso: FAIL — `MonitorProbe::check()` non esiste.

- [ ] **Step 5: Scrivi il probe**

`app/Support/MonitorProbe.php`:

```php
<?php

namespace App\Support;

use App\Enums\MonitorType;
use App\Exceptions\MonitorCheckFailed;
use App\Models\Monitor;
use Illuminate\Support\Facades\Http;

class MonitorProbe
{
    /**
     * @throws MonitorCheckFailed quando il target non risponde come atteso.
     */
    public function check(Monitor $monitor): void
    {
        match ($monitor->type) {
            MonitorType::Http => $this->checkHttp($monitor),
            MonitorType::Tcp => $this->checkTcp($monitor),
        };
    }

    private function checkHttp(Monitor $monitor): void
    {
        $status = Http::timeout($monitor->timeout_seconds)
            ->get($monitor->target)
            ->status();

        if ($status !== $monitor->expected_status) {
            throw new MonitorCheckFailed("HTTP {$status}, atteso {$monitor->expected_status}");
        }
    }

    private function checkTcp(Monitor $monitor): void
    {
        // ponytail: TCP connect, non ICMP ping. Il ping vero richiede exec() e
        // privilegi, è filtrato su molti hosting e va parsato a mano; inoltre un
        // kernel vivo con il servizio morto supera il ping. Upgrade path: un caso
        // MonitorType::Icmp con un ramo dedicato, dove i privilegi lo permettono.
        $connection = @fsockopen(
            $monitor->target,
            $monitor->port,
            $errno,
            $errstr,
            $monitor->timeout_seconds,
        );

        if ($connection === false) {
            throw new MonitorCheckFailed("TCP {$monitor->target}:{$monitor->port} — {$errstr} ({$errno})");
        }

        fclose($connection);
    }
}
```

Timeout e fallimenti DNS emergono da `Http::get()` come `ConnectionException`, che **deve** propagare senza essere catturata: per la queue è un fallimento come gli altri, ed è esattamente ciò che innesca il retry. Catturarla per riavvolgerla in `MonitorCheckFailed` aggiungerebbe codice senza cambiare comportamento.

- [ ] **Step 6: Esegui i test**

```bash
php artisan test --compact tests/Feature/MonitorProbeTest.php
```

Atteso: 5 test PASS.

- [ ] **Step 7: Pint e commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/ tests/
git commit -m "feat: add MonitorProbe for HTTP and TCP checks"
```

---

## Task 3: CheckMonitor job e policy di alert

**Files:**
- Create: `app/Jobs/CheckMonitor.php`
- Test: `tests/Feature/CheckMonitorTest.php`

**Interfaces:**
- Consumes: `App\Models\Monitor` (Task 1), `App\Support\MonitorProbe::check()` e `App\Exceptions\MonitorCheckFailed` (Task 2).
- Produces: `App\Jobs\CheckMonitor`, costruito con `new CheckMonitor(Monitor $monitor)` e dispatchabile con `CheckMonitor::dispatch($monitor)`. Espone `handle(MonitorProbe $probe): void` e `failed(?Throwable $e): void`.

- [ ] **Step 1: Genera il job**

```bash
php artisan make:job CheckMonitor --no-interaction
```

- [ ] **Step 2: Scrivi i test**

`tests/Feature/CheckMonitorTest.php`. Questi test sono il cuore della verifica: il secondo e il terzo proteggono la regola anti-spam.

```php
<?php

use App\Exceptions\MonitorCheckFailed;
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Support\MonitorProbe;
use Illuminate\Support\Facades\Queue;
use Spatie\DiscordAlerts\Jobs\SendToDiscordChannelJob;

beforeEach(function () {
    Queue::fake();
});

it('segna il monitor come su e programma il prossimo check', function () {
    $monitor = Monitor::factory()->create(['is_up' => null, 'interval_minutes' => 5]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once();

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    $monitor->refresh();
    expect($monitor->is_up)->toBeTrue()
        ->and($monitor->last_checked_at)->not->toBeNull()
        ->and($monitor->next_check_at->timestamp)->toBeGreaterThan(now()->addMinutes(4)->timestamp);
});

it('non manda nulla su Discord quando il monitor era già su', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once();

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    Queue::assertNotPushed(SendToDiscordChannelJob::class);
});

it('manda il recovery quando un monitor giù torna su', function () {
    $monitor = Monitor::factory()->create(['is_up' => false]);
    $this->mock(MonitorProbe::class)->shouldReceive('check')->once();

    (new CheckMonitor($monitor))->handle(app(MonitorProbe::class));

    Queue::assertPushed(SendToDiscordChannelJob::class);
    expect($monitor->refresh()->is_up)->toBeTrue();
});

it('manda l alert e segna giù al fallimento definitivo', function () {
    $monitor = Monitor::factory()->create(['is_up' => true]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('HTTP 500, atteso 200'));

    Queue::assertPushed(SendToDiscordChannelJob::class);

    $monitor->refresh();
    expect($monitor->is_up)->toBeFalse()
        ->and($monitor->last_failure_reason)->toBe('HTTP 500, atteso 200');
});

it('non ri-allerta un monitor già noto come giù', function () {
    $monitor = Monitor::factory()->create(['is_up' => false]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('ancora giù'));

    Queue::assertNotPushed(SendToDiscordChannelJob::class);
    expect($monitor->refresh()->last_failure_reason)->toBe('ancora giù');
});

it('programma il prossimo check anche quando fallisce', function () {
    $monitor = Monitor::factory()->create(['is_up' => true, 'next_check_at' => null]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('giù'));

    expect($monitor->refresh()->next_check_at)->not->toBeNull();
});

it('allerta al primo fallimento di un monitor mai controllato', function () {
    $monitor = Monitor::factory()->create(['is_up' => null]);

    (new CheckMonitor($monitor))->failed(new MonitorCheckFailed('giù'));

    Queue::assertPushed(SendToDiscordChannelJob::class);
});
```

L'ultimo test fissa una decisione che altrimenti resterebbe implicita: un monitor appena creato che non risponde (`is_up === null`) **deve** allertare. La condizione giusta è quindi `is_up !== false`, non `is_up === true`.

- [ ] **Step 3: Esegui i test per verificare che falliscano**

```bash
php artisan test --compact tests/Feature/CheckMonitorTest.php
```

Atteso: FAIL — `handle()` e `failed()` non hanno ancora la logica.

- [ ] **Step 4: Scrivi il job**

`app/Jobs/CheckMonitor.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Support\MonitorProbe;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Throwable;

class CheckMonitor implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Un check più tre retry. */
    public int $tries = 4;

    /** Secondi tra i retry: l'alert parte a ~60 secondi dal primo fallimento. */
    public int $backoff = 20;

    /**
     * Il lock dura più della catena di retry, così lo scheduler non
     * ri-dispatcha lo stesso monitor mentre è ancora in ritenta.
     */
    public int $uniqueFor = 300;

    public function __construct(public Monitor $monitor) {}

    public function uniqueId(): string
    {
        return (string) $this->monitor->id;
    }

    public function handle(MonitorProbe $probe): void
    {
        $probe->check($this->monitor);

        $wasDown = $this->monitor->is_up === false;

        $this->monitor->update([
            'is_up' => true,
            'last_failure_reason' => null,
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
        ]);

        if ($wasDown) {
            DiscordAlert::message("✅ **{$this->monitor->name}** è tornato su — {$this->monitor->target}");
        }
    }

    /**
     * Invocato dopo l'ultimo tentativo fallito: è qui che parte l'alert.
     */
    public function failed(?Throwable $exception): void
    {
        $wasUp = $this->monitor->is_up !== false;

        $this->monitor->update([
            'is_up' => false,
            'last_failure_reason' => $exception?->getMessage(),
            'last_checked_at' => now(),
            'next_check_at' => now()->addMinutes($this->monitor->interval_minutes),
        ]);

        if ($wasUp) {
            DiscordAlert::message(
                "🔴 **{$this->monitor->name}** è giù — {$this->monitor->target}".PHP_EOL.$exception?->getMessage()
            );
        }
    }
}
```

Tre dettagli che non sono stilistici:

1. `$wasDown` / `$wasUp` vengono letti **prima** dell'`update()`, altrimenti la transizione è già stata sovrascritta e nessun messaggio parte mai.
2. L'alert parte **dopo** l'`update()`: se la scrittura falla, non si manda un messaggio che il database contraddice.
3. `next_check_at` è aggiornato in entrambi i rami. Se mancasse in uno dei due, un monitor in quello stato verrebbe ri-dispatchato ogni minuto per sempre.

Il messaggio Discord contiene nome, target e motivo — non header né body della risposta, che potrebbero trasportare token o dati di sessione in un canale di chat.

- [ ] **Step 5: Esegui i test**

```bash
php artisan test --compact tests/Feature/CheckMonitorTest.php
```

Atteso: 7 test PASS.

- [ ] **Step 6: Pint e commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/ tests/
git commit -m "feat: add CheckMonitor job with retry and alert policy"
```

---

## Task 4: Scheduler

**Files:**
- Modify: `routes/console.php`
- Test: `tests/Feature/MonitorSchedulingTest.php`

**Interfaces:**
- Consumes: `Monitor::due()` (Task 1), `CheckMonitor::dispatch()` (Task 3).
- Produces: un task schedulato di nome `dispatch-monitor-checks`, eseguito ogni minuto.

- [ ] **Step 1: Scrivi il test**

`tests/Feature/MonitorSchedulingTest.php`:

```php
<?php

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Illuminate\Support\Facades\Queue;

it('dispatcha un job per ogni monitor scaduto e nessuno per gli altri', function () {
    Queue::fake();

    $due = Monitor::factory()->create(['next_check_at' => now()->subMinute()]);
    $notDue = Monitor::factory()->create(['next_check_at' => now()->addHour()]);
    $paused = Monitor::factory()->create(['is_active' => false, 'next_check_at' => null]);

    $this->artisan('schedule:test --name=dispatch-monitor-checks')->assertSuccessful();

    Queue::assertPushed(CheckMonitor::class, 1);
    Queue::assertPushed(CheckMonitor::class, fn (CheckMonitor $job) => $job->monitor->is($due));
});
```

- [ ] **Step 2: Esegui il test per verificare che falisca**

```bash
php artisan test --compact tests/Feature/MonitorSchedulingTest.php
```

Atteso: FAIL — nessun task schedulato con quel nome.

- [ ] **Step 3: Registra lo scheduler**

Aggiungere in coda a `routes/console.php`, lasciando il comando `inspire` esistente:

```php
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    Monitor::due()->each(fn (Monitor $monitor) => CheckMonitor::dispatch($monitor));
})->everyMinute()->name('dispatch-monitor-checks')->withoutOverlapping();
```

Nessuna classe `Command`: non prende argomenti e non serve lanciarla a mano. `withoutOverlapping()` protegge dal caso in cui il dispatch stesso diventi lento; la deduplica dei check è comunque garantita da `ShouldBeUnique` sul job.

Se `schedule:test` non accettasse il filtro `--name` in questa versione, sostituire lo Step 1 con un test che invoca direttamente la closure via `Schedule::events()`, cercando l'evento per descrizione — non cambiare l'implementazione per accomodare il test.

- [ ] **Step 4: Esegui il test**

```bash
php artisan test --compact tests/Feature/MonitorSchedulingTest.php
php artisan schedule:list
```

Atteso: test PASS, e `schedule:list` mostra il task ogni minuto.

- [ ] **Step 5: Pint e commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/ tests/
git commit -m "feat: dispatch due monitor checks every minute"
```

---

## Task 5: Filament Resource

**Files:**
- Create: `app/Filament/Resources/Monitors/**` (via `make:filament-resource`)
- Test: `tests/Feature/MonitorResourceTest.php`

**Interfaces:**
- Consumes: `App\Models\Monitor` (Task 1), `App\Jobs\CheckMonitor` (Task 3), `App\Enums\MonitorType` (Task 1).
- Produces: il CRUD dei monitor nel panel admin.

- [ ] **Step 1: Genera la Resource**

```bash
php artisan make:filament-resource Monitor --generate --no-interaction
```

- [ ] **Step 2: Ispeziona ciò che è stato generato prima di modificarlo**

```bash
find app/Filament -type f | sort
```

v5 genera la Resource insieme a classi separate sotto `Schemas/` (il form) e `Tables/` (la tabella). **Leggere i file generati** e modificare quelli, senza spostare la logica nella Resource: la struttura generata è la convenzione di questa versione.

- [ ] **Step 3: Adatta il form**

Nella classe schema generata (`app/Filament/Resources/Monitors/Schemas/MonitorForm.php` o il nome che il generatore ha scelto), i campi devono essere:

```php
use App\Enums\MonitorType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

// dentro il metodo che ritorna lo Schema, nell'array dei componenti:
TextInput::make('name')
    ->required(),

Select::make('type')
    ->options(MonitorType::class)
    ->default(MonitorType::Http)
    ->required()
    ->live(),

TextInput::make('target')
    ->label(fn (callable $get): string => $get('type') === MonitorType::Tcp->value ? 'Host o IP' : 'URL')
    ->required(),

TextInput::make('port')
    ->numeric()
    ->minValue(1)
    ->maxValue(65535)
    ->required(fn (callable $get): bool => $get('type') === MonitorType::Tcp->value)
    ->visible(fn (callable $get): bool => $get('type') === MonitorType::Tcp->value),

TextInput::make('expected_status')
    ->numeric()
    ->default(200)
    ->required()
    ->visible(fn (callable $get): bool => $get('type') === MonitorType::Http->value),

TextInput::make('interval_minutes')
    ->numeric()
    ->minValue(1)
    ->default(1)
    ->required(),

TextInput::make('timeout_seconds')
    ->numeric()
    ->minValue(1)
    ->default(10)
    ->required(),

Toggle::make('is_active')
    ->default(true),
```

`->live()` sul Select è ciò che rende reattivi i `visible()` degli altri campi: senza, `port` ed `expected_status` non compaiono al cambio di tipo. Se in v5 la closure riceve un tipo diverso da `callable $get`, adeguare la firma a quella dei file generati — la logica resta identica.

- [ ] **Step 4: Adatta la tabella**

Nella classe table generata:

```php
use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;

// colonne:
TextColumn::make('name')
    ->searchable()
    ->sortable(),

TextColumn::make('type')
    ->badge(),

TextColumn::make('target')
    ->searchable()
    ->limit(40),

IconColumn::make('is_up')
    ->label('Stato')
    ->boolean()
    ->placeholder('mai controllato'),

ToggleColumn::make('is_active')
    ->label('Attivo'),

TextColumn::make('last_checked_at')
    ->label('Ultimo check')
    ->since()
    ->placeholder('—'),

TextColumn::make('last_failure_reason')
    ->label('Ultimo errore')
    ->limit(50)
    ->placeholder('—')
    ->toggleable(),

// azione di riga, insieme a quelle generate:
Action::make('checkNow')
    ->label('Controlla ora')
    ->icon('heroicon-o-arrow-path')
    ->action(function (Monitor $record): void {
        CheckMonitor::dispatch($record);
    })
    ->successNotificationTitle('Check accodato'),
```

`->placeholder()` su `is_up` copre il terzo stato: senza, un monitor mai controllato è indistinguibile da uno giù.

- [ ] **Step 5: Scrivi il test smoke**

`tests/Feature/MonitorResourceTest.php`:

```php
<?php

use App\Models\Monitor;
use App\Models\User;

use function Pest\Livewire\livewire;

it('mostra i monitor nella tabella', function () {
    $this->actingAs(User::factory()->create());
    $monitor = Monitor::factory()->create(['name' => 'sito-produzione']);

    $listPage = collect(glob(app_path('Filament/Resources/Monitors/Pages/List*.php')))
        ->map(fn (string $path): string => 'App\\Filament\\Resources\\Monitors\\Pages\\'.basename($path, '.php'))
        ->first();

    livewire($listPage)->assertCanSeeTableRecords([$monitor]);
});
```

Il test scopre la classe della pagina invece di codificarne il nome, perché il generatore v5 lo decide. Se `glob` non trova nulla, sostituire con il nome reale letto allo Step 2 — è la soluzione preferibile una volta noto.

- [ ] **Step 6: Esegui il test**

```bash
php artisan test --compact tests/Feature/MonitorResourceTest.php
```

Atteso: PASS. Se fallisce per l'accesso al panel, il model `User` deve poter accedere: verificare l'`AdminPanelProvider` generato e, se necessario, implementare `FilamentUser::canAccessPanel()` su `App\Models\User` ritornando `true`.

- [ ] **Step 7: Crea un utente admin e verifica a mano**

```bash
php artisan make:filament-user --no-interaction --name=admin --email=a.mian@laterallearning.it --password=changeme
```

Poi `php artisan serve`, aprire `/admin`, creare un monitor HTTP verso un sito reale e uno TCP verso `127.0.0.1:22`, e usare "Controlla ora".

- [ ] **Step 8: Pint e commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/ tests/
git commit -m "feat: add Filament resource for monitors"
```

---

## Task 6: Verifica end-to-end e note di deploy

**Files:**
- Modify: `.env.example`
- Test: nessun nuovo test automatico; questo task è verifica manuale.

**Interfaces:**
- Consumes: tutto quanto sopra.
- Produces: la conferma che la catena scheduler → queue → retry → Discord funziona davvero.

- [ ] **Step 1: Documenta il webhook in `.env.example`**

Aggiungere in coda:

```
DISCORD_ALERT_WEBHOOK=
```

- [ ] **Step 2: Configura il webhook reale in `.env`**

Chiedere all'utente l'URL del webhook del canale Discord e metterlo in `.env` come `DISCORD_ALERT_WEBHOOK`. **Non committare `.env`.**

- [ ] **Step 3: Verifica la catena completa con un target che fallisce di sicuro**

In tre terminali separati:

```bash
php artisan queue:work --tries=1
```

```bash
php artisan schedule:work
```

```bash
php artisan tinker --execute 'App\Models\Monitor::create(["name" => "test-down", "type" => "tcp", "target" => "127.0.0.1", "port" => 9, "interval_minutes" => 5, "timeout_seconds" => 2]);'
```

Nota: `--tries=1` sul worker **non** annulla il `$tries = 4` del job, che è definito sulla classe e vince. Attendere ~60 secondi e verificare che nel canale Discord arrivi un solo messaggio 🔴.

- [ ] **Step 4: Verifica il recovery e l'assenza di re-alert**

Aprire un listener sulla porta usata sopra:

```bash
nc -l 127.0.0.1 9
```

Al check successivo deve arrivare un solo messaggio ✅. Prima di aprire il listener, attendere due o tre cicli e confermare che **non** arrivino altri messaggi 🔴: è la regola anti-spam in azione.

- [ ] **Step 5: Esegui la suite completa**

```bash
php artisan test --compact
```

Atteso: tutti i test PASS. Riportare l'output reale, non un riassunto.

- [ ] **Step 6: Commit finale**

```bash
git add .env.example
git commit -m "chore: document Discord webhook env var"
```

---

## Task 7 (opzionale): dead man's switch

Da fare solo se l'utente lo conferma. Quando cade il VPS che ospita questo monitor, il silenzio è indistinguibile da "tutto bene".

**Files:**
- Modify: `routes/console.php`
- Modify: `.env.example`

- [ ] **Step 1: Registra un account su un servizio di dead man's switch**

healthchecks.io ha un piano gratuito. Creare un check con periodo 5 minuti e copiare l'URL di ping.

- [ ] **Step 2: Aggiungi il ping allo scheduler**

Modificare il task in `routes/console.php`:

```php
Schedule::call(function (): void {
    Monitor::due()->each(fn (Monitor $monitor) => CheckMonitor::dispatch($monitor));
})
    ->everyMinute()
    ->name('dispatch-monitor-checks')
    ->withoutOverlapping()
    ->pingOnSuccessIf(filled(config('services.heartbeat_url')), config('services.heartbeat_url'));
```

- [ ] **Step 3: Aggiungi la config**

In `config/services.php`:

```php
'heartbeat_url' => env('HEARTBEAT_PING_URL'),
```

E in `.env.example`:

```
HEARTBEAT_PING_URL=
```

`pingOnSuccessIf` è nativo di Laravel e non richiede Guzzle a mano. La guardia `filled()` fa sì che senza la variabile il task funzioni identico, così i test non provano a chiamare la rete.

- [ ] **Step 4: Verifica e commit**

Attendere due minuti e controllare che healthchecks.io mostri il check come "up".

```bash
vendor/bin/pint --dirty --format agent
git add routes/ config/ .env.example
git commit -m "feat: ping external dead man's switch from scheduler"
```

---

## Copertura della spec

| Requisito della spec | Task |
|---|---|
| Check HTTP con status atteso | 2 |
| Check TCP su porta | 2 |
| 3 retry a 20 secondi, alert a ~60s | 3 |
| Nessun re-alert mentre resta giù | 3 |
| Messaggio di recovery | 3 |
| Intervallo per-target | 1 (colonna), 3 (uso), 4 (query) |
| Deduplica dei check sovrapposti | 3 (`ShouldBeUnique`) |
| Gestione target via Filament | 5 |
| Pausa senza cancellare | 1 (colonna), 4 (scope), 5 (toggle) |
| Motivo dell'ultimo fallimento visibile | 3 (scrittura), 5 (colonna) |
| Nessun dato sensibile nei messaggi | 3 |
| Dead man's switch | 7 (opzionale) |
