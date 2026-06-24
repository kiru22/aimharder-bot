# Calendario Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crear una página Filament 4 "Calendario" mobile-first que muestra el mes en cuadrícula y la agenda lista, con acciones Livewire para cancelar, cambiar hora y reactivar días de reservas programadas.

**Architecture:** Página Filament 4 custom (`Filament\Pages\Page`) que es un componente Livewire con estado de mes serializable (`public string $month`). Consume `OccurrencesService::forRange()` y muta `BookingRule::skip_dates`/`time_overrides` via métodos Livewire directos en la clase de la página. La vista Blade usa Tailwind mobile-first con una cuadrícula de 7 columnas para el calendario y tarjetas apiladas para la agenda.

**Tech Stack:** Laravel 13, Filament 4, Livewire (integrado en Filament), Tailwind CSS, Pest 4 + `pestphp/pest-plugin-laravel`.

## Global Constraints

- PHP 8.3+
- Filament 4 (NO usar APIs de Filament 3 como `$title` estático en el render, `$view` debe ser la vista blade correcta)
- No tocar widgets del Dashboard existentes
- No ejecutar pint
- Timezone siempre de `config('aimharder.timezone')` = `Europe/Madrid`
- Estado de mes como `public string $month` en formato `'Y-m'` (serializable por Livewire)
- Botones/tap targets ≥ 40px en móvil
- Todos los tests con `RefreshDatabase` + `Carbon::setTestNow()` donde se necesite fecha fija
- Slug de la página: Filament 4 genera `calendario` (kebab de `Calendario`)

---

## File Structure

| Fichero | Rol |
|---|---|
| `app/Filament/Pages/Calendario.php` | Página Filament 4 + componente Livewire. Estado `$month`, métodos `previousMonth/nextMonth`, computed `occurrences()`, acciones `cancelDay/changeTime/reactivateDay` |
| `resources/views/filament/pages/calendario.blade.php` | Vista Blade con cuadrícula mensual + agenda lista, Tailwind mobile-first |
| `tests/Feature/CalendarioPageTest.php` | Tests: render HTTP 200 con datos, acciones Livewire (cancelDay, changeTime, reactivateDay) |

---

### Task 1: Página Filament 4 Calendario (PHP + Blade básico)

**Files:**
- Create: `app/Filament/Pages/Calendario.php`
- Create: `resources/views/filament/pages/calendario.blade.php`

**Interfaces:**
- Produces: `Calendario::class` (Filament page), ruta `/admin/calendario`, métodos `cancelDay(int $ruleId, string $date)`, `changeTime(int $ruleId, string $date, string $time)`, `reactivateDay(int $ruleId, string $date)`

- [ ] **Step 1: Crear la página Filament**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan make:filament-page Calendario
```

Esto genera `app/Filament/Pages/Calendario.php` y `resources/views/filament/pages/calendario.blade.php`.

- [ ] **Step 2: Verificar qué generó el artisan**

```bash
cat /Users/ruslankyrch/aimharder-bot/app/Filament/Pages/Calendario.php
cat /Users/ruslankyrch/aimharder-bot/resources/views/filament/pages/calendario.blade.php
```

- [ ] **Step 3: Reemplazar el contenido de la página PHP con la implementación completa**

Reemplazar `app/Filament/Pages/Calendario.php` con:

```php
<?php

namespace App\Filament\Pages;

