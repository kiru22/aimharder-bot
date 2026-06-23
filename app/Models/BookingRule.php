<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BookingRule extends Model
{
    protected $fillable = ['account_id', 'weekdays', 'time', 'class_name', 'insist', 'active', 'skip_dates'];

    protected function casts(): array
    {
        return [
            'weekdays'   => 'array',
            'insist'     => 'boolean',
            'active'     => 'boolean',
            'skip_dates' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Próxima ocurrencia >= $from cuyo ISO weekday esté en $weekdays
     * y cuya fecha Y-m-d NO esté en skip_dates.
     * Combina la fecha con $this->time ("HH:MM").
     * Busca hasta 14 días; retorna null si no hay ninguna.
     */
    public function nextOccurrence(?Carbon $from = null): ?Carbon
    {
        $tz   = config('aimharder.timezone');
        $from ??= now($tz);
        $skip = $this->skip_dates ?? [];
        $days = $this->weekdays ?? [];

        for ($i = 0; $i < 14; $i++) {
            $candidate = $from->copy()->addDays($i);

            if (! in_array($candidate->dayOfWeekIso, $days, true)) {
                continue;
            }

            if (in_array($candidate->format('Y-m-d'), $skip, true)) {
                continue;
            }

            [$h, $m] = explode(':', $this->time);
            $dt = $candidate->setTime((int) $h, (int) $m);

            // Descarta ocurrencias ya pasadas (incluye hoy si la hora ya pasó).
            if ($dt->lessThanOrEqualTo($from)) {
                continue;
            }

            return $dt;
        }

        return null;
    }

    /**
     * Todas las ocurrencias en los próximos $days días.
     *
     * @return Collection<int, Carbon>
     */
    public function upcomingOccurrences(int $days = 14): Collection
    {
        $tz   = config('aimharder.timezone');
        $from = now($tz)->startOfDay();
        $skip = $this->skip_dates ?? [];
        $wds  = $this->weekdays ?? [];
        [$h, $m] = explode(':', $this->time);
        $result = collect();

        for ($i = 0; $i < $days; $i++) {
            $candidate = $from->copy()->addDays($i);

            if (! in_array($candidate->dayOfWeekIso, $wds, true)) {
                continue;
            }

            if (in_array($candidate->format('Y-m-d'), $skip, true)) {
                continue;
            }

            $result->push($candidate->setTime((int) $h, (int) $m));
        }

        return $result;
    }
}
