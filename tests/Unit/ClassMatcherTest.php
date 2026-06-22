<?php
use App\Services\Aimharder\ClassMatcher;

function samplePayload(): array
{
    return [
        'timetable' => [
            ['id' => '1800_60', 'time' => '18:00-19:00'],
            ['id' => '1900_60', 'time' => '19:00-20:00'],
        ],
        'bookings' => [
            ['id' => 111, 'timeid' => '1800_60', 'className' => 'B. Jiu-jitsu Principiante', 'bookState' => 0],
            ['id' => 222, 'timeid' => '1800_60', 'className' => 'CrossFit', 'bookState' => 0],
            ['id' => 333, 'timeid' => '1900_60', 'className' => 'CrossFit', 'bookState' => 1],
        ],
    ];
}

it('encuentra la clase por hora + nombre, desempatando clases solapadas', function () {
    $m = ClassMatcher::find(samplePayload(), '18:00', 'CrossFit');
    expect($m['id'])->toBe(222);
});

it('devuelve null si no hay coincidencia', function () {
    expect(ClassMatcher::find(samplePayload(), '18:00', 'HYROX-Endurance'))->toBeNull();
    expect(ClassMatcher::find(samplePayload(), '07:00', 'CrossFit'))->toBeNull();
});

it('conserva bookState para detectar ya-reservada', function () {
    $m = ClassMatcher::find(samplePayload(), '19:00', 'CrossFit');
    expect($m['bookState'])->toBe(1);
});
