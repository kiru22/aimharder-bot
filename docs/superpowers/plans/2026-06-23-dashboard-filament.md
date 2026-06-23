# Dashboard Filament — AimHarder Bot — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el dashboard vacío de Filament 4 con tres widgets útiles (estadísticas, próximas reservas, reservas recientes) añadiendo la columna `skip_dates` y la lógica `nextOccurrence` con tests TDD.

**Architecture:** Seguir exactamente el patrón del proyecto (clases Table/Form separadas, Filament 4 API: `recordActions`, `toolbarActions`, `TextColumn::badge()`). `BookingRule::nextOccurrence` es lógica pura testeable con `Carbon::setTestNow`. Los widgets se registran vía `discoverWidgets` y se eliminan los dos defaults de `->widgets([])`.

**Tech Stack:** Laravel 13, Filament 4.11.7, Pest, Carbon, SQLite (tests).

## Global Constraints

- Filament 4 API: `recordActions()` / `toolbarActions()` en `Table`, `TextColumn::make()->badge()` (NO `BadgeColumn`), base widget tabla = `Filament\Widgets\TableWidget`.
- `StatsOverviewWidget::getStats()` retorna `array<Stat>` (namespace `Filament\Widgets\StatsOverviewWidget\Stat`).
- Row/header actions: `Filament\Actions\Action`, `Filament\Actions\EditAction`.
- Timezone: `config('aimharder.timezone')` = `Europe/Madrid`.
- `BookingLog::$timestamps = false` — usa `created_at` con cast a `datetime`.
- NO invocar pint manualmente.
- `php artisan test` debe quedar en verde (≥21 tests).
- `BookingLog` status enum: `booked`, `failed`, `no_match`, `already`, `skipped`.

---

## Mapa de Archivos

| Acción | Ruta |
|--------|------|
| Crear | `database/migrations/2026_06_23_000001_add_skip_dates_to_booking_rules.php` |
| Modificar | `app/Models/BookingRule.php` |
| Modificar | `app/Models/BookingLog.php` (`$fillable` añadir `skipped`) |
| Modificar | `app/Console/Commands/RunBookings.php` |
| Modificar | `app/Providers/Filament/AdminPanelProvider.php` |
| Crear | `app/Filament/Widgets/BookingStatsWidget.php` |
| Crear | `app/Filament/Widgets/UpcomingBookingsWidget.php` |
| Crear | `app/Filament/Widgets/RecentBookingsWidget.php` |
| Modificar | `tests/Feature/FilamentSmokeTest.php` |
| Crear | `tests/Unit/BookingRuleNextOccurrenceTest.php` |
| Crear | `tests/Feature/RunBookingsSkipTest.php` |
| Crear | `.superpowers/sdd/dashboard-report.md` |

---

### Task 1: Migración skip_dates + cast en BookingRule

**Files:**
- Create: `database/migrations/2026_06_23_000001_add_skip_dates_to_booking_rules.php`
- Modify: `app/Models/BookingRule.php`

**Interfaces:**
- Produces: `BookingRule::$skip_dates` (array|null), `BookingRule::nextOccurrence(?Carbon): ?Carbon`, `BookingRule::upcomingOccurrences(int): Collection`

- [ ] **Step 1: Crear la migración**

```php
<?php
// database/migrations/2026_06_23_000001_add_skip_dates_to_booking_rules.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_rules', function (Blueprint $table) {
            $table->json('skip_dates')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('booking_rules', function (Blueprint $table) {
            $table->dropColumn('skip_dates');
        });
    }
};
```

- [ ] **Step 2: Actualizar BookingRule con cast, fillable y métodos**

