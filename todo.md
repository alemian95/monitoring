# TODO

Prossimi passi per rendere il monitoring piu' efficace. In ordine di priorita'.

I punti 1 e 4 sono fatti: status code multipli, corpo atteso, tempo di
risposta con soglia, e status/tempo/motivo salvati per ogni check. La
numerazione dei rimanenti resta quella originale.

## 2. Scadenza del certificato SSL

Un dominio "su" con il certificato che scade fra tre giorni e' un incidente
gia' in calendario.

Due cose emerse valutandolo, da non ri-scoprire:

- **Non va nel probe.** Un certificato non scade fra un minuto e l'altro:
  metterlo nel check per-minuto significa una connessione TLS in piu' ogni
  sessanta secondi per un dato che cambia una volta ogni tre mesi. Comando
  artisan giornaliero, cosi' e' anche lanciabile a mano.
- **E' previsione, non rilevamento.** Un certificato gia' scaduto fa fallire
  la verifica TLS di Guzzle, quindi oggi genera gia' un alert di down. Questa
  feature serve a sapere che scade fra quattordici giorni.

Lettura con `stream_socket_client` su `ssl://host:443` e `capture_peer_cert`,
con `verify_peer => false`: si sta leggendo una data, non fidandosi della
connessione, e senza quel flag un certificato gia' scaduto non si riuscirebbe
nemmeno a leggere.

Dedup con due colonne — `certificate_expires_at` e `certificate_alerted_at`:
alert una volta sola sotto soglia, e quando la scadenza letta e' diversa da
quella salvata il certificato e' stato rinnovato e si riparte.

## 3. Dead man's switch — da valutare

Il buco piu' grosso del sistema: se il worker o lo scheduler muoiono, non
arriva niente, e il silenzio e' indistinguibile da "tutto ok". Stesso failure
mode gia' risolto per Discord in `SendDiscordAlert`, ma un livello sopra.

Due approcci, coprono buchi diversi:

- **Esterno (healthchecks.io)** — lo scheduler fa un ping ogni minuto, se
  smette avvisano loro. Copre anche il caso "tutto il server e' giu'".
  Introduce una dipendenza esterna.
- **Interno (stale check)** — un task periodico che cerca monitor attivi con
  `last_checked_at` piu' vecchio di `interval_minutes * 3` e alza un alert.
  Zero dipendenze, ma muore insieme al resto.

Da valutare se prenderli entrambi.

## 5. Re-alert finche' il target resta giu'

Oggi parte un solo messaggio quando un target cade. Se passa inosservato alle
tre di notte non torna piu'.

- Dopo il primo alert, un promemoria a intervallo configurabile (default
  un'ora) finche' il monitor resta giu'.
- Al recovery si azzera tutto: intervallo di check e cadenza dei re-alert
  tornano ai valori normali del monitor.

Serve una colonna tipo `last_alerted_at` per sapere quando e' partito
l'ultimo.

## 6. Evitare gli alert durante deploy e manutenzione — da valutare

I falsi allarmi durante un deploy sono il modo in cui un team impara a
ignorare gli alert.

Opzioni da confrontare:

- `is_active` a mano prima del deploy — costa zero, ma ci si dimentica di
  riattivarlo, che e' un failure mode peggiore del problema.
- Comando artisan tipo `monitor:silence --minutes=15`, richiamabile dallo
  script di deploy. Auto-scadente, quindi non ci si puo' dimenticare.
- Finestre di manutenzione ricorrenti su tabella.

Il comando artisan sembra il punto giusto: risolve il caso vero (il deploy)
senza inventare uno scheduler dentro lo scheduler.
