<?php

namespace App\Services;

use App\Models\BookingLog;
use App\Models\BookingRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OccurrencesService
{
    /**
     * Ocurrencias individuales (un item por regla y día) en el rango [start, end] inclusive.
     *
     * @return Collection<int, array{date:string, time:string, account:string, class_name:string, status:string, rule_id:int, log_id:int|null}>
     *   date = 'Y-m-d'; time = hora efectiva (override o regla);
     *   status ∈ booked|failed|already|no_match|skipped|cancelled|scheduled
     */
    public static function forRange(Carbon $start, Carbon $end): Collection
    {
        $tz    = config('aimharder.timezone');
        $today = now($tz)->startOfDay();

        // Carga todas las reglas activas con sus accounts para evitar N+1
        $rules = BookingRule::with('account')
            ->where('active', true)
            ->get();

        if ($rules->isEmpty()) {
            return collect();
        }

        // Carga todos los BookingLogs del rango de una sola vez
        $logs = BookingLog::whereBetween('target_date', [
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        ])->get()->groupBy(function (BookingLog $log) {
            // Clave compuesta: rule_id + target_date
            return $log->booking_rule_id.'::'.$log->target_date->format('Y-m-d');
        });

        $items = collect();

        foreach ($rules as $rule) {
            $weekdays  = $rule->weekdays ?? [];
            $skipDates = $rule->skip_dates ?? [];

            // Iteramos día a día en el rango
            $cursor = $start->copy()->startOfDay();
            $endDay = $end->copy()->startOfDay();

            while ($cursor->lessThanOrEqualTo($endDay)) {
                $ymd = $cursor->format('Y-m-d');

                // Solo días cuyo ISO weekday esté en la regla
                if (! in_array($cursor->dayOfWeekIso, $weekdays, true)) {
                    $cursor->addDay();
                    continue;
                }

                $logKey = $rule->id.'::'.$ymd;
                $log    = $logs->get($logKey)?->first();

                if ($log !== null) {
                    // El bot ya corrió ese día: usamos el status del log
                    $status = $log->status;
                    $logId  = $log->id;
                } elseif (in_array($ymd, $skipDates, true)) {
                    // Fecha marcada como saltada (cancelada manualmente)
                    $status = 'cancelled';
                    $logId  = null;
                } elseif ($cursor->greaterThanOrEqualTo($today)) {
                    // Día futuro (o hoy si aún no corrió) → programado
                    $status = 'scheduled';
                    $logId  = null;
                } else {
                    // Pasado sin log: el bot no corrió ese día, omitimos para evitar ruido
                    $cursor->addDay();
                    continue;
                }

                $items->push([
                    'date'       => $ymd,
                    'time'       => $rule->effectiveTimeFor($ymd),
                    'account'    => $rule->account->label,
                    'class_name' => $rule->class_name,
                    'status'     => $status,
                    'rule_id'    => $rule->id,
                    'log_id'     => $logId,
                ]);

                $cursor->addDay();
            }
        }

        return $items->sortBy([['date', 'asc'], ['time', 'asc']])->values();
    }
}