use App\Models\BookingRule;
use App\Services\OccurrencesService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Calendario extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Calendario';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.calendario';

    public string $month;

    // Para el modal de cambio de hora
    public string $changeTimeDate  = '';
    public int    $changeTimeRuleId = 0;
    public string $changeTimeValue = '';
    public bool   $showChangeTimeModal = false;

    public function mount(): void
    {
        $tz          = config('aimharder.timezone');
        $this->month = now($tz)->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)
            ->subMonth()
            ->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)
            ->addMonth()
            ->format('Y-m');
    }

    /** @return Collection<int, array> */
    public function occurrences(): Collection
    {
        $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth()->startOfDay();
        $end   = Carbon::createFromFormat('Y-m', $this->month)->endOfMonth()->startOfDay();

        return OccurrencesService::forRange($start, $end);
    }

    /**
     * Añade la fecha a skip_dates de la regla (cancela ese día).
     */
    public function cancelDay(int $ruleId, string $date): void
    {
        $rule  = BookingRule::findOrFail($ruleId);
        $skip  = $rule->skip_dates ?? [];

        if (! in_array($date, $skip, true)) {
            $skip[] = $date;
            $rule->update(['skip_dates' => $skip]);
        }

        Notification::make()
            ->title("Día {$date} cancelado")
            ->success()
            ->send();
    }

    /**
     * Abre el modal de cambio de hora.
     */
    public function openChangeTime(int $ruleId, string $date): void
    {
        $rule = BookingRule::findOrFail($ruleId);

        $this->changeTimeRuleId = $ruleId;
        $this->changeTimeDate   = $date;
        $this->changeTimeValue  = $rule->effectiveTimeFor($date);
        $this->showChangeTimeModal = true;
    }

    /**
     * Cierra el modal de cambio de hora.
     */
    public function closeChangeTime(): void
    {
        $this->showChangeTimeModal = false;
    }

    /**
     * Guarda el override de hora para la fecha dada.
     * $time debe tener formato HH:MM.
     */
    public function changeTime(int $ruleId, string $date, string $time): void
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            Notification::make()
                ->title('Formato de hora inválido (usa HH:MM)')
                ->danger()
                ->send();

            return;
        }

        $rule      = BookingRule::findOrFail($ruleId);
        $overrides = $rule->time_overrides ?? [];
        $overrides[$date] = $time;
        $rule->update(['time_overrides' => $overrides]);

        $this->showChangeTimeModal = false;

        Notification::make()
            ->title("Hora del {$date} cambiada a {$time}")
            ->success()
            ->send();
    }

    /**
     * Guarda el cambio de hora desde el modal (usa las propiedades públicas).
     */
    public function saveChangeTime(): void
    {
        $this->changeTime($this->changeTimeRuleId, $this->changeTimeDate, $this->changeTimeValue);
    }

    /**
     * Quita la fecha de skip_dates de la regla (reactiva ese día).
     */
    public function reactivateDay(int $ruleId, string $date): void
    {
        $rule = BookingRule::findOrFail($ruleId);
        $skip = array_values(array_filter(
            $rule->skip_dates ?? [],
            fn (string $d) => $d !== $date
        ));
        $rule->update(['skip_dates' => $skip]);

        Notification::make()
            ->title("Día {$date} reactivado")
            ->success()
            ->send();
    }
}
```

- [ ] **Step 4: Escribir la vista Blade mobile-first**

Reemplazar `resources/views/filament/pages/calendario.blade.php` con la vista completa (cuadrícula + agenda + modal). Ver Task 2.

- [ ] **Step 5: Verificar que la ruta existe**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan route:list | grep -i calend
```

Esperado: aparece `/admin/calendario` con nombre `filament.admin.pages.calendario`.

---

### Task 2: Vista Blade mobile-first

**Files:**
- Modify: `resources/views/filament/pages/calendario.blade.php`

**Interfaces:**
- Consumes: `$this->occurrences()`, propiedades Livewire `$month`, `$showChangeTimeModal`, `$changeTimeDate`, `$changeTimeValue`, `$changeTimeRuleId`
- Produces: UI completa — cuadrícula mensual, leyenda, agenda lista, botones de acción, modal de cambio de hora

- [ ] **Step 1: Escribir la vista Blade completa**

Reemplazar `resources/views/filament/pages/calendario.blade.php` con:

