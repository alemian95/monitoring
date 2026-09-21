# TODO

Lacune rilevate confrontando questo monitoring con Uptime Kuma, UptimeRobot,
Better Stack, Zabbix e Checkly. Non tutto va colmato: dove i grandi vincono
per struttura e non per righe di codice, replicarli male e' peggio che non
averli.

## Fatto nel frattempo

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

## In valutazione

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

### Scala e retention

Un worker, drain seriale, SQLite, retention di 30 giorni senza rollup. Il
tetto non e' "quanti target ho" ma "quanti ne cadono insieme per il loro
timeout": con i numeri attuali (4 monitor, 152 ms di media) non e' vicino.
La leva, quando servira', e' un secondo `queue:work` schedulato con un
`--name` diverso — non una ristrutturazione.
