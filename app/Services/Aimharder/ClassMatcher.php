<?php
namespace App\Services\Aimharder;

class ClassMatcher
{
    /**
     * @param  array  $payload  JSON decodificado de /api/bookings
     * @return array|null  el objeto de clase que casa, o null
     */
    public static function find(array $payload, string $time, string $className): ?array
    {
        $timeById = [];
        foreach ($payload['timetable'] ?? [] as $slot) {
            $timeById[$slot['id']] = $slot['time'] ?? '';
        }

        foreach ($payload['bookings'] ?? [] as $b) {
            $slotTime = $timeById[$b['timeid'] ?? ''] ?? '';
            if (str_starts_with($slotTime, $time) && ($b['className'] ?? '') === $className) {
                return $b;
            }
        }

        return null;
    }
}
