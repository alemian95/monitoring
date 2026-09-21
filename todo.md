# TODO

Lacune rilevate confrontando questo monitoring con Uptime Kuma, UptimeRobot,
Better Stack, Zabbix e Checkly. Non tutto va colmato: dove i grandi vincono
per struttura e non per righe di codice, replicarli male e' peggio che non
averli.

## Da fare

### Status page pubblica

Oggi `routes/web.php` serve ancora la welcome di Laravel. E' la funzione con
cui Kuma, Better Stack e StatusCake si presentano: e' quello che mostri al
cliente invece di rispondere alle mail.

I dati ci sono gia' tutti — `Monitor::uptimeSeries()` e `responseTimeSeries()`
sono le stesse serie che alimentano i grafici del pannello. Manca solo la
vista: una rotta Blade, nessun layer nuovo.

### Piu' protocolli

Oggi solo HTTP e TCP. Mancano, in ordine di utilita' reale:

- **push monitor** (heartbeat in ingresso): una rotta che un cron esterno
  chiama, e un alert se non chiama piu'. Paradossale che manchi, visto che
  questo software un heartbeat lo *manda* gia' (`Support\Heartbeat`) ma non
  sa riceverne. Una rotta piu' una colonna.
- **DNS**: un record che cambia o sparisce non lo vede nessun check HTTP.
- **ICMP**: il ping vero richiede `exec()` e privilegi, ed e' filtrato su
  molti hosting — vedi la nota gia' scritta in `MonitorProbe::checkTcp()`.
- SMTP/IMAP, gRPC: solo se compaiono target che li parlano.

### API, multi-utente, report

Tre cose separate che oggi mancano tutte e tre:

- nessuna API: i monitor si creano solo dalla UI Filament, quindi niente
  gestione da script o da un altro servizio;
- un utente solo e nessuna policy: non c'e' modo di dare accesso a qualcuno
  senza dargli tutto;
- nessun report SLA mensile, nessuna timeline degli incidenti, nessuna
  annotazione post-mortem. Lo storico c'e' (`monitor_checks`), manca la
  lettura che lo racconta come incidenti invece che come righe.

Da fare a pezzi, non in blocco: l'API ha senso quando c'e' qualcosa che la
chiama, le policy quando c'e' un secondo utente.

### Baseline sui tempi di risposta

Oggi `max_response_time_ms` e' un numero fisso che scegli a mano, per target.
Se lo metti stretto genera rumore, se lo metti largo non scatta mai.

Un target sano ha un profilo: la sua normalita' e' nei dati che stiamo gia'
salvando. Allertare su "il p95 di oggi e' tre volte quello dell'ultima
settimana" e' la stessa query del grafico dei tempi di risposta, con un
percentile al posto della media. Da capire se conviene come *sostituto* della
soglia o come segnale in piu' accanto ad essa.

## In valutazione

### Punto di osservazione singolo

La lacuna piu' grave in assoluto. Un solo nodo, una sola rete: un problema di
rotta del tuo ISP, o un firewall che blocca te e non il mondo, sono
indistinguibili da un target giu'. Pingdom e UptimeRobot dichiarano down solo
con l'accordo di N nodi su M; i quattro tentativi mitigano il rumore breve,
non questo.

Multi-regione fatto in casa non vale la candela. La via onesta e' un secondo
osservatore che non sia questo software — un UptimeRobot free sul target piu'
critico — e non richiede scrivere niente.

### Escalation e reperibilita'

Un solo canale, Discord. Manca "se nessuno risponde in cinque minuti chiama il
telefono", manca il turno di on-call, manca l'acknowledgement ("me ne sto
occupando io") che zittisce i promemoria. Il re-alert orario in
`CheckMonitor` e' escalation da poveri.

Il pezzo economico e' il secondo canale (una mail dopo N promemoria inevasi:
Discord alle tre di notte lo silenzi, la mail resta). L'acknowledgement invece
vuole interazione — un bot Discord — ed e' un ordine di grandezza sopra.

### HTTP oltre il GET

`MonitorProbe::checkHttp()` fa `Http::timeout(...)->get($target)`: niente
metodo, niente header, niente body. Un endpoint che vuole un `Authorization`
non e' monitorabile.

Nel confronto era il primo per valore/riga — tre colonne e un pass-through a
`Http::` — ed e' il tetto piu' basso della superficie HTTP attuale. Resta qui
finche' non compare un target che lo richiede davvero.

### Check multi-step

Checkly e Pingdom eseguono un browser vero: login, carrello, checkout. "Il
sito risponde 200" non dice che il login e' rotto. Costoso e fuori scala
rispetto a tutto il resto del sistema.

### Dipendenze fra target

Se cade il router, Zabbix manda un alert; questo ne manda uno per target. Con
4 monitor non si nota, con 40 diventa il motivo per cui si smette di guardare
gli alert.

### Scala e retention

Un worker, drain seriale, SQLite, retention di 30 giorni senza rollup. Il
tetto non e' "quanti target ho" ma "quanti ne cadono insieme per il loro
timeout": con i numeri attuali (4 monitor, 152 ms di media) non e' vicino.
La leva, quando servira', e' un secondo `queue:work` schedulato con un
`--name` diverso — non una ristrutturazione.
