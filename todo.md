# TODO

Lacune rilevate confrontando questo monitoring con Uptime Kuma, UptimeRobot,
Better Stack, Zabbix e Checkly. Non tutto va colmato: dove i grandi vincono
per struttura e non per righe di codice, replicarli male e' peggio che non
averli.

## Fatto nel frattempo

- **Storico di un anno** — retention in `MONITOR_RETENTION_DAYS`, piu' le
  finestre trimestre e anno. Niente rollup: le righe grezze costano ~37 MB per
  target all'anno.
- **Timeline degli incidenti** — lo storico letto come eventi invece che come
  righe, nel pannello (con il motivo) e sulla pagina di stato (senza). Nessuna
  tabella: sono serie consecutive di check falliti.
- **Baseline sui tempi di risposta** — `monitor:latency` confronta ogni ora il
  p95 dell'ultima ora con quello della settimana precedente. Segnale accanto
  alla soglia fissa, non un suo sostituto: non tocca `is_up`.
- **Push monitor** — una rotta con token che i job esterni chiamano, e un
  alert se smettono o se dichiarano `/fail`.
- **DNS** — il record esiste e contiene ancora il valore atteso.
- **HTTP oltre il GET** — metodo, header cifrati a riposo, corpo.
- **Pagina di stato** — una pagina per servizio, con la visibilita' decisa sul
  singolo (pannello / in chiaro / link firmato a scadenza). Fuori dal pannello
  non esiste un elenco, cosi' il link che mandi a un cliente mostra il suo
  servizio e non rivela gli altri; il riepilogo di tutti i servizi e' su
  `/status`, dietro il login.

## Da fare

### SQL specifico di SQLite, ma la produzione non usa SQLite

`uptimeSeries()` e `responseTimeSeries()` raggruppano con `strftime`, che
fuori da SQLite non esiste: su MySQL e' `DATE_FORMAT`, su Postgres `to_char`,
con token diversi. `UptimeRange::sqlFormat()` e' il punto unico da tradurre —
era gia' marcato come tale, ma da nota diventa lavoro nel momento in cui il
database di produzione e' un altro.

Su Postgres si aggiunge un secondo punto: `avg(is_up)` e `sum(is_up)` su una
colonna booleana non sono ammessi e vanno castati.

Il resto e' portabile: la window function degli incidenti gira su MySQL 8 e
Postgres, e il percentile e' ordinamento piu' offset.

## In valutazione

### API

Nessuno la chiama, e un'API si progetta attorno al suo consumatore: senza,
si espone il CRUD dei monitor e si scopre dopo che serviva un'altra forma. Il
trigger e' preciso — quando un deploy script dovra' creare o silenziare un
monitor da solo. Allora sono tre rotte, non un layer.

### Multi-utente, policy, audit

Un utente solo: le policy proteggerebbero te da te stesso e l'audit
registrerebbe una colonna di righe uguali. Il caso che di solito spinge al
multi-utente — dare accesso a un cliente senza dargli tutto — e' gia' coperto
dal link firmato per servizio. Il trigger e' il secondo operatore.

### Annotazioni post-mortem

La timeline degli incidenti c'e' e lo storico arriva all'anno. Resta fuori
l'annotazione: vuole una tabella, perche' una nota va appesa a un incidente
con un'identita' che resta.

### Protocolli che restano fuori

- **ICMP**: il ping vero richiede `exec()` e privilegi, ed e' filtrato su
  molti hosting — vedi la nota gia' scritta in `MonitorProbe::checkTcp()`.
- SMTP/IMAP, gRPC: il TCP connect dice gia' che la porta risponde; l'handshake
  vero solo se compaiono target che lo pretendono.

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

### Check multi-step

Checkly e Pingdom eseguono un browser vero: login, carrello, checkout. "Il
sito risponde 200" non dice che il login e' rotto. Costoso e fuori scala
rispetto a tutto il resto del sistema.

### Dipendenze fra target

Se cade il router, Zabbix manda un alert; questo ne manda uno per target. Con
4 monitor non si nota, con 40 diventa il motivo per cui si smette di guardare
gli alert.

### Scala

Un worker, drain seriale. Il tetto non e' "quanti target ho" ma "quanti ne
cadono insieme per il loro timeout": con i numeri attuali non e' vicino. La
leva, quando servira', e' un secondo `queue:work` schedulato con un `--name`
diverso — non una ristrutturazione.

La retention non e' piu' un problema: un anno di righe grezze costa ~37 MB per
target, e il rollup esisteva per risparmiare spazio che non manca. Torna in
gioco solo se i target si moltiplicano per dieci.