```php
<?php
// app/Models/BookingRule.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BookingRule extends Model
{
    protected $fillable = ['account_id', 'weekdays', 'time', 'class_name', 'insist', 'active', 'skip_dates'];

    protected function casts(): array
    {
        return [
            'weekdays'   => 'array',
            'insist'     => 'boolean',
            'active'     => 'boolean',
            'skip_dates' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Próxima ocurrencia >= $from cuyo ISO weekday esté en $weekdays
     * y cuya fecha Y-m-d NO esté en skip_dates.
     * Combina la fecha con $this->time ("HH:MM").
     * Busca hasta 14 días; retorna null si no hay ninguna.
     */
    public function nextOccurrence(?Carbon $from = null): ?Carbon
    {
        $tz   = config('aimharder.timezone');
        $from ??= now($tz)->startOfDay();
        $skip = $this->skip_dates ?? [];
        $days = $this->weekdays ?? [];

        for ($i = 0; $i < 14; $i++) {
            $candidate = $from->copy()->addDays($i);

            if (! in_array($candidate->dayOfWeekIso, $days, true)) {
                continue;
            }

            if (in_array($candidate->format('Y-m-d'), $skip, true)) {
                continue;
            }

            [$h, $m] = explode(':', $this->time);

            return $candidate->setTime((int) $h, (int) $m);
        }

        return null;
    }

    /**
     * Todas las ocurrencias en los próximos $days días.
     *
     * @return Collection<int, Carbon>
     */
    public function upcomingOccurrences(int $days = 14): Collection
    {
        $tz   = config('aimharder.timezone');
        $from = now($tz)->startOfDay();
        $skip = $this->skip_dates ?? [];
        $wds  = $this->weekdays ?? [];
        [$h, $m] = explode(':', $this->time);
        $result = collect();

        for ($i = 0; $i < $days; $i++) {
            $candidate = $from->copy()->addDays($i);

            if (! in_array($candidate->dayOfWeekIso, $wds, true)) {
                continue;
            }

            if (in_array($candidate->format('Y-m-d'), $skip, true)) {
                continue;
            }

            $result->push($candidate->setTime((int) $h, (int) $m));
        }

        return $result;
    }
}
```

- [ ] **Step 3: Verificar que la migración aplica correctamente**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan migrate --env=testing 2>&1 | tail -5
```

Expected: "Nothing to migrate." o la migración aplicada sin errores.

- [ ] **Step 4: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add database/migrations/2026_06_23_000001_add_skip_dates_to_booking_rules.php app/Models/BookingRule.php && git commit -m "feat: add skip_dates to BookingRule with nextOccurrence and upcomingOccurrences"
```

---

### Task 2: Tests TDD de BookingRule::nextOccurrence

**Files:**
- Create: `tests/Unit/BookingRuleNextOccurrenceTest.php`

**Interfaces:**
- Consumes: `BookingRule::nextOccurrence(?Carbon): ?Carbon` (Task 1)

- [ ] **Step 1: Crear el archivo de test**

```php
<?php
// tests/Unit/BookingRuleNextOccurrenceTest.php

use App\Models\BookingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

// 2026-06-23 es martes (ISO weekday 2)
it('devuelve el próximo día de semana configurado hacia adelante', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 23, 6, 0, 0, 'Europe/Madrid')); // martes

    $rule = new BookingRule(['weekdays' => [4], 'time' => '18:00', 'skip_dates' => null]); // jueves

    $next = $rule->nextOccurrence();

    expect($next)->not->toBeNull()
        ->and($next->format('Y-m-d'))->toBe('2026-06-25') // próximo jueves
        ->and($next->format('H:i'))->toBe('18:00');
});

it('devuelve hoy si el día de hoy coincide con weekdays', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 23, 6, 0, 0, 'Europe/Madrid')); // martes (ISO 2)

    $rule = new BookingRule(['weekdays' => [2], 'time' => '09:00', 'skip_dates' => null]);

    $next = $rule->nextOccurrence();

    expect($next)->not->toBeNull()
        ->and($next->format('Y-m-d'))->toBe('2026-06-23')
        ->and($next->format('H:i'))->toBe('09:00');
});

it('salta la fecha que está en skip_dates y usa la siguiente', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 23, 6, 0, 0, 'Europe/Madrid')); // martes

    $rule = new BookingRule([
        'weekdays'   => [2],            // martes
        'time'       => '18:00',
        'skip_dates' => ['2026-06-23'], // hoy saltado
    ]);

    $next = $rule->nextOccurrence();

    expect($next)->not->toBeNull()
        ->and($next->format('Y-m-d'))->toBe('2026-06-30') // siguiente martes
        ->and($next->format('H:i'))->toBe('18:00');
});

it('retorna null si no hay weekdays configurados', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 23, 6, 0, 0, 'Europe/Madrid'));

    $rule = new BookingRule(['weekdays' => [], 'time' => '18:00', 'skip_dates' => null]);

    expect($rule->nextOccurrence())->toBeNull();
});

it('acepta un $from explícito', function () {
    $from = Carbon::create(2026, 6, 30, 0, 0, 0, 'Europe/Madrid'); // martes

    $rule = new BookingRule(['weekdays' => [2], 'time' => '07:30', 'skip_dates' => null]);

    $next = $rule->nextOccurrence($from);

    expect($next)->not->toBeNull()
        ->and($next->format('Y-m-d'))->toBe('2026-06-30')
        ->and($next->format('H:i'))->toBe('07:30');
});
```

