# Monitoring uptime domini/VPS — design

**Data:** 2026-09-08
**Stato:** approvato in brainstorming, da implementare

## Obiettivo

Sapere entro un minuto quando un dominio o un VPS non risponde, e riceverlo
su Discord nei canali del team. Gestione dei target via interfaccia Filament,
senza deploy per aggiungere o mettere in pausa un monitor.

Ambiente: progetto Laravel 13 dedicato (`Dev/lateral/monitoring`), PHP 8.4,
SQLite, Filament v5.8, `spatie/laravel-discord-alerts` 1.10.

## Requisiti

1. Due tipi di check: **HTTP/HTTPS** (status code atteso) e **TCP** (porta
   raggiungibile su un host).
2. Su fallimento: **3 retry a 20 secondi** di distanza. Alert Discord solo
   dopo il quarto tentativo fallito, cioè a ~60 secondi dal primo errore.
3. Nessun re-alert mentre un target resta giù. Un messaggio quando torna su.
4. Intervallo di check configurabile **per singolo target**.
5. Scala prevista: 10-50 target.

## Approcci scartati

**SaaS esistente (Better Stack, UptimeRobot).** Zero codice e webhook Discord
nativo. Scartato perché serve l'interfaccia Filament interna e il controllo
sui dati dei target; resta il piano B se il costo di manutenzione supera il
valore.

**`spatie/laravel-uptime-monitor` esteso.** Regalerebbe storico uptime e
scadenza certificati SSL, ma entrambi i requisiti chiave gli vanno contro: il
check TCP non ha punto di estensione (il pacchetto esegue un pool Guzzle
dentro `MonitorCollection`, che va sottoclassato) e la sua logica di
fallimento è "N check consecutivi a distanza di minuti", incompatibile con i
retry a 20 secondi. Il costo di combattere il pacchetto supera quello di
scrivere il probe.

**Custom minimale (scelto).** Cinque file più una migration. Retry, backoff,
dead-letter e deduplica sono feature native della queue di Laravel, non
codice da mantenere.

## Modello dati

Una sola tabella, `monitors`.

| Colonna | Tipo | Note |
|---|---|---|
| `id` | id | |
| `name` | string | Label usata in Discord e in Filament |
| `type` | string (`http` \| `tcp`) | Unico discriminante di comportamento |
| `target` | string | URL completo per `http`, hostname/IP per `tcp` |
| `port` | unsignedSmallInteger nullable | Solo `tcp` |
| `expected_status` | unsignedSmallInteger, default 200 | Solo `http` |
| `timeout_seconds` | unsignedSmallInteger, default 10 | Per-target |
| `interval_minutes` | unsignedSmallInteger, default 1 | Per-target |
| `is_active` | boolean, default true | Pausa senza cancellare |
| `is_up` | boolean **nullable** | `null` = mai controllato |
| `next_check_at` | timestamp nullable, **indicizzato** | Quando ricontrollare |
| `last_checked_at` | timestamp nullable | Solo per la UI |
| `last_failure_reason` | text nullable | Ultimo errore, mostrato in tabella |
| `created_at` / `updated_at` | timestamps | |

Due scelte deliberate:

- **`is_up` booleano nullable** invece di un enum a tre stati. I tre valori
  necessari (mai controllato / su / giù) stanno in un booleano nullable, e
  risparmiano un file.
- **`next_check_at` come colonna** invece di calcolare
  `last_checked_at + interval_minutes` in query. Quel calcolo richiede
  sintassi specifica del database; una colonna è portabile e indicizzabile.

`type` è un enum PHP (`App\Enums\MonitorType`) castato sul model, così il
`match` nel probe è esaustivo e verificato dal type system.

## Componenti

### 1. Scheduler — `routes/console.php`

```php
Schedule::call(function () {
    Monitor::where('is_active', true)
        ->where(fn ($q) => $q->whereNull('next_check_at')
                             ->orWhere('next_check_at', '<=', now()))
        ->each(fn (Monitor $m) => CheckMonitor::dispatch($m));
})->everyMinute();
```

Nessuna classe `Command`: non ha argomenti e non serve lanciarlo a mano.
L'intervallo minimo effettivo è un minuto, la granularità del cron.

### 2. `App\Jobs\CheckMonitor`

Implementa `ShouldQueue` e `ShouldBeUnique`.

```php
public int $tries = 4;      // 1 check + 3 retry
public int $backoff = 20;   // secondi tra i retry
public int $uniqueFor = 300;

public function uniqueId(): string { return (string) $this->monitor->id; }
```

`handle()` invoca il probe. Se il probe lancia, la queue ri-tenta da sola. Al
successo:

- se `is_up === false`, manda il messaggio di recovery su Discord;
- aggiorna `is_up = true`, `last_checked_at`, `next_check_at`, azzera
  `last_failure_reason`.

Dopo il quarto tentativo fallito Laravel invoca `failed(Throwable $e)`, che:

- manda l'alert **solo se `is_up !== false`** (niente re-alert su un target
  già noto come giù);
