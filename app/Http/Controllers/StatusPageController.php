<?php

namespace App\Http\Controllers;

use App\Enums\UptimeLevel;
use App\Enums\UptimeRange;
use App\Models\Monitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Il riepilogo interno di tutti i servizi e la pagina del singolo servizio,
 * che e' l'unica cosa che esce di qui: quella in chiaro se il servizio e'
 * pubblico, quella dietro firma se ha un link firmato.
 *
 * Fuori non esiste un elenco. Chi non e' autenticato puo' vedere il servizio
 * di cui ha l'indirizzo, non sapere quali altri ce ne sono.
 */
class StatusPageController extends Controller
{
    /**
     * La pagina si ricarica da sola ogni minuto e i check girano al minuto:
     * una fotografia piu' fresca di cosi' non direbbe niente di nuovo, e un URL
     * pubblico lo puo' colpire chiunque quante volte vuole.
     */
    private const CACHE_SECONDS = 60;

    private const RANGE = UptimeRange::Month;

    /**
     * Tutti i servizi, visibilita' compresa: la rotta e' dietro `auth`.
     */
    public function index(): View
    {
        return $this->page('Stato dei servizi', Monitor::query()->orderBy('name')->get());
    }

    public function show(Request $request, Monitor $monitor): View
    {
        // Chi e' autenticato ha gia' accesso a tutto dal pannello: la
        // visibilita' regola chi arriva da fuori, non chi e' dentro.
        $denial = $request->user() === null
            ? $monitor->visibility->denialStatus($request)
            : null;

        if ($denial !== null) {
            abort($denial, $denial === 403 ? 'Questo link non è più valido.' : '');
        }

        return $this->page($monitor->name, collect([$monitor]));
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     */
    private function page(string $title, Collection $monitors): View
    {
        $services = $this->summarise($monitors);
        $operational = collect($services)->every(fn (array $service): bool => $service['isUp'] !== false);
        $single = count($services) === 1;

        return view('status', [
            'title' => $title,
            'services' => $services,
            'range' => self::RANGE,
            'banner' => [
                'class' => $operational ? 'ok' : 'down',
                'text' => match (true) {
                    $operational && $single => 'Servizio operativo',
                    $operational => 'Tutti i sistemi operativi',
                    $single => 'Servizio non raggiungibile',
                    default => 'Disservizio in corso',
                },
            ],
        ]);
    }

    /**
     * Riduce i monitor a quello che la pagina disegna: niente URL dei target,
     * niente motivi di fallimento, niente che non sia gia' visibile a chi
     * guarda il servizio da fuori.
     *
     * La chiave della cache elenca gli id, cosi' pubblicare o togliere un
     * servizio la invalida da solo senza bisogno di ricordarselo.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return list<array{name: string, isUp: bool|null, uptime: float|null, level: string, days: list<array{label: string, class: string, title: string}>}>
     */
    private function summarise(Collection $monitors): array
    {
        return Cache::remember(
            'status-page:'.$monitors->pluck('id')->join('-'),
            self::CACHE_SECONDS,
            fn (): array => $monitors->map(fn (Monitor $monitor): array => [
                'name' => $monitor->name,
                'isUp' => $monitor->is_up,
                'uptime' => $uptime = $monitor->uptimePercentage(self::RANGE),
                'level' => UptimeLevel::for($uptime)->cssClass(),
                'days' => $this->days($monitor),
            ])->all(),
        );
    }

    /**
     * Un elemento per giorno della finestra, dal piu' vecchio al piu' recente.
     *
     * @return list<array{label: string, class: string, title: string}>
     */
    private function days(Monitor $monitor): array
    {
        return $monitor->uptimeSeries(self::RANGE)
            ->map(function (?float $uptime, string $day): array {
                $label = Carbon::parse($day)->format(self::RANGE->labelFormat());

                return [
                    'label' => $label,
                    'class' => UptimeLevel::for($uptime)->cssClass(),
                    'title' => $uptime === null
                        ? "{$label}: nessun dato"
                        : "{$label}: {$uptime}%",
                ];
            })
            ->values()
            ->all();
    }
}
