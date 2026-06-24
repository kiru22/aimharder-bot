<?php

use App\Models\Account;
use App\Models\BookingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('usa el time_override del día cuando existe y reserva la clase a la hora sobreescrita', function () {
    // 2026-06-22 es lunes (ISO weekday 1)
    Carbon::setTestNow(Carbon::create(2026, 6, 22, 6, 0, 0, 'Europe/Madrid'));

    // El timetable solo tiene la clase a las 19:00 ese día
    $bookingsPayload = [
        'timetable' => [['id' => '1900_60', 'time' => '19:00-20:00']],
        'bookings'  => [['id' => 333, 'timeid' => '1900_60', 'className' => 'CrossFit', 'bookState' => 0]],
    ];

    Http::fake([
        'login.aimharder.com/api/login'             => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response($bookingsPayload, 200),
        'hybridboxgrau.aimharder.com/api/book'      => Http::response(['bookState' => 0, 'id' => '333'], 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create([
        'weekdays'       => [1],          // lunes
        'time'           => '18:00',      // hora base
        'class_name'     => 'CrossFit',
        'time_overrides' => ['2026-06-22' => '19:00'], // override: hoy a las 19:00
    ]);

    $this->artisan('bookings:run')->assertOk();

    // Debe haber reservado (encontró CrossFit a las 19:00)
    expect(BookingLog::where('status', 'booked')->count())->toBe(1);
});

it('registra no_match cuando el override apunta a una hora que no existe en el timetable', function () {
    // 2026-06-22 es lunes (ISO weekday 1)
    Carbon::setTestNow(Carbon::create(2026, 6, 22, 6, 0, 0, 'Europe/Madrid'));

    // El timetable solo tiene CrossFit a las 18:00, pero el override pide 20:00
    $bookingsPayload = [
        'timetable' => [['id' => '1800_60', 'time' => '18:00-19:00']],
        'bookings'  => [['id' => 222, 'timeid' => '1800_60', 'className' => 'CrossFit', 'bookState' => 0]],
    ];

    Http::fake([
        'login.aimharder.com/api/login'             => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response($bookingsPayload, 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create([
        'weekdays'       => [1],
        'time'           => '18:00',
        'class_name'     => 'CrossFit',
        'time_overrides' => ['2026-06-22' => '20:00'], // no existe en timetable
    ]);

    $this->artisan('bookings:run')->assertOk();

    expect(BookingLog::where('status', 'no_match')->count())->toBe(1);
});
