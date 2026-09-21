<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Fuori dai motori di ricerca: una pagina raggiunta con un link firmato
         non deve finire indicizzata, e nemmeno l'indice dei servizi. --}}
    <meta name="robots" content="noindex, nofollow">
    {{-- L'aggiornamento senza una riga di JavaScript, in un progetto che non ha
         una pipeline frontend. --}}
    <meta http-equiv="refresh" content="60">
    <title>Stato dei servizi — {{ $title }}</title>
    <style>
        :root {
            --bg: #fafafa;
            --card: #ffffff;
            --border: #e4e4e7;
            --text: #18181b;
            --muted: #71717a;
            --ok: #16a34a;
            --warn: #f59e0b;
            --down: #dc2626;
            --none: #d4d4d8;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #18181b;
                --card: #27272a;
                --border: #3f3f46;
                --text: #fafafa;
                --muted: #a1a1aa;
                --none: #52525b;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 3rem 1rem;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        main { max-width: 46rem; margin: 0 auto; }

        h1 { font-size: 1.4rem; margin: 0 0 1.5rem; }

        .banner {
            display: flex;
            align-items: center;
            gap: .6rem;
            margin: 0 0 2rem;
            padding: .9rem 1.1rem;
            border-radius: .5rem;
            font-weight: 600;
            color: #fff;
        }

        .banner.ok { background: var(--ok); }
        .banner.down { background: var(--down); }

        .service {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: .5rem;
            padding: 1.1rem 1.2rem;
            margin-bottom: .9rem;
        }

        .service header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 1rem;
            margin-bottom: .9rem;
        }

        .service h2 { font-size: 1rem; margin: 0; }

        .state { font-size: .8rem; font-weight: 600; }
        .state.ok { color: var(--ok); }
        .state.down { color: var(--down); }
        .state.none { color: var(--muted); }

        .bars { display: flex; gap: 2px; height: 2.1rem; }

        .bar { flex: 1; border-radius: 2px; background: var(--none); }
        .bar.ok { background: var(--ok); }
        .bar.warn { background: var(--warn); }
        .bar.down { background: var(--down); }

        .scale {
            display: flex;
            justify-content: space-between;
            margin-top: .5rem;
            font-size: .75rem;
            color: var(--muted);
        }

        .uptime { font-weight: 600; }
        .uptime.ok { color: var(--ok); }
        .uptime.warn { color: var(--warn); }
        .uptime.down { color: var(--down); }
        .uptime.none { color: var(--muted); }

        .empty, .updated {
            color: var(--muted);
            font-size: .8rem;
        }

        .updated { margin-top: 2rem; text-align: center; }
    </style>
</head>
<body>
<main>
    <h1>Stato dei servizi</h1>

    @if (filled($services))
        <p class="banner {{ $operational ? 'ok' : 'down' }}">
            {{ $operational ? 'Tutti i sistemi operativi' : 'Disservizio in corso' }}
        </p>
    @endif

    @forelse ($services as $service)
        <article class="service">
            <header>
                <h2>{{ $service['name'] }}</h2>
                @if ($service['isUp'] === null)
                    <span class="state none">Mai controllato</span>
                @else
                    <span class="state {{ $service['isUp'] ? 'ok' : 'down' }}">
                        {{ $service['isUp'] ? 'Operativo' : 'Non raggiungibile' }}
                    </span>
                @endif
            </header>

            <div class="bars">
                @foreach ($service['days'] as $day)
                    <span class="bar {{ $day['class'] }}" title="{{ $day['title'] }}"></span>
                @endforeach
            </div>

            <div class="scale">
                <span>{{ $range->label() }}</span>
                <span class="uptime {{ $service['level'] }}">
                    {{ $service['uptime'] === null ? 'nessun dato' : $service['uptime'].'% di uptime' }}
                </span>
                <span>oggi</span>
            </div>
        </article>
    @empty
        <p class="empty">Nessun servizio pubblicato.</p>
    @endforelse

    {{-- Il fuso e' quello dell'app, non quello di chi guarda: senza etichetta
         un orario su una pagina pubblica e' un orario sbagliato per qualcuno. --}}
    <p class="updated">Aggiornato alle {{ now()->format('H:i T') }}</p>
</main>
</body>
</html>