- [ ] **Step 2: Ejecutar y verificar que los tests PASAN**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan test tests/Unit/BookingRuleNextOccurrenceTest.php --no-coverage 2>&1
```

Expected: 5 tests, 5 passed.

- [ ] **Step 3: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add tests/Unit/BookingRuleNextOccurrenceTest.php && git commit -m "test: TDD tests for BookingRule::nextOccurrence"
```

---

### Task 3: Comando RunBookings — skip por skip_dates

**Files:**
- Modify: `app/Console/Commands/RunBookings.php`

**Interfaces:**
- Consumes: `BookingRule::$skip_dates` (array|null) — Task 1
- Produces: `BookingLog` con status `'skipped'` cuando la fecha está en skip_dates

- [ ] **Step 1: Modificar RunBookings para saltar reglas con skip_dates**

Añadir el chequeo `skip_dates` en el loop `foreach ($rules as $rule)` dentro de `processAccount`, antes de buscar el match. El estado `skipped` NO llama a la API.

```php
<?php
// app/Console/Commands/RunBookings.php — método processAccount completo
// Solo se muestra el bloque modificado del foreach; el resto permanece igual.

    private function processAccount(Account $account, $rules, string $day, bool $dry): void
    {
        $client = new AimharderClient($account->subdomain, $account->box_id);

        try {
            $client->login($account->email, $account->password, $account->fingerprint);
        } catch (\Throwable $e) {
            foreach ($rules as $rule) {
                $this->log($account, $rule, $day, null, 'failed', null, 'Login: '.$e->getMessage());
            }

            return;
        }

        $payload = $client->listClasses($day);

        foreach ($rules as $rule) {
            // Saltar si hoy está en skip_dates
            $todayDate = now(config('aimharder.timezone'))->format('Y-m-d');
            if (in_array($todayDate, $rule->skip_dates ?? [], true)) {
                $this->log($account, $rule, $day, null, 'skipped', null, 'Saltada manualmente.');
                continue;
            }

            $match = ClassMatcher::find($payload, $rule->time, $rule->class_name);

            if ($match === null) {
                $this->log($account, $rule, $day, null, 'no_match', null,
                    "No se encontró {$rule->class_name} a las {$rule->time}.");

                continue;
            }

            $classId = (string) $match['id'];

            if (($match['bookState'] ?? null) === 1) {
                $this->log($account, $rule, $day, $classId, 'already', 1, 'Ya estaba reservada.');

                continue;
            }

            if ($dry) {
                $this->info("[dry-run] {$account->label}: reservaría {$rule->class_name} {$rule->time} (id $classId)");

                continue;
            }

            $res    = $client->book($classId, $day, $rule->insist);
            $state  = $res['bookState'] ?? null;
            $errLang = $res['errorMssgLang'] ?? null;
            $hasError = isset($res['errorMssg']) || isset($res['errorMssgLang']);

            if ($errLang === 'NOPUEDESRESERVAMISMAHORA') {
                $status = 'already';
            } elseif ($hasError || $state === null || $state < 0) {
                $status = 'failed';
            } else {
                $status = 'booked';
            }

            $this->log($account, $rule, $day, $classId, $status, $state,
                $res['errorMssg'] ?? ($status === 'booked' ? 'Reservada correctamente' : "bookState=$state"));
        }
    }
```

- [ ] **Step 2: Verificar que los tests existentes siguen pasando**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan test tests/Feature/RunBookingsTest.php --no-coverage 2>&1
```

Expected: todos los tests previos en verde.

- [ ] **Step 3: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add app/Console/Commands/RunBookings.php && git commit -m "feat: skip booking rule when today is in skip_dates"
```

