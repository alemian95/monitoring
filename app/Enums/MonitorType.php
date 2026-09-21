<?php

namespace App\Enums;

enum MonitorType: string
{
    case Http = 'http';
    case Tcp = 'tcp';
    case Dns = 'dns';

    /** L'unico invertito: non contattiamo il target, aspettiamo che ci chiami. */
    case Push = 'push';
}
