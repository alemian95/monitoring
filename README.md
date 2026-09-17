# Monitoring

Controlla domini (status HTTP fra quelli accettati, testo atteso nel corpo,
tempo di risposta sotto soglia) e VPS (porta TCP) su un intervallo per-target
e avvisa un canale Discord quando un target è giù, con recovery quando torna
su. Di ogni check restano status, tempo di risposta e motivo del fallimento.
Finché un target resta giù l'alert si ripete ogni ora. Un comando giornaliero
avvisa quattordici giorni prima che scada un certificato TLS. Gestione dei
target via Filament (`/admin`).

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
  ogni giro riuscito, e se i ping smettono è il servizio esterno (es.
  healthchecks.io) ad avvisare. È l'unico modo per accorgersi che è morto il
  monitoring e non i target.

**Senza cron non parte niente, e in silenzio: nessun check, nessun alert,
nessun errore visibile.**

Dettagli architetturali, policy di retry e note operative:
[`docs/superpowers/specs/2026-09-08-monitoring-uptime-design.md`](docs/superpowers/specs/2026-09-08-monitoring-uptime-design.md).
