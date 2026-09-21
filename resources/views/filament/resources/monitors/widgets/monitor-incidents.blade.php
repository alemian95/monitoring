<x-filament-widgets::widget>
    <x-filament::section :heading="'Disservizi — '.$range->label()">
        @if ($incidents->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nessun disservizio nel periodo.</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                @foreach ($incidents as $incident)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2">
                        <span class="font-medium tabular-nums">{{ $incident->startedAt->format('d/m/Y H:i') }}</span>

                        @if ($incident->isOngoing())
                            <span class="font-semibold text-danger-600 dark:text-danger-400">
                                in corso da {{ $incident->duration() }}
                            </span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">{{ $incident->duration() }}</span>
                        @endif

                        @if (filled($incident->reason))
                            <span class="w-full truncate text-gray-500 dark:text-gray-400 sm:w-auto">
                                {{ $incident->reason }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
