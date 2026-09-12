# TODO

Prossimi passi per rendere il monitoring piu' efficace. In ordine di priorita'.

## 1. Check della risposta attesa, oltre al solo status code

Oggi un `200` che serve "Database connection failed" passa come successo: e'
il falso negativo piu' comune. Serve un controllo opzionale sul corpo della
risposta (`expected_body_contains`), nullable — quando e' vuoto il
comportamento resta quello attuale.

Da decidere: solo substring o anche regex. La substring copre il 90% dei casi
e non ha modi di sbagliarsi.

## 2. Scadenza del certificato SSL

Un dominio "su" con il certificato che scade fra tre giorni e' un incidente
gia' in calendario. Il certificato e' gia' nello stream della richiesta HTTPS,
non serve una connessione in piu'.

Alert a soglia (es. 14 e 3 giorni), separato da quello di down: e' un avviso,
non un'emergenza.

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

## 4. Response time atteso, configurabile

Registrare i millisecondi in `monitor_checks` e fallire il check sopra una
soglia per-target. Il grafico che c'e' gia' mostrerebbe il degrado *prima* del
down: il sistema smette di essere solo reattivo.

Soglia nullable: senza valore si registra il tempo senza farci nulla.

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