- aggiorna `is_up = false`, `last_failure_reason`, `last_checked_at`,
  `next_check_at`.

`ShouldBeUnique` non è decorativo: con `interval_minutes = 1` e retry che
occupano ~60 secondi, lo scheduler ripescherebbe lo stesso monitor mentre il
primo job è ancora in ritenta, producendo alert doppi. Richiede un cache
driver reale (`database` con SQLite, non `array`).

`next_check_at` viene aggiornato sia nel ramo di successo sia in `failed()`.
Se non lo fosse in uno dei due, un target in quello stato verrebbe
ri-dispatchato ogni minuto per sempre.

### 3. `App\Support\MonitorProbe`

La sola classe che fa I/O di rete. Un metodo pubblico, un `match` esaustivo
su `MonitorType`, due rami privati. Fallimento = eccezione
(`App\Exceptions\MonitorCheckFailed`), che è esattamente ciò che serve al
retry della queue.

- **HTTP:** `Http::timeout($m->timeout_seconds)->get($m->target)`; lancia se
  `status() !== $m->expected_status`. Timeout e DNS failure emergono già come
  `ConnectionException`, che propaga senza incapsulamento.
- **TCP:** `@fsockopen($m->target, $m->port, $errno, $errstr, $timeout)`;
  lancia se `false`, altrimenti `fclose()`.

**Semplificazione dichiarata: TCP connect, non ICMP ping.** Il ping vero
richiede `exec()` e privilegi, è filtrato su molti hosting e va parsato a
mano. Inoltre "la porta 22 accetta connessioni" è un segnale più utile di
"l'host risponde al ping": un kernel vivo con SSH morto supera il ping. Per un
VPS senza servizi HTTP esposti si punta alla porta SSH. Il limite va marcato
con un commento `// ponytail:` che nomina l'upgrade path (un ramo `icmp` che
usa `exec` dove i privilegi lo permettono).

### 4. Filament

`php artisan make:filament-resource Monitor --generate` per tabella e form.
Aggiunte sopra il generato:

- `IconColumn` booleano su `is_up`, con stato per `null` ("mai controllato");
- `ToggleColumn` su `is_active`, per mettere in pausa dalla tabella;
- una `Action` "Controlla ora" che fa `CheckMonitor::dispatch($record)`;
- `port` visibile solo con `type = tcp`, `expected_status` solo con
  `type = http` (`visible()` reattivo sul campo `type`).

L'API di Filament v5 va verificata sul codice generato, non assunta da
versioni precedenti.

### 5. Discord

`spatie/laravel-discord-alerts`, webhook in `.env`
(`DISCORD_ALERT_WEBHOOK`). Due call site `DiscordAlert::message()`, entrambi
in `CheckMonitor`: uno in `failed()`, uno nel ramo di recovery. Nessuna
Notification class, nessun listener, nessun evento: due chiamate in un file
sono più facili da seguire di una catena di eventi.

**Il job di consegna e' sostituito.** `SendToDiscordChannelJob` del pacchetto
chiama `Http::post()` senza `->throw()`, quindi un webhook sbagliato, revocato
o rate-limited risulta consegnato per sempre: nessuna riga in `failed_jobs`,
niente nei log, nessuna differenza osservabile da un invio riuscito. In un
sistema di alerting quello e' il fallimento peggiore, perche' il segnale di
"tutto bene" e il segnale di "canale rotto" sono lo stesso silenzio.

`App\Jobs\SendDiscordAlert` estende quel job aggiungendo `->throw()`, ed e'
registrato in `config/discord-alerts.php` alla chiave `job`. Verificato contro
Discord: un webhook inesistente produce ora una riga in `failed_jobs` con
`RequestException: HTTP request returned status code 404 {"message": "Unknown
Webhook"}`.

Un vincolo che ne deriva: `discord-alerts.queue_connection` **non** va messo a
`sync`. Con la connection `database` l'alert viene accodato e fallisce per
conto suo; in `sync` l'eccezione risalirebbe dentro `CheckMonitor::failed()`,
dove verrebbe classificata come guasto di infrastruttura. Un test fissa
entrambi i comportamenti.

## Flusso completo

1. Il cron invoca lo scheduler ogni minuto.
2. Lo scheduler dispatcha un `CheckMonitor` per ogni monitor attivo e scaduto.
3. Il worker esegue il probe. Successo → aggiorna lo stato, eventuale
   recovery su Discord. Fallimento → eccezione.
4. La queue ri-tenta a 20, 40, 60 secondi.
5. Quarto fallimento → `failed()` → alert Discord (se non già giù) e stato
   `is_up = false`.

## Gestione errori

- Il probe non cattura nulla che non sappia gestire: ogni eccezione di rete è
  un fallimento legittimo e deve propagare al retry.
- `last_failure_reason` riceve il messaggio dell'eccezione, non lo stack
  trace, ed è mostrato in Filament.
- Il messaggio Discord contiene nome, target e motivo del fallimento. Non
  contiene header, body della risposta o credenziali: il `target` è già in
  chiaro nel canale, il resto no.
