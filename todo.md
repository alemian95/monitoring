# TODO

Quello che resta. Fatti e in `master`: status code multipli, corpo atteso,
soglia sul tempo di risposta, storico per singolo check, dead man's switch,
re-alert orario finche' il target resta giu', scadenza dei certificati TLS.

## L'alert arriva a ~4 minuti, non a ~60 secondi — da progettare

Da quando il worker lo avvia lo scheduler, la catena di retry non sta piu'
dentro un solo giro: `queue:work --stop-when-empty` esce appena non c'e' un job
*disponibile*, e un retry in backoff ha `available_at` nel futuro. Il worker si
spegne, il tentativo dopo aspetta il worker del minuto successivo, e i quattro
tentativi si spalmano su ~4 minuti — mentre il docblock di
`CheckMonitor::$backoff` promette ancora ~60 secondi.

Una riga lo chiuderebbe: `--stop-when-empty-for=25`, cioe' piu' del backoff di
20 secondi, tiene il worker vivo attraverso la ritenta. Ma cambia il profilo
del processo — da "si spegne subito" a "resta acceso quasi tutto il minuto" —
e quello va deciso, non subito. Finche' non e' deciso, il docblock e il test
sul contratto di retry descrivono l'intenzione, non il comportamento.

## Evitare gli alert durante deploy e manutenzione — da valutare

I falsi allarmi durante un deploy sono il modo in cui un team impara a
ignorare gli alert.

Opzioni da confrontare:

- `is_active` a mano prima del deploy — costa zero, ma ci si dimentica di
  riattivarlo, che e' un failure mode peggiore del problema.
- Comando artisan tipo `monitor:silence --minutes=15`, richiamabile dallo
  script di deploy. Auto-scadente, quindi non ci si puo' dimenticare.
- Finestre di manutenzione ricorrenti su tabella.

Il comando artisan sembra il punto giusto: risolve il caso vero (il deploy)
senza inventare uno scheduler dentro lo scheduler. La dedup degli alert vive
gia' in cache (`monitor-down:{id}` in `CheckMonitor`): lo stesso store, con una
chiave a scadenza, coprirebbe il silenziamento senza una colonna in piu'.
