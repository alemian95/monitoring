<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * L'estremo ricevente del push monitor: un job esterno chiama questo indirizzo
 * per dire che e' vivo, e il suo suffisso `/fail` per dire che e' andato male.
 *
 * Qui non si decide niente: si registra e basta. Se il job e' in ritardo lo
 * stabilisce `MonitorProbe::checkPush()` al giro successivo, con lo stesso
 * percorso di retry, alert e recovery di tutti gli altri tipi.
 */
class PingController extends Controller
{
    public function __invoke(Request $request, string $token, ?string $status = null): Response
    {
        $monitor = Monitor::query()->where('ping_token', $token)->firstOrFail();

        $monitor->update([
            'last_ping_at' => now(),
            // Il corpo della richiesta diventa il motivo del fallimento: un
            // `curl -d "$(tail -1 backup.log)"` racconta molto piu' di un flag.
            'last_ping_failure' => $status === null
                ? null
                : Str::limit(trim($request->getContent()) ?: 'Il job ha segnalato un fallimento', 255),
        ]);

        return response('OK');
    }
}