- Se un monitor viene cancellato mentre un suo job è in retry, Laravel di
  default **non** scarta il job: `CallQueuedHandler::handleModelNotFound()`
  chiama `$job->fail($e)`, che lascia una riga in `failed_jobs` e un'eccezione
  `report()`ata. Lo scarto silenzioso richiede `public bool
  $deleteWhenMissingModels = true;` sul job, impostato esplicitamente su
  `CheckMonitor`.
- **Solo un fallimento del probe significa "il target e' giu'".** `failed()`
  viene invocato per qualsiasi fallimento definitivo, incluse le eccezioni di
  infrastruttura (`QueryException` su lock SQLite, timeout del worker,
  `MaxAttemptsExceededException`). Trattarle come "giu'" produrrebbe un falso
  alert con dentro dettagli interni, seguito da un falso recovery. Quindi solo
  `MonitorCheckFailed` e `ConnectionException` toccano `is_up`; il resto viene
  `report()`ato e il monitor riprogrammato senza allertare.
- **Limite accertato, non aggirabile nel codice:** se il worker viene ucciso o
  va in timeout durante il quarto tentativo, il framework rimpiazza
  l'eccezione del probe con una sintetica (`TimeoutExceededException` dal
  gestore `SIGALRM`, o `MaxAttemptsExceededException` dopo `retry_after`).
  `failed()` non puo' distinguere quel caso, quindi lo classifica come
  infrastruttura: un target realmente giu' allerta **un intervallo piu'
  tardi**, non mai. L'alert e' ritardato, non perso. Il caso resta silenzioso
  solo se lo stesso guasto di infrastruttura si ripete a ogni ciclo, con
  `failed_jobs` e le eccezioni `report()`ate come unico segnale.

## Testing

Due test Pest, non una suite.

1. **`MonitorProbe`**: con `Http::fake()`, una risposta 500 fa lanciare
   `MonitorCheckFailed`, una 200 non lancia.
2. **Policy anti-spam**: `failed()` su un monitor con `is_up = true` manda
   l'alert e scrive `is_up = false`; su uno già `is_up = false` non manda
   nulla.

Il secondo è quello che conta: protegge la regola anti-spam, che è la parte
che si rompe silenziosamente in un refactor.

`DiscordAlert::message()` non fa una richiesta HTTP diretta: dispatcha un
`Spatie\DiscordAlerts\Jobs\SendToDiscordChannelJob`. Nei test l'asserzione
corretta è quindi `Queue::assertPushed(SendToDiscordChannelJob::class)`, non
`Http::assertSent()`. Il webhook non viene mai chiamato davvero.

## Note operative

- **Worker e cron.** Servono entrambi: `php artisan schedule:run` ogni minuto
  da cron e un `queue:work` persistente. Senza il worker i job restano in coda
  e nessun alert parte — un fallimento silenzioso da tenere presente in
  deploy.
- **SQLite e concorrenza.** Con queue driver `database` su SQLite, worker e
  scheduler scrivono sullo stesso file. A 10-50 monitor e un solo worker è
  sostenibile; se compaiono errori `database is locked`, la leva è abilitare
  WAL (`PRAGMA journal_mode=WAL`), non cambiare architettura.
- **Cache driver.** `ShouldBeUnique` richiede un lock: cache driver
  `database`, non `array`.
- **Alert durante i deploy.** Con 3 retry a 20 secondi l'alert parte a ~60
  secondi. Un riavvio di servizio più lungo di così genera un alert seguito
  dal recovery. Se diventa rumoroso, la leva è `$backoff` (es.
  `[20, 30, 60]`), non altra logica.
- **Chi monitora il monitor.** Quando cade questo VPS, il silenzio è
  indistinguibile da "tutto bene". Mitigazione da una riga: `->then(fn () =>
  Http::get(config('monitoring.heartbeat_url')))` sullo scheduler, puntato a
  un dead man's switch esterno (healthchecks.io ha un piano gratuito).
  Opzionale ma consigliato.

## Fuori scope

Deliberatamente non incluso, con la condizione che ne giustificherebbe
l'aggiunta:

- **Storico uptime e percentuali** — quando qualcuno chiederà un report o un
  SLA da dimostrare.
- **Scadenza certificati SSL** — è un check giornaliero, non al minuto:
  cadenza e modello diversi, progetto separato.
- **Webhook Discord per-target** — quando i target apparterranno a team o
  clienti con canali distinti.
- **Heartbeat monitoring dei cron applicativi** — quando serve sapere che un
  job è girato, non solo che l'host risponde.
- **Status page pubblica** — quando ci sarà un pubblico esterno da informare.
- **Metriche host (CPU/RAM/disco)** — richiede un agent sui VPS, è un altro
  prodotto.

## Conto finale

Sei file più una migration:

- `database/migrations/…_create_monitors_table.php`
- `app/Models/Monitor.php`
- `app/Enums/MonitorType.php`
- `app/Exceptions/MonitorCheckFailed.php`
- `app/Jobs/CheckMonitor.php`
- `app/Support/MonitorProbe.php`

più la Filament Resource generata, due righe in `routes/console.php` e due
file di test.
