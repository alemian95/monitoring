<?php

namespace App\Http\Controllers;

use App\Enums\MonitorVisibility;
use App\Enums\UptimeLevel;
use App\Enums\UptimeRange;
use App\Models\Monitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * La pagina di stato pubblica: l'indice dei servizi pubblicati e la pagina del
 * singolo servizio, che e' anche quella che si raggiunge con un link firmato.
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

    public function index(): View
    {
        $monitors = Monitor::query()
            ->where('visibility', MonitorVisibility::Public)
            ->orderBy('name')
            ->get();

        return $this->page(config('app.name'), $monitors);
    }

    public function show(Request $request, Monitor $monitor): View
    {
        $denial = $monitor->visibility->denialStatus($request);

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

        return view('status', [
            'title' => $title,
            'services' => $services,
            'range' => self::RANGE,
            'operational' => collect($services)->every(fn (array $service): bool => $service['isUp'] !== false),
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