```blade
<x-filament-panels::page>
    {{-- ===== HELPERS (computed en Blade) ===== --}}
    @php
        use Illuminate\Support\Carbon;

        $occurrences = $this->occurrences();

        // Agrupar por fecha para la cuadrícula
        $byDate = $occurrences->groupBy('date');

        // Calcular primer día del mes y días del mes
        $monthCarbon  = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $monthLabel   = ucfirst($monthCarbon->locale('es')->isoFormat('MMMM YYYY'));
        $daysInMonth  = $monthCarbon->daysInMonth;

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
            >‹</button>

            <h2 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-white capitalize">
                {{ $monthLabel }}
            </h2>

            <button
                wire:click="nextMonth"
                class="flex items-center justify-center w-10 h-10 rounded-lg bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-bold text-lg"
                aria-label="Mes siguiente"
            >›</button>
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

                <div class="min-h-[44px] rounded-lg p-1 flex flex-col items-center
                    {{ $isToday ? 'bg-blue-50 dark:bg-blue-900/30 ring-1 ring-blue-400' : 'bg-gray-50 dark:bg-gray-800/50' }}">

                    <span class="text-xs font-medium {{ $isToday ? 'text-blue-700 dark:text-blue-300 font-bold' : 'text-gray-700 dark:text-gray-300' }}">
                        {{ $day }}
                    </span>

                    {{-- Puntos de estado --}}
                    <div class="flex flex-wrap gap-0.5 justify-center mt-0.5">
                        @foreach($dayOccs as $occ)
                            @php $dotColor = $statusColors[$occ['status']]['dot'] ?? 'bg-gray-400'; @endphp
                            <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}" title="{{ $occ['class_name'] }} - {{ $statusLabels[$occ['status']] ?? $occ['status'] }}"></span>
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
                            · {{ $occ['time'] }}
                            · {{ $occ['account'] }}
                        </p>
                    </div>
                    <span class="shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeCss }}">
                        {{ $statusLbl }}
                    </span>
                </div>

                {{-- Botones de acción (solo scheduled y cancelled) --}}
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
```

- [ ] **Step 2: Verificar que la vista referencia la página correctamente**

```bash
grep -n "view\|$view" /Users/ruslankyrch/aimharder-bot/app/Filament/Pages/Calendario.php
```

Esperado: `protected string $view = 'filament.pages.calendario';`

---

### Task 3: Tests Feature para la página Calendario

**Files:**
- Create: `tests/Feature/CalendarioPageTest.php`

**Interfaces:**
- Consumes: `Calendario::class`, `BookingRule`, `Account`, `BookingLog`, `User`, `Livewire::test()`

- [ ] **Step 1: Escribir los tests**

Crear `tests/Feature/CalendarioPageTest.php`:

