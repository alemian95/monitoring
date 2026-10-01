<?php

namespace App\Enums;

enum MonitorType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
    case Dns = 'dns';

    /** MySQL/MariaDB, Postgres o SQL Server: la connessione e un `select 1`. */
    case Database = 'database';

    /** Risponde `+PONG` a un `PING`, dopo l'eventuale `AUTH`. */
    case Redis = 'redis';

    /** Il server saluta con 220 e accetta `EHLO` (e `STARTTLS`, se richiesto). */
    case Smtp = 'smtp';

    /**
     * Non contatta niente: e' giu' quando lo e' uno dei suoi figli. Riassume
     * un servizio fatto di piu' pezzi — sito, API, database — in uno stato.
     */
    case Group = 'group';

    /** L'unico invertito: non contattiamo il target, aspettiamo che ci chiami. */
    case Push = 'push';
}
