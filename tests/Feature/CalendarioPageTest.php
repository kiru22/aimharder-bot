<?php

use App\Filament\Pages\Calendario;
use App\Models\Account;
use App\Models\BookingLog;
use App\Models\BookingRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

// Fijamos la fecha en un miércoles de junio 2026 para que los tests sean deterministas.
// 2026-06-10 = miércoles (weekday ISO 3).
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

    // La regla tiene lunes (1) y miércoles (3) en weekdays.
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

    // Log failed el miércoles 10-jun (hoy, tiene log — prioridad sobre skip_dates)
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
    makeRuleWithLogs();

    $this->get('/admin/calendario')
        ->assertOk()
        ->assertSee('CrossFit')
        ->assertSee('Reservada')   // badge del status 'booked' en español
        ->assertSee('Fallida');    // badge del status 'failed' en español
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
        ->call('reactivateDay', $rule->id, '2026-06-99'); // fecha inexistente en skip_dates

    // No debe lanzar excepción ni alterar los skips existentes
    $skip = BookingRule::find($rule->id)->skip_dates;

    expect($skip)->toContain('2026-06-15'); // el skip original intacto
});
