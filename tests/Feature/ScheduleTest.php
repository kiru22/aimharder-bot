<?php
use Illuminate\Console\Scheduling\Schedule;

it('agenda bookings:run a las 06:00 en Europe/Madrid', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'bookings:run'));

    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('0 6 * * *')
        ->and($event->timezone)->toBe('Europe/Madrid');
});
