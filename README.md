# Monitoring

Controlla quattro tipi di target su un intervallo per-target e avvisa un canale
Discord quando uno e' giu', con recovery quando torna su:

- **HTTP** — metodo, header e corpo a scelta; status fra quelli accettati,
  testo atteso nel corpo, tempo di risposta sotto soglia;
- **TCP** — la porta accetta connessioni;
- **DNS** — il record esiste e, volendo, contiene ancora il valore atteso;
- **push** — il contrario degli altri: un job esterno chiama noi, e se smette
  di farlo e' lui a risultare giu'.

Di ogni check restano status, tempo di risposta e motivo del fallimento. Dal
primo errore all'alert passano ~35 secondi, il tempo di quattro tentativi.
Finche' un target resta giu' l'alert si ripete ogni ora. Un comando giornaliero
avvisa quattordici giorni prima che scada un certificato TLS, e uno orario
quando un target rallenta rispetto alla sua normalita'. Gestione dei
target via Filament (`/admin`), pagina di stato per singolo servizio.

## Push monitor

Per sorvegliare quello che nessuno puo' interrogare da fuori: un backup
notturno, un import, una coda. Il monitor espone due indirizzi, che l'azione
*URL di ping* nella tabella mostra:

```bash
curl -fsS https://monitoring.example/ping/TOKEN                   # sono vivo
curl -fsS https://monitoring.example/ping/TOKEN/fail -d "$MOTIVO" # sono andato male
```

*Attendo un ping ogni* dice quanto puo' passare fra due ping prima che il job
risulti giu'; e' un'altra cosa da *ogni quanto verifico il ritardo*, che e'
solo la frequenza con cui ce ne accorgiamo. Il corpo della richiesta a `/fail`
diventa il motivo del fallimento nell'alert.

Il segreto e' il token nell'URL, non una firma: finisce nella crontab di
qualcun altro, e se trapela va revocato da solo — l'azione *Rigenera URL di
ping* lo sostituisce e spegne il vecchio.

## Disservizi

Lo storico e' una colonna di booleani; letto come eventi diventa «giu' 47
minuti dalle 03:12, per timeout». Gli incidenti non hanno una tabella: sono
serie consecutive di check falliti, ricostruite con una window function che
restituisce solo i cambi di stato — su un mese a un check al minuto sono una
manciata di righe invece di quarantamila.

Si vedono in due posti. Nel pannello, sotto i grafici del monitor, con il
motivo del fallimento. Sulla pagina di stato, sotto le barre, **senza** il
motivo: «TCP 10.0.0.5:5432 — Connection refused» racconta a un estraneo com'e'
fatta la rete dentro, mentre quanto e' durato lo puo' sapere, perche' lo ha
subito.

Il tetto e' la retention: 30 giorni, quindi un SLA mensile si legge e uno
annuale no. Annotare un post-mortem richiederebbe invece una tabella, perche'
una nota va appesa a qualcosa che resta.

## Rallentamenti

`max_response_time_ms` e' una soglia scelta a mano: stretta fa rumore, larga
non scatta mai. Accanto a quella, `monitor:latency` ogni ora confronta il p95
dell'ultima ora con il p95 della settimana precedente, e avvisa se e'
almeno triplicato **e** cresciuto di almeno 250 ms — il solo moltiplicatore,
su un target da 30 ms, segnalerebbe differenze che non nota nessuno.

La soglia se la scrive il target da solo, quindi non c'e' niente da
configurare. Non tocca `is_up`: un target lento non e' un target giu', e
contarlo come tale falserebbe l'uptime. Il promemoria si ripete ogni sei ore
finche' dura.

Serve storico: dieci check nell'ultima ora e cento nella settimana. Un target
controllato di rado non li raggiunge e resta con la sola soglia fissa.

## Pagina di stato

Fuori dal pannello si vede **un servizio alla volta**, su `/status/{id}`: non
esiste un elenco pubblico, quindi il link che mandi a un cliente mostra il suo
servizio e niente altro. Il riepilogo di tutti i servizi sta su `/status` ed e'
dietro il login del pannello.

Chi puo' vedere un servizio da fuori lo decide il campo *Pagina di stato*:

- **Solo dal pannello** (default): `/status/{id}` risponde 404. Un servizio che
  non hai pubblicato non conferma nemmeno di esistere.
- **Pubblica**: si apre in chiaro a chi ne conosce l'indirizzo.
- **Solo con link firmato**: si apre solo con il link generato dall'azione
  *Link firmato* nella tabella dei monitor, che chiede la scadenza (fino a
  «nessuna»).

**La firma e' calcolata sull'URL assoluto: in produzione `APP_URL` deve essere
quello vero, o i link firmati non valideranno.**

La pagina e' un solo Blade senza JavaScript e si ricarica da sola ogni minuto;
i dati sono in cache per 60 secondi.

## In sviluppo

```bash
php artisan dev
```

Avvia tutto quello che serve in un colpo: scheduler, queue worker, server e
log. Lo scheduler e' registrato in `AppServiceProvider::boot()`, perche' senza
di lui nessun check parte.

## In produzione

Serve una entry cron che invochi `php artisan schedule:run` ogni minuto: il
queue worker lo avvia lo scheduler stesso, un minuto alla volta.

Due variabili in `.env`:

- `DISCORD_ALERT_WEBHOOK` — il canale dove arrivano gli alert;
- `HEALTHCHECKS_PING_URL` — opzionale ma consigliata: lo scheduler la pinga a
  ogni giro riuscito e ne pinga l'endpoint `/fail` a ogni job fallito. Se i
  ping smettono è il servizio esterno (es. healthchecks.io) ad avvisare: è
  l'unico modo per accorgersi che è morto il monitoring e non i target, e
  l'unico canale per sapere che un alert Discord non è stato consegnato.

**Senza cron non parte niente, e in silenzio: nessun check, nessun alert,
nessun errore visibile.**

Dettagli architetturali, policy di retry e note operative:
[`docs/superpowers/specs/2026-09-08-monitoring-uptime-design.md`](docs/superpowers/specs/2026-09-08-monitoring-uptime-design.md).