---

### Task 4: Tests TDD del comando skip

**Files:**
- Create: `tests/Feature/RunBookingsSkipTest.php`

**Interfaces:**
- Consumes: `RunBookings` (Task 3), `BookingRule::$skip_dates` (Task 1)

- [ ] **Step 1: Crear el test de skip en el comando**

```php
<?php
// tests/Feature/RunBookingsSkipTest.php

use App\Models\Account;
use App\Models\BookingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 2026-06-22 es lunes (ISO weekday 1)
    Carbon::setTestNow(Carbon::create(2026, 6, 22, 6, 0, 0, 'Europe/Madrid'));
});

afterEach(fn () => Carbon::setTestNow());

it('registra status skipped y no llama a /api/book cuando hoy está en skip_dates', function () {
    Http::fake([
        'login.aimharder.com/api/login'             => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response([
            'timetable' => [['id' => '1800_60', 'time' => '18:00-19:00']],
            'bookings'  => [['id' => 222, 'timeid' => '1800_60', 'className' => 'CrossFit', 'bookState' => 0]],
        ], 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create([
        'weekdays'   => [1],           // lunes
        'time'       => '18:00',
        'class_name' => 'CrossFit',
        'skip_dates' => ['2026-06-22'], // hoy saltado
    ]);

    $this->artisan('bookings:run')->assertOk();

    Http::assertNotSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/book'));
    expect(BookingLog::where('status', 'skipped')->count())->toBe(1);
    expect(BookingLog::first()->message)->toBe('Saltada manualmente.');
});
```

- [ ] **Step 2: Ejecutar el test y verificar que PASA**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan test tests/Feature/RunBookingsSkipTest.php --no-coverage 2>&1
```

Expected: 1 test, 1 passed.

- [ ] **Step 3: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add tests/Feature/RunBookingsSkipTest.php && git commit -m "test: TDD skip command test for skip_dates"
```

---

### Task 5: Widget BookingStatsWidget

**Files:**
- Create: `app/Filament/Widgets/BookingStatsWidget.php`

**Interfaces:**
- Consumes: `BookingLog` (status, created_at), `BookingRule` (active)
- Produces: widget registrado via `discoverWidgets`, aparece en `/admin`

- [ ] **Step 1: Crear BookingStatsWidget**

```php
<?php
// app/Filament/Widgets/BookingStatsWidget.php

namespace App\Filament\Widgets;

use App\Models\BookingLog;
use App\Models\BookingRule;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BookingStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $booked = BookingLog::query()
            ->where('status', 'booked')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $failed = BookingLog::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $active = BookingRule::query()
            ->where('active', true)
            ->count();

        return [
            Stat::make('Reservadas (30 días)', $booked)
                ->description('Reservas completadas')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Fallidas (30 días)', $failed)
                ->description('Reservas con error')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),

            Stat::make('Reglas activas', $active)
                ->description('Reglas en funcionamiento')
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->color('info'),
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add app/Filament/Widgets/BookingStatsWidget.php && git commit -m "feat: add BookingStatsWidget with booked/failed/active stats"
```

---

### Task 6: Widget UpcomingBookingsWidget

**Files:**
- Create: `app/Filament/Widgets/UpcomingBookingsWidget.php`

**Interfaces:**
- Consumes: `BookingRule::nextOccurrence()` (Task 1), `Filament\Widgets\TableWidget`, `Filament\Actions\Action`, `Filament\Actions\EditAction`
- Produces: tabla con heading "Próximas reservas" en `/admin`

- [ ] **Step 1: Crear UpcomingBookingsWidget**

