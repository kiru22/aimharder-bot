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
        'weekdays'   => [1],            // lunes
        'time'       => '18:00',
        'class_name' => 'CrossFit',
        'skip_dates' => ['2026-06-22'], // hoy saltado
    ]);

    $this->artisan('bookings:run')->assertOk();

    Http::assertNotSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/api/book'));
    expect(BookingLog::where('status', 'skipped')->count())->toBe(1);
    expect(BookingLog::first()->message)->toBe('Saltada manualmente.');
});