```php
<?php

use App\Filament\Pages\Calendario;
use App\Models\Account;
use App\Models\BookingLog;
use App\Models\BookingRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

// Fijamos la fecha en un miércoles de junio 2026 para que los tests sean deterministas
beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 10, 8, 0, 0, 'Europe/Madrid'));
    $this->actingAs(User::factory()->create());
});

afterEach(fn () => Carbon::setTestNow());

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeRuleWithLogs(): array
{
    $account = Account::create([
        'label'    => 'TestUser',
        'email'    => 'caltest@test.com',
        'password' => 'pw',
    ]);

    // La regla tiene lunes (1) y miércoles (3) en weekdays
    $rule = $account->rules()->create([
        'weekdays'   => [1, 3],
        'time'       => '18:00',
        'class_name' => 'CrossFit',
        'skip_dates' => ['2026-06-15'], // lunes 15 cancelado
    ]);

    // Log booked el lunes 08-jun (pasado con log)
    BookingLog::create([
        'account_id'      => $account->id,
        'booking_rule_id' => $rule->id,
        'target_date'     => '2026-06-08',
        'class_id'        => '1800_60',
        'status'          => 'booked',
        'book_state'      => 0,
        'message'         => 'OK',
    ]);

    // Log failed el miércoles 10-jun (hoy, tiene log)
    BookingLog::create([
        'account_id'      => $account->id,
        'booking_rule_id' => $rule->id,
        'target_date'     => '2026-06-10',
        'class_id'        => null,
        'status'          => 'failed',
        'book_state'      => -2,
        'message'         => 'Sin créditos',
    ]);

    return ['account' => $account, 'rule' => $rule];
}

// ─── Test render HTTP ─────────────────────────────────────────────────────────

it('renderiza la página Calendario con datos (HTTP 200)', function () {
    ['rule' => $rule] = makeRuleWithLogs();

    $this->get('/admin/calendario')
        ->assertOk()
        ->assertSee('CrossFit')
        ->assertSee('booked', false)
        ->assertSee('failed', false);
});

// ─── Test cancelDay ──────────────────────────────────────────────────────────

it('cancelDay añade la fecha a skip_dates de la regla', function () {
    ['rule' => $rule] = makeRuleWithLogs();

    // 2026-06-17 es miércoles, está en weekdays y es futuro → scheduled
    Livewire::test(Calendario::class)
        ->call('cancelDay', $rule->id, '2026-06-17');

    $skip = BookingRule::find($rule->id)->skip_dates;

    expect($skip)->toContain('2026-06-17');
});

it('cancelDay no duplica la fecha si ya estaba en skip_dates', function () {
    ['rule' => $rule] = makeRuleWithLogs();

    Livewire::test(Calendario::class)
        ->call('cancelDay', $rule->id, '2026-06-17')
        ->call('cancelDay', $rule->id, '2026-06-17');

    $skip = BookingRule::find($rule->id)->skip_dates;

    expect(array_count_values($skip)['2026-06-17'])->toBe(1);
});

// ─── Test changeTime ─────────────────────────────────────────────────────────

it('changeTime guarda el override de hora en time_overrides', function () {
    ['rule' => $rule] = makeRuleWithLogs();

    Livewire::test(Calendario::class)
        ->call('changeTime', $rule->id, '2026-06-17', '09:30');

    $overrides = BookingRule::find($rule->id)->time_overrides;

    expect($overrides['2026-06-17'])->toBe('09:30');
});

it('changeTime rechaza formato inválido y no muta la BD', function () {
    ['rule' => $rule] = makeRuleWithLogs();

    Livewire::test(Calendario::class)
        ->call('changeTime', $rule->id, '2026-06-17', '9:3');

    $overrides = BookingRule::find($rule->id)->time_overrides ?? [];

    expect($overrides)->not->toHaveKey('2026-06-17');
});

// ─── Test reactivateDay ───────────────────────────────────────────────────────

it('reactivateDay quita la fecha de skip_dates', function () {
    ['rule' => $rule] = makeRuleWithLogs();

    // 2026-06-15 ya está en skip_dates (establecido en makeRuleWithLogs)
    Livewire::test(Calendario::class)
        ->call('reactivateDay', $rule->id, '2026-06-15');

    $skip = BookingRule::find($rule->id)->skip_dates;

    expect($skip)->not->toContain('2026-06-15');
});

it('reactivateDay es idempotente si la fecha no estaba en skip_dates', function () {
    ['rule' => $rule] = makeRuleWithLogs();

    Livewire::test(Calendario::class)
        ->call('reactivateDay', $rule->id, '2026-06-99'); // fecha inexistente

    // No debe lanzar excepción ni alterar los skip existentes
    $skip = BookingRule::find($rule->id)->skip_dates;

    expect($skip)->toContain('2026-06-15'); // el skip original intacto
});
```

- [ ] **Step 2: Ejecutar los tests**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan test tests/Feature/CalendarioPageTest.php --no-ansi 2>&1
```

Esperado: todos en verde (`PASS`).

- [ ] **Step 3: Ejecutar toda la suite para asegurar que no se rompió nada**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan test --no-ansi 2>&1
```

Esperado: toda la suite en verde.

---

### Task 4: Commit y reporte

**Files:**
- Create: `.superpowers/sdd/occ-ui-report.md`

- [ ] **Step 1: Crear directorio de reporte**

```bash
mkdir -p /Users/ruslankyrch/aimharder-bot/.superpowers/sdd
```

- [ ] **Step 2: Escribir el reporte**

Crear `.superpowers/sdd/occ-ui-report.md` con: qué se construyó, decisiones de Filament 4, slug de la página, evidencia de tests (render + 3 acciones), ficheros creados, concerns.

- [ ] **Step 3: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add app/Filament/Pages/Calendario.php resources/views/filament/pages/calendario.blade.php tests/Feature/CalendarioPageTest.php .superpowers/sdd/occ-ui-report.md docs/superpowers/plans/2026-06-24-calendario-page.md && git commit -m "feat: página Calendario mobile-first con acciones Livewire (cancelDay, changeTime, reactivateDay)"
```

- [ ] **Step 4: Reportar al team-lead via SendMessage**