```php
<?php
// app/Filament/Widgets/UpcomingBookingsWidget.php

namespace App\Filament\Widgets;

use App\Models\BookingRule;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;

class UpcomingBookingsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Próximas reservas')
            ->query(BookingRule::query()->where('active', true)->with('account'))
            ->columns([
                TextColumn::make('account.label')
                    ->label('Cuenta')
                    ->searchable(),
                TextColumn::make('class_name')
                    ->label('Clase')
                    ->searchable(),
                TextColumn::make('weekdays')
                    ->label('Días')
                    ->formatStateUsing(function ($state): string {
                        $names = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
                        $days  = is_array($state) ? $state : json_decode($state, true) ?? [];

                        return implode(', ', array_map(fn ($d) => $names[$d] ?? $d, $days));
                    }),
                TextColumn::make('time')
                    ->label('Hora'),
                TextColumn::make('next_occurrence')
                    ->label('Próxima')
                    ->getStateUsing(function (BookingRule $record): string {
                        $next = $record->nextOccurrence();

                        if ($next === null) {
                            return '—';
                        }

                        $locale = \Carbon\Carbon::now()->locale('es');
                        $next->locale('es');

                        return $next->isoFormat('ddd D MMM HH:mm');
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->form(fn (Schema $schema) => $schema->components([
                        TextInput::make('time')
                            ->placeholder('18:00')
                            ->required(),
                        CheckboxList::make('weekdays')
                            ->options([
                                1 => 'Lun',
                                2 => 'Mar',
                                3 => 'Mié',
                                4 => 'Jue',
                                5 => 'Vie',
                                6 => 'Sáb',
                                7 => 'Dom',
                            ])
                            ->columns(7)
                            ->required(),
                        TextInput::make('class_name')
                            ->placeholder('CrossFit')
                            ->required(),
                        Toggle::make('insist')
                            ->helperText('Insistir / lista de espera'),
                    ])),

                Action::make('skip_next')
                    ->label('Saltar próxima')
                    ->icon('heroicon-m-forward')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (BookingRule $record): void {
                        $next = $record->nextOccurrence();

                        if ($next === null) {
                            Notification::make()
                                ->title('No hay próxima ocurrencia')
                                ->warning()
                                ->send();

                            return;
                        }

                        $dateStr = $next->format('Y-m-d');
                        $skip    = $record->skip_dates ?? [];
                        $skip[]  = $dateStr;
                        $record->update(['skip_dates' => $skip]);

                        Notification::make()
                            ->title("Reserva del {$dateStr} saltada")
                            ->success()
                            ->send();
                    }),

                Action::make('deactivate')
                    ->label('Desactivar')
                    ->icon('heroicon-m-pause-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (BookingRule $record): void {
                        $record->update(['active' => false]);

                        Notification::make()
                            ->title('Regla desactivada')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add app/Filament/Widgets/UpcomingBookingsWidget.php && git commit -m "feat: add UpcomingBookingsWidget with edit/skip/deactivate actions"
```

---

### Task 7: Widget RecentBookingsWidget

**Files:**
- Create: `app/Filament/Widgets/RecentBookingsWidget.php`

**Interfaces:**
- Consumes: `BookingLog` con cast `created_at`, `account()` relation
- Produces: tabla read-only con heading "Reservas recientes" en `/admin`

- [ ] **Step 1: Crear RecentBookingsWidget**

```php
<?php
// app/Filament/Widgets/RecentBookingsWidget.php

namespace App\Filament\Widgets;

use App\Models\BookingLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentBookingsWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Reservas recientes')
            ->query(BookingLog::query()->latest('created_at')->limit(10))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('account.label')
                    ->label('Cuenta'),
                TextColumn::make('class_id')
                    ->label('Clase ID'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'booked'   => 'success',
                        'failed'   => 'danger',
                        'no_match' => 'warning',
                        'already'  => 'info',
                        'skipped'  => 'gray',
                        default    => 'gray',
                    }),
                TextColumn::make('message')
                    ->label('Mensaje')
                    ->limit(60),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add app/Filament/Widgets/RecentBookingsWidget.php && git commit -m "feat: add RecentBookingsWidget (read-only, last 10 logs)"
```

---

### Task 8: Actualizar AdminPanelProvider — eliminar defaults

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

**Interfaces:**
- Produces: `->widgets([])` vacío — los widgets se registran solo via `discoverWidgets`

- [ ] **Step 1: Quitar AccountWidget y FilamentInfoWidget de ->widgets([])**

Reemplazar:
```php
->widgets([
    AccountWidget::class,
    FilamentInfoWidget::class,
])
```

Por:
```php
->widgets([])
```

Y eliminar los imports correspondientes:
```php
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
```

