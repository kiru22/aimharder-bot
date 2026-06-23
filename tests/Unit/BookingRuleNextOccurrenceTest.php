<?php

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

it('salta hoy si la hora ya pasó y devuelve la siguiente ocurrencia', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 23, 20, 0, 0, 'Europe/Madrid')); // martes 20:00 (tras las 18:00)

    $rule = new BookingRule(['weekdays' => [2], 'time' => '18:00', 'skip_dates' => null]); // martes 18:00

    $next = $rule->nextOccurrence();

    expect($next)->not->toBeNull()
        ->and($next->format('Y-m-d'))->toBe('2026-06-30') // siguiente martes, no hoy
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
