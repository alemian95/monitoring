# TODO

Quello che resta. Fatti e in `master`: status code multipli, corpo atteso,
soglia sul tempo di risposta, storico per singolo check, dead man's switch
(ping a ogni giro riuscito e su ogni job fallito), re-alert orario finche' il
target resta giu', scadenza dei certificati TLS, e la catena di retry riportata
dentro un solo giro dello scheduler.

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
