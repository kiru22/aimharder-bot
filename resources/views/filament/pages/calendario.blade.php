<x-filament-panels::page>
    {{-- ===== HELPERS (computed en Blade) ===== --}}
    @php
        $occurrences = $this->occurrences();

        // Agrupar por fecha para la cuadrícula
        $byDate = $occurrences->groupBy('date');

        // Calcular primer día del mes y días del mes
        $monthCarbon = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $monthLabel  = ucfirst($monthCarbon->locale('es')->isoFormat('MMMM YYYY'));
        $daysInMonth = $monthCarbon->daysInMonth;

        // ISO weekday del primer día (1=Lun … 7=Dom)
        $firstDow = $monthCarbon->dayOfWeekIso;

        // Colores por estado
        $statusColors = [
            'booked'    => ['dot' => 'bg-green-500',  'badge' => 'bg-green-100 text-green-800'],
            'failed'    => ['dot' => 'bg-red-500',    'badge' => 'bg-red-100 text-red-800'],
            'scheduled' => ['dot' => 'bg-blue-500',   'badge' => 'bg-blue-100 text-blue-800'],
            'cancelled' => ['dot' => 'bg-gray-400',   'badge' => 'bg-gray-100 text-gray-600'],
            'already'   => ['dot' => 'bg-teal-500',   'badge' => 'bg-teal-100 text-teal-800'],
            'no_match'  => ['dot' => 'bg-amber-500',  'badge' => 'bg-amber-100 text-amber-800'],
            'skipped'   => ['dot' => 'bg-gray-400',   'badge' => 'bg-gray-100 text-gray-600'],
        ];

        $statusLabels = [
            'booked'    => 'Reservada',
            'failed'    => 'Fallida',
            'scheduled' => 'Programada',
            'cancelled' => 'Cancelada',
            'already'   => 'Ya reservada',
            'no_match'  => 'Sin clase',
            'skipped'   => 'Saltada',
        ];
    @endphp

    {{-- ===== SECCIÓN 1: CUADRÍCULA MENSUAL ===== --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 sm:p-4 shadow-sm">

        {{-- Cabecera: mes + botones ‹ › --}}
        <div class="flex items-center justify-between mb-3">
            <button
                wire:click="previousMonth"
                class="flex items-center justify-center w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-bold text-lg"
                aria-label="Mes anterior"
            >&#8249;</button>

            <h2 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-white capitalize">
                {{ $monthLabel }}
            </h2>

            <button
                wire:click="nextMonth"
                class="flex items-center justify-center w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-bold text-lg"
                aria-label="Mes siguiente"
            >&#8250;</button>
        </div>

        {{-- Cabecera días de la semana --}}
        <div class="grid grid-cols-7 gap-0.5 mb-1">
            @foreach(['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $dow)
                <div class="text-center text-xs font-medium text-gray-500 dark:text-gray-400 py-1">
                    {{ $dow }}
                </div>
            @endforeach
        </div>

        {{-- Cuadrícula de días --}}
        <div class="grid grid-cols-7 gap-0.5">
            {{-- Celdas vacías antes del primer día --}}
            @for ($i = 1; $i < $firstDow; $i++)
                <div class="min-h-[44px]"></div>
            @endfor

            {{-- Días del mes --}}
            @for ($day = 1; $day <= $daysInMonth; $day++)
                @php
                    $ymd     = $monthCarbon->copy()->setDay($day)->format('Y-m-d');
                    $dayOccs = $byDate->get($ymd, collect());
                    $isToday = $ymd === now(config('aimharder.timezone'))->format('Y-m-d');
                @endphp

                <div class="min-h-[44px] rounded-lg p-1 flex flex-col items-center {{ $isToday ? 'bg-blue-50 dark:bg-blue-900/30 ring-1 ring-blue-400' : 'bg-gray-50 dark:bg-gray-800/50' }}">
                    <span class="text-xs font-medium {{ $isToday ? 'text-blue-700 dark:text-blue-300 font-bold' : 'text-gray-700 dark:text-gray-300' }}">
                        {{ $day }}
                    </span>

                    {{-- Puntos de estado --}}
                    <div class="flex flex-wrap gap-0.5 justify-center mt-0.5">
                        @foreach($dayOccs as $occ)
                            @php $dotColor = $statusColors[$occ['status']]['dot'] ?? 'bg-gray-400'; @endphp
                            <span
                                class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"
                                title="{{ $occ['class_name'] }} — {{ $statusLabels[$occ['status']] ?? $occ['status'] }}"
                            ></span>
                        @endforeach
                    </div>
                </div>
            @endfor
        </div>

        {{-- Leyenda --}}
        <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1">
            @foreach($statusColors as $st => $colors)
                <div class="flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full {{ $colors['dot'] }}"></span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $statusLabels[$st] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ===== SECCIÓN 2: AGENDA / LISTA ===== --}}
    <div class="mt-4 space-y-2">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 px-1">
            Agenda — {{ $monthLabel }}
        </h3>

        @forelse($occurrences as $occ)
            @php
                $badgeCss  = $statusColors[$occ['status']]['badge'] ?? 'bg-gray-100 text-gray-600';
                $statusLbl = $statusLabels[$occ['status']] ?? $occ['status'];
            @endphp

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 shadow-sm">
                {{-- Cabecera de tarjeta --}}
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ $occ['class_name'] }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ \Illuminate\Support\Carbon::parse($occ['date'])->locale('es')->isoFormat('ddd D MMM') }}
                            &middot; {{ $occ['time'] }}
                            &middot; {{ $occ['account'] }}
                        </p>
                    </div>
                    <span class="shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeCss }}">
                        {{ $statusLbl }}
                    </span>
                </div>

                {{-- Botones de acción: scheduled --}}
                @if ($occ['status'] === 'scheduled')
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            wire:click="cancelDay({{ $occ['rule_id'] }}, '{{ $occ['date'] }}')"
                            wire:confirm="¿Cancelar la reserva del {{ $occ['date'] }}?"
                            class="flex-1 min-h-[40px] rounded-lg bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-xs font-medium px-3 hover:bg-red-100 dark:hover:bg-red-900/50"
                        >
                            Cancelar día
                        </button>
                        <button
                            wire:click="openChangeTime({{ $occ['rule_id'] }}, '{{ $occ['date'] }}')"
                            class="flex-1 min-h-[40px] rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 text-xs font-medium px-3 hover:bg-blue-100 dark:hover:bg-blue-900/50"
                        >
                            Cambiar hora
                        </button>
                    </div>
                @endif

                {{-- Botón de acción: cancelled --}}
                @if ($occ['status'] === 'cancelled')
                    <div class="mt-2">
                        <button
                            wire:click="reactivateDay({{ $occ['rule_id'] }}, '{{ $occ['date'] }}')"
                            wire:confirm="¿Reactivar la reserva del {{ $occ['date'] }}?"
                            class="w-full min-h-[40px] rounded-lg bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 text-xs font-medium px-3 hover:bg-green-100 dark:hover:bg-green-900/50"
                        >
                            Reactivar
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">
                Sin ocurrencias este mes.
            </p>
        @endforelse
    </div>

    {{-- ===== MODAL CAMBIO DE HORA ===== --}}
    @if ($showChangeTimeModal)
        <div
            class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 px-4 pb-4 sm:pb-0"
            wire:click.self="closeChangeTime"
        >
            <div class="w-full max-w-sm rounded-2xl bg-white dark:bg-gray-900 p-5 shadow-xl">
                <h4 class="text-base font-semibold text-gray-900 dark:text-white mb-1">
                    Cambiar hora
                </h4>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    {{ $changeTimeDate }}
                </p>

                <input
                    type="time"
                    wire:model="changeTimeValue"
                    class="block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 text-base focus:outline-none focus:ring-2 focus:ring-blue-500 mb-4"
                />

                <div class="flex gap-3">
                    <button
                        wire:click="closeChangeTime"
                        class="flex-1 min-h-[44px] rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800"
                    >
                        Cancelar
                    </button>
                    <button
                        wire:click="saveChangeTime"
                        class="flex-1 min-h-[44px] rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700"
                    >
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
