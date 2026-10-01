<?php

namespace App\Enums;

enum MonitorType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
    case Dns = 'dns';

    /** MySQL/MariaDB, Postgres o SQL Server: la connessione e un `select 1`. */
    case Database = 'database';

    /** L'unico invertito: non contattiamo il target, aspettiamo che ci chiami. */
    case Push = 'push';
}
