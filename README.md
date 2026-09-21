# Monitoring

Controlla domini (status HTTP fra quelli accettati, testo atteso nel corpo,
tempo di risposta sotto soglia) e VPS (porta TCP) su un intervallo per-target
e avvisa un canale Discord quando un target è giù, con recovery quando torna
su. Di ogni check restano status, tempo di risposta e motivo del fallimento.
Dal primo errore all'alert passano ~35 secondi, il tempo di quattro tentativi.
Finché un target resta giù l'alert si ripete ogni ora. Un comando giornaliero
avvisa quattordici giorni prima che scada un certificato TLS. Gestione dei
target via Filament (`/admin`), pagina di stato per singolo servizio.

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