- [ ] **Step 2: Verificar que el servidor de pruebas carga /admin sin errores**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan route:list --path=admin 2>&1 | head -5
```

Expected: rutas listadas sin errores de compilación.

- [ ] **Step 3: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add app/Providers/Filament/AdminPanelProvider.php && git commit -m "chore: remove default Filament widgets from admin panel"
```

---

### Task 9: Tests de humo Filament — ampliar FilamentSmokeTest

**Files:**
- Modify: `tests/Feature/FilamentSmokeTest.php`

**Interfaces:**
- Consumes: todos los widgets creados (Tasks 5-7)

- [ ] **Step 1: Añadir tests de dashboard al FilamentSmokeTest**

Reemplazar el contenido de `tests/Feature/FilamentSmokeTest.php`:

```php
<?php
// tests/Feature/FilamentSmokeTest.php

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('carga el listado de cuentas', function () {
    $this->get('/admin/accounts')->assertOk();
});

it('carga el listado de reglas', function () {
    $this->get('/admin/booking-rules')->assertOk();
});

it('carga el listado de logs', function () {
    $this->get('/admin/booking-logs')->assertOk();
});

it('muestra los botones de ejecución manual en logs', function () {
    $this->get('/admin/booking-logs')
        ->assertSee('Ejecutar reservas ahora')
        ->assertSee('Simular');
});

it('carga el dashboard con los tres widgets', function () {
    $this->get('/admin')
        ->assertOk()
        ->assertSee('Próximas reservas')
        ->assertSee('Reservas recientes')
        ->assertSee('Reglas activas');
});
```

- [ ] **Step 2: Ejecutar TODOS los tests y verificar verde**

```bash
cd /Users/ruslankyrch/aimharder-bot && php artisan test --no-coverage 2>&1
```

Expected: ≥26 tests (21 previos + 5 nuevos de nextOccurrence + 1 skip + 1 dashboard), todos pasando.

- [ ] **Step 3: Commit**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add tests/Feature/FilamentSmokeTest.php && git commit -m "test: add dashboard smoke test asserting widget headings"
```

---

### Task 10: Reporte final

**Files:**
- Create: `.superpowers/sdd/dashboard-report.md`

- [ ] **Step 1: Crear directorio y reporte**

```bash
mkdir -p /Users/ruslankyrch/aimharder-bot/.superpowers/sdd
```

Crear `/Users/ruslankyrch/aimharder-bot/.superpowers/sdd/dashboard-report.md` con:
- Status: DONE
- Widget base class usada: `Filament\Widgets\TableWidget`
- Lista de archivos creados/modificados
- Evidencia de tests (output de `php artisan test`)
- Notas sobre Filament 4 API (`TextColumn::badge()` en vez de `BadgeColumn`, `recordActions()` en `Table`, `Stat::make()` con `->color()`, `->descriptionIcon()`)

- [ ] **Step 2: Commit final**

```bash
cd /Users/ruslankyrch/aimharder-bot && git add .superpowers/sdd/dashboard-report.md && git commit -m "docs: add dashboard implementation report"
```

---

## Self-Review

**Spec coverage:**
- [x] Migración `skip_dates` → Task 1
- [x] `BookingRule::nextOccurrence` → Task 1
- [x] `BookingRule::upcomingOccurrences` → Task 1
- [x] `RunBookings` skip con status `skipped` → Task 3
- [x] TDD `nextOccurrence` (4 casos) → Task 2
- [x] TDD comando skip → Task 4
- [x] `BookingStatsWidget` → Task 5
- [x] `UpcomingBookingsWidget` con acciones Editar/Saltar/Desactivar → Task 6
- [x] `RecentBookingsWidget` read-only → Task 7
- [x] Eliminar defaults del panel → Task 8
- [x] `FilamentSmokeTest` ampliado con GET /admin → Task 9
- [x] Reporte en `.superpowers/sdd/` → Task 10

**Placeholder scan:** Sin TBDs ni placeholders.

**Type consistency:**
- `nextOccurrence(?Carbon): ?Carbon` — consistente en Tasks 1, 2, 6
- `skip_dates` (array|null) — consistente en Tasks 1, 3, 4, 6
- `Filament\Widgets\TableWidget` — mismo en Tasks 6 y 7
- `recordActions([])` — API correcta según `vendor/filament`
