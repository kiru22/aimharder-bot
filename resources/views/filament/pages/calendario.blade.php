<x-filament-panels::page>
    @php
        $occurrences = $this->occurrences();
        $byDate      = $occurrences->groupBy('date');

        $monthCarbon = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $monthLabel  = ucfirst($monthCarbon->locale('es')->isoFormat('MMMM YYYY'));
        $daysInMonth = $monthCarbon->daysInMonth;
        $firstDow    = $monthCarbon->dayOfWeekIso; // 1=Lun … 7=Dom
        $todayYmd    = now(config('aimharder.timezone'))->format('Y-m-d');

        $statusColors = [
            'booked'    => '#22c55e',
            'failed'    => '#ef4444',
            'scheduled' => '#3b82f6',
            'cancelled' => '#9ca3af',
            'already'   => '#14b8a6',
            'no_match'  => '#f59e0b',
            'skipped'   => '#9ca3af',
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

    <style>
        .ah-cal { display: flex; flex-direction: column; gap: 1rem; }
        .ah-card { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); border-radius: 14px; padding: .85rem; }
        .ah-cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .75rem; }
        .ah-cal-title { font-size: 1.05rem; font-weight: 600; text-transform: capitalize; color: #f3f4f6; }
        .ah-nav { width: 42px; height: 42px; border: none; border-radius: 10px; background: rgba(255,255,255,.08); color: #e5e7eb; font-size: 1.3rem; line-height: 1; cursor: pointer; }
        .ah-nav:hover { background: rgba(255,255,255,.16); }
        .ah-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .ah-dow { text-align: center; font-size: .68rem; font-weight: 600; color: #9ca3af; padding: 2px 0; text-transform: uppercase; }
        .ah-cell { min-height: 48px; border-radius: 9px; padding: 3px; display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,.05); }
        .ah-cell--empty { background: transparent; }
        .ah-cell--today { background: rgba(59,130,246,.22); box-shadow: inset 0 0 0 1.5px #3b82f6; }
        .ah-num { font-size: .72rem; font-weight: 600; color: #d1d5db; }
        .ah-num--today { color: #93c5fd; font-weight: 700; }
        .ah-dots { display: flex; flex-wrap: wrap; gap: 2px; justify-content: center; margin-top: 2px; }
        .ah-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
        .ah-legend { display: flex; flex-wrap: wrap; gap: .35rem .9rem; margin-top: .75rem; }
        .ah-legend span { display: inline-flex; align-items: center; gap: 5px; font-size: .68rem; color: #9ca3af; }
        .ah-section-title { font-size: .9rem; font-weight: 600; color: #d1d5db; margin: .25rem .25rem 0; }
        .ah-occ { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; }
        .ah-occ-class { font-size: .92rem; font-weight: 600; color: #f3f4f6; }
        .ah-occ-meta { font-size: .72rem; color: #9ca3af; margin-top: 2px; }
        .ah-badge { flex-shrink: 0; font-size: .68rem; font-weight: 600; padding: 2px 9px; border-radius: 999px; color: #fff; }
        .ah-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .6rem; }
        .ah-btn { flex: 1 1 auto; min-height: 42px; border: none; border-radius: 10px; font-size: .8rem; font-weight: 600; cursor: pointer; padding: 0 .75rem; }
        .ah-btn--danger { background: rgba(239,68,68,.18); color: #fca5a5; }
        .ah-btn--info   { background: rgba(59,130,246,.18); color: #93c5fd; }
        .ah-btn--ok     { background: rgba(34,197,94,.18); color: #86efac; width: 100%; }
        .ah-empty { text-align: center; color: #6b7280; font-size: .85rem; padding: 1.5rem 0; }
        .ah-modal { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,.55); padding: 1rem; }
        .ah-modal-box { width: 100%; max-width: 360px; background: #1f2937; border-radius: 18px; padding: 1.25rem; box-shadow: 0 20px 50px rgba(0,0,0,.5); }
        .ah-modal-title { font-size: 1rem; font-weight: 600; color: #f3f4f6; }
        .ah-modal-sub { font-size: .75rem; color: #9ca3af; margin: 2px 0 1rem; }
        .ah-input { width: 100%; border: 1px solid rgba(255,255,255,.15); background: rgba(0,0,0,.25); color: #f3f4f6; border-radius: 10px; padding: .55rem .7rem; font-size: 1rem; margin-bottom: 1rem; }
        .ah-modal-actions { display: flex; gap: .75rem; }
        .ah-btn--ghost { flex: 1; min-height: 44px; background: transparent; border: 1px solid rgba(255,255,255,.15); color: #e5e7eb; border-radius: 10px; }
        .ah-btn--primary { flex: 1; min-height: 44px; background: #2563eb; color: #fff; border: none; border-radius: 10px; font-weight: 600; }
    </style>

    <div class="ah-cal">
        {{-- ===== CUADRÍCULA MENSUAL ===== --}}
        <div class="ah-card">
            <div class="ah-cal-head">
                <button type="button" class="ah-nav" wire:click="previousMonth" aria-label="Mes anterior">&#8249;</button>
                <div class="ah-cal-title">{{ $monthLabel }}</div>
                <button type="button" class="ah-nav" wire:click="nextMonth" aria-label="Mes siguiente">&#8250;</button>
            </div>

            <div class="ah-grid" style="margin-bottom:4px;">
                @foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $dow)
                    <div class="ah-dow">{{ $dow }}</div>
                @endforeach
            </div>

            <div class="ah-grid">
                @for ($i = 1; $i < $firstDow; $i++)
                    <div class="ah-cell ah-cell--empty"></div>
                @endfor

                @for ($day = 1; $day <= $daysInMonth; $day++)
                    @php
                        $ymd     = $monthCarbon->copy()->setDay($day)->format('Y-m-d');
                        $dayOccs = $byDate->get($ymd, collect());
                        $isToday = $ymd === $todayYmd;
                    @endphp
                    <div class="ah-cell {{ $isToday ? 'ah-cell--today' : '' }}">
                        <span class="ah-num {{ $isToday ? 'ah-num--today' : '' }}">{{ $day }}</span>
                        <div class="ah-dots">
                            @foreach ($dayOccs as $occ)
                                <span class="ah-dot"
                                      style="background: {{ $statusColors[$occ['status']] ?? '#9ca3af' }};"
                                      title="{{ $occ['class_name'] }} — {{ $statusLabels[$occ['status']] ?? $occ['status'] }} {{ $occ['time'] }}"></span>
                            @endforeach
                        </div>
                    </div>
                @endfor
            </div>

            <div class="ah-legend">
                @foreach ($statusLabels as $st => $lbl)
                    <span><span class="ah-dot" style="background: {{ $statusColors[$st] }};"></span>{{ $lbl }}</span>
                @endforeach
            </div>
        </div>

        {{-- ===== AGENDA / LISTA ===== --}}
        <div class="ah-section-title">Agenda — {{ $monthLabel }}</div>

        @forelse ($occurrences as $occ)
            <div class="ah-card">
                <div class="ah-occ">
                    <div>
                        <div class="ah-occ-class">{{ $occ['class_name'] }}</div>
                        <div class="ah-occ-meta">
                            {{ \Illuminate\Support\Carbon::parse($occ['date'])->locale('es')->isoFormat('ddd D MMM') }}
                            &middot; {{ $occ['time'] }} &middot; {{ $occ['account'] }}
                        </div>
                    </div>
                    <span class="ah-badge" style="background: {{ $statusColors[$occ['status']] ?? '#9ca3af' }};">
                        {{ $statusLabels[$occ['status']] ?? $occ['status'] }}
                    </span>
                </div>

                @if ($occ['status'] === 'scheduled')
                    <div class="ah-actions">
                        <button type="button" class="ah-btn ah-btn--danger"
                                wire:click="cancelDay({{ $occ['rule_id'] }}, '{{ $occ['date'] }}')"
                                wire:confirm="¿Cancelar la reserva del {{ $occ['date'] }}?">Cancelar día</button>
                        <button type="button" class="ah-btn ah-btn--info"
                                wire:click="openChangeTime({{ $occ['rule_id'] }}, '{{ $occ['date'] }}')">Cambiar hora</button>
                    </div>
                @elseif ($occ['status'] === 'cancelled')
                    <div class="ah-actions">
                        <button type="button" class="ah-btn ah-btn--ok"
                                wire:click="reactivateDay({{ $occ['rule_id'] }}, '{{ $occ['date'] }}')"
                                wire:confirm="¿Reactivar la reserva del {{ $occ['date'] }}?">Reactivar</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="ah-empty">Sin ocurrencias este mes.</div>
        @endforelse
    </div>

    {{-- ===== MODAL CAMBIO DE HORA ===== --}}
    @if ($showChangeTimeModal)
        <div class="ah-modal" wire:click.self="closeChangeTime">
            <div class="ah-modal-box">
                <div class="ah-modal-title">Cambiar hora</div>
                <div class="ah-modal-sub">{{ $changeTimeDate }}</div>
                <input type="time" class="ah-input" wire:model="changeTimeValue" />
                <div class="ah-modal-actions">
                    <button type="button" class="ah-btn--ghost" wire:click="closeChangeTime">Cancelar</button>
                    <button type="button" class="ah-btn--primary" wire:click="saveChangeTime">Guardar</button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
