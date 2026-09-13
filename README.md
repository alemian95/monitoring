# Monitoring

Controlla domini (status HTTP fra quelli accettati, testo atteso nel corpo,
tempo di risposta sotto soglia) e VPS (porta TCP) su un intervallo per-target
e avvisa un canale Discord quando un target è giù, con recovery quando torna
su. Di ogni check restano status, tempo di risposta e motivo del fallimento.
Gestione dei target via Filament (`/admin`).

## In sviluppo

```bash
php artisan dev
```

Avvia tutto quello che serve in un colpo: scheduler, queue worker, server e
log. Lo scheduler e' registrato in `AppServiceProvider::boot()`, perche' senza
di lui nessun check parte.

## In produzione

Servono due processi, oltre all'app:

- una entry cron che invochi `php artisan schedule:run` ogni minuto;
- un `php artisan queue:work` persistente (es. Supervisor), che esegue i check e i retry.

E una variabile in `.env`: `DISCORD_ALERT_WEBHOOK`.

**Senza il worker attivo i job restano in coda in silenzio: nessun check
viene eseguito e nessun alert parte, senza errori visibili.**

Dettagli architetturali, policy di retry e note operative:
[`docs/superpowers/specs/2026-09-08-monitoring-uptime-design.md`](docs/superpowers/specs/2026-09-08-monitoring-uptime-design.md).
