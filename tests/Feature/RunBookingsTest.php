<?php
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

function bookingsJson(): array
{
    return [
        'timetable' => [['id' => '1800_60', 'time' => '18:00-19:00']],
        'bookings'  => [['id' => 222, 'timeid' => '1800_60', 'className' => 'CrossFit', 'bookState' => 0]],
    ];
}

it('reserva la clase del día y registra status booked', function () {
    Http::fake([
        'login.aimharder.com/api/login'              => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*'  => Http::response(bookingsJson(), 200),
        'hybridboxgrau.aimharder.com/api/book'       => Http::response(['bookState' => 0, 'id' => '555'], 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create(['weekdays' => [1], 'time' => '18:00', 'class_name' => 'CrossFit']);

    $this->artisan('bookings:run')->assertOk();

    expect(BookingLog::where('status', 'booked')->count())->toBe(1);
});

it('en dry-run no llama a /api/book ni registra booked', function () {
    Http::fake([
        'login.aimharder.com/api/login'             => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response(bookingsJson(), 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create(['weekdays' => [1], 'time' => '18:00', 'class_name' => 'CrossFit']);

    $this->artisan('bookings:run --dry-run')->assertOk();

    Http::assertNotSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/book'));
    expect(BookingLog::count())->toBe(0);   // dry-run no registra NADA, no solo no-booked
});

it('registra no_match cuando la clase no existe', function () {
    Http::fake([
        'login.aimharder.com/api/login'             => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response(bookingsJson(), 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create(['weekdays' => [1], 'time' => '18:00', 'class_name' => 'HYROX-Endurance']);

    $this->artisan('bookings:run')->assertOk();

    expect(BookingLog::where('status', 'no_match')->count())->toBe(1);
});

it('registra failed (no booked) cuando bookState es negativo sin errorMssg', function () {
    Http::fake([
        'login.aimharder.com/api/login'             => Http::response('{}', 200, ['Set-Cookie' => 'amhrdrauth=x; Domain=aimharder.com']),
        'hybridboxgrau.aimharder.com/api/bookings*' => Http::response(bookingsJson(), 200),
        'hybridboxgrau.aimharder.com/api/book'      => Http::response(['bookState' => -2], 200),
    ]);

    $a = Account::create(['label' => 'Yo', 'email' => 'a@b.com', 'password' => 'pw']);
    $a->rules()->create(['weekdays' => [1], 'time' => '18:00', 'class_name' => 'CrossFit']);

    $this->artisan('bookings:run')->assertOk();

    expect(BookingLog::where('status', 'failed')->count())->toBe(1)
        ->and(BookingLog::where('status', 'booked')->count())->toBe(0);
});
