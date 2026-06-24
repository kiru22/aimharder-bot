<?php

use App\Models\Account;
use App\Models\BookingLog;
use App\Models\BookingRule;
use App\Services\OccurrencesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

// Semana de prueba: lunes 2026-06-08 … domingo 2026-06-14
// Hoy: miércoles 2026-06-10 06:00 Europe/Madrid
// La regla activa los lunes (1) y miércoles (3).
//   2026-06-08 lunes  → log con status 'booked'
//   2026-06-09 martes → no está en weekdays, se omite
//   2026-06-10 miérc  → log con status 'failed'
//   2026-06-11 jueves → no está en weekdays, se omite
//   2026-06-12 viern  → skip_date → cancelled
//   (ningún viernes en weekdays — no aplica; usamos lunes 2026-06-15 futuro)
//   2026-06-15 lunes  → futuro → scheduled

it('devuelve ocurrencias con los statuses correctos según logs, skip_dates y días futuros', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 10, 6, 0, 0, 'Europe/Madrid')); // miércoles

    $account = Account::create(['label' => 'TestUser', 'email' => 'u@test.com', 'password' => 'pw']);

    $rule = $account->rules()->create([
        'weekdays'       => [1, 3], // lunes y miércoles
        'time'           => '18:00',
        'class_name'     => 'CrossFit',
        'skip_dates'     => ['2026-06-10'], // miércoles 10 está saltado manualmente
        'time_overrides' => ['2026-06-15' => '19:00'], // próximo lunes override
    ]);

    // Log booked el lunes 08-jun (pasado con log)
    BookingLog::create([
        'account_id'      => $account->id,
        'booking_rule_id' => $rule->id,
        'target_date'     => '2026-06-08',
        'class_id'        => '1800_60',
        'status'          => 'booked',
        'book_state'      => 0,
        'message'         => 'Reservada correctamente',
    ]);

    // Log failed el miércoles 10-jun (hoy, pero tiene log — prioridad al log)
    // Nota: aunque está en skip_dates, el log tiene prioridad según el service
    BookingLog::create([
        'account_id'      => $account->id,
        'booking_rule_id' => $rule->id,
        'target_date'     => '2026-06-10',
        'class_id'        => null,
        'status'          => 'failed',
        'book_state'      => -2,
        'message'         => 'Sin créditos',
    ]);

    $start = Carbon::create(2026, 6, 8, 0, 0, 0, 'Europe/Madrid');
    $end   = Carbon::create(2026, 6, 17, 0, 0, 0, 'Europe/Madrid');

    $results = OccurrencesService::forRange($start, $end);

    // Días en weekdays [1,3] entre 08-jun y 17-jun:
    // lun 08 → booked (log)
    // mié 10 → failed (log, tiene prioridad sobre skip_date)
    // lun 15 → scheduled (futuro, override 19:00)
    // mié 17 → scheduled (futuro, hora base 18:00)

    expect($results)->toHaveCount(4);

    $byDate = $results->keyBy('date');

    expect($byDate['2026-06-08']['status'])->toBe('booked')
        ->and($byDate['2026-06-08']['time'])->toBe('18:00')
        ->and($byDate['2026-06-08']['log_id'])->not->toBeNull();

    expect($byDate['2026-06-10']['status'])->toBe('failed')
        ->and($byDate['2026-06-10']['log_id'])->not->toBeNull();

    expect($byDate['2026-06-15']['status'])->toBe('scheduled')
        ->and($byDate['2026-06-15']['time'])->toBe('19:00')  // override aplicado
        ->and($byDate['2026-06-15']['log_id'])->toBeNull();

    expect($byDate['2026-06-17']['status'])->toBe('scheduled')
        ->and($byDate['2026-06-17']['time'])->toBe('18:00')
        ->and($byDate['2026-06-17']['log_id'])->toBeNull();
});

it('marca cancelled cuando la fecha está en skip_dates y no hay log', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 10, 6, 0, 0, 'Europe/Madrid')); // miércoles

    $account = Account::create(['label' => 'TestUser', 'email' => 'u@test.com', 'password' => 'pw']);

    $account->rules()->create([
        'weekdays'   => [1], // solo lunes
        'time'       => '18:00',
        'class_name' => 'CrossFit',
        'skip_dates' => ['2026-06-08'], // lunes 08 saltado, sin log
    ]);

    $start = Carbon::create(2026, 6, 8, 0, 0, 0, 'Europe/Madrid');
    $end   = Carbon::create(2026, 6, 8, 0, 0, 0, 'Europe/Madrid');

    $results = OccurrencesService::forRange($start, $end);

    expect($results)->toHaveCount(1)
        ->and($results[0]['status'])->toBe('cancelled')
        ->and($results[0]['log_id'])->toBeNull();
});

it('omite días pasados sin log (evita ruido cuando el bot no corrió)', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 10, 6, 0, 0, 'Europe/Madrid')); // miércoles

    $account = Account::create(['label' => 'TestUser', 'email' => 'u@test.com', 'password' => 'pw']);

    $account->rules()->create([
        'weekdays'   => [1, 3],
        'time'       => '18:00',
        'class_name' => 'CrossFit',
        'skip_dates' => [],
    ]);

    // Sin ningún log creado
    $start = Carbon::create(2026, 6, 1, 0, 0, 0, 'Europe/Madrid');   // lunes pasado
    $end   = Carbon::create(2026, 6, 8, 0, 0, 0, 'Europe/Madrid');   // lunes pasado más reciente

    $results = OccurrencesService::forRange($start, $end);

    // Lunes 01, mié 03, lun 08 son pasados sin log → todos omitidos
    expect($results)->toHaveCount(0);
});

it('devuelve colección vacía si no hay reglas activas', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 10, 6, 0, 0, 'Europe/Madrid'));

    $account = Account::create(['label' => 'TestUser', 'email' => 'u@test.com', 'password' => 'pw']);

    // Regla inactiva
    $account->rules()->create([
        'weekdays'   => [1],
        'time'       => '18:00',
        'class_name' => 'CrossFit',
        'active'     => false,
    ]);

    $start = Carbon::create(2026, 6, 8, 0, 0, 0, 'Europe/Madrid');
    $end   = Carbon::create(2026, 6, 15, 0, 0, 0, 'Europe/Madrid');

    $results = OccurrencesService::forRange($start, $end);

    expect($results)->toHaveCount(0);
});

it('el resultado está ordenado por fecha y luego por time', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 24, 6, 0, 0, 'Europe/Madrid')); // miércoles futuro

    $account = Account::create(['label' => 'TestUser', 'email' => 'u@test.com', 'password' => 'pw']);

    // Dos reglas en el mismo día (lunes), horas distintas
    $account->rules()->create(['weekdays' => [1], 'time' => '19:00', 'class_name' => 'Yoga']);
    $account->rules()->create(['weekdays' => [1], 'time' => '08:00', 'class_name' => 'Running']);

    $start = Carbon::create(2026, 6, 29, 0, 0, 0, 'Europe/Madrid'); // lunes futuro
    $end   = Carbon::create(2026, 6, 29, 0, 0, 0, 'Europe/Madrid');

    $results = OccurrencesService::forRange($start, $end);

    expect($results)->toHaveCount(2)
        ->and($results[0]['time'])->toBe('08:00')
        ->and($results[1]['time'])->toBe('19:00');
});
