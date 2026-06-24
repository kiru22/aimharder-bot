<?php

use App\Models\BookingRule;

// Tests para BookingRule::effectiveTimeFor

it('devuelve la hora base cuando no hay overrides', function () {
    $rule = new BookingRule(['time' => '18:00', 'time_overrides' => null]);

    expect($rule->effectiveTimeFor('2026-06-22'))->toBe('18:00');
});

it('devuelve la hora base cuando el override no corresponde a la fecha', function () {
    $rule = new BookingRule(['time' => '18:00', 'time_overrides' => ['2026-06-23' => '19:00']]);

    expect($rule->effectiveTimeFor('2026-06-22'))->toBe('18:00');
});

it('devuelve el override cuando la fecha coincide', function () {
    $rule = new BookingRule(['time' => '18:00', 'time_overrides' => ['2026-06-22' => '19:00']]);

    expect($rule->effectiveTimeFor('2026-06-22'))->toBe('19:00');
});

it('puede manejar múltiples overrides y devuelve el correcto', function () {
    $rule = new BookingRule([
        'time'           => '18:00',
        'time_overrides' => [
            '2026-06-22' => '19:00',
            '2026-06-24' => '09:30',
        ],
    ]);

    expect($rule->effectiveTimeFor('2026-06-22'))->toBe('19:00')
        ->and($rule->effectiveTimeFor('2026-06-24'))->toBe('09:30')
        ->and($rule->effectiveTimeFor('2026-06-25'))->toBe('18:00');
});
