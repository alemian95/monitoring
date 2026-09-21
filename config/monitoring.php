<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention dello storico
    |--------------------------------------------------------------------------
    |
    | Per quanti giorni tenere le righe di `monitor_checks`. Un anno di default:
    | e' l'unita' con cui si parla di uptime a un cliente, e a un check al
    | minuto costa una settantina di byte per riga — poco piu' di un centinaio
    | di megabyte per target all'anno, che non vale un sistema di aggregati.
    |
    | Va tenuta almeno pari alla finestra piu' lunga di `UptimeRange`, o i
    | grafici mostreranno buchi dove i dati sono stati potati.
    |
    */

    'retention_days' => (int) env('MONITOR_RETENTION_DAYS', 365),

];
