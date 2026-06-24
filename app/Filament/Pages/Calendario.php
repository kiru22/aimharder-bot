<?php

namespace App\Filament\Pages;

use App\Models\BookingRule;
use App\Services\OccurrencesService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Calendario extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Calendario';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.calendario';

    public string $month;

    // Para el modal de cambio de hora
    public string $changeTimeDate   = '';
    public int    $changeTimeRuleId = 0;
    public string $changeTimeValue  = '';
    public bool   $showChangeTimeModal = false;

    public function mount(): void
    {
        $tz          = config('aimharder.timezone');
        $this->month = now($tz)->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)
            ->subMonth()
            ->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = Carbon::createFromFormat('Y-m', $this->month)
            ->addMonth()
            ->format('Y-m');
    }

    /** @return Collection<int, array> */
    public function occurrences(): Collection
    {
        $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth()->startOfDay();
        $end   = Carbon::createFromFormat('Y-m', $this->month)->endOfMonth()->startOfDay();

        return OccurrencesService::forRange($start, $end);
    }

    /**
     * Añade la fecha a skip_dates de la regla (cancela ese día).
     */
    public function cancelDay(int $ruleId, string $date): void
    {
        $rule = BookingRule::findOrFail($ruleId);
        $skip = $rule->skip_dates ?? [];

        if (! in_array($date, $skip, true)) {
            $skip[] = $date;
            $rule->update(['skip_dates' => $skip]);
        }

        Notification::make()
            ->title("Día {$date} cancelado")
            ->success()
            ->send();
    }

    /**
     * Abre el modal de cambio de hora.
     */
    public function openChangeTime(int $ruleId, string $date): void
    {
        $rule = BookingRule::findOrFail($ruleId);

        $this->changeTimeRuleId    = $ruleId;
        $this->changeTimeDate      = $date;
        $this->changeTimeValue     = $rule->effectiveTimeFor($date);
        $this->showChangeTimeModal = true;
    }

    /**
     * Cierra el modal de cambio de hora.
     */
    public function closeChangeTime(): void
    {
        $this->showChangeTimeModal = false;
    }

    /**
     * Guarda el override de hora para la fecha dada.
     * $time debe tener formato HH:MM.
     */
    public function changeTime(int $ruleId, string $date, string $time): void
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            Notification::make()
                ->title('Formato de hora inválido (usa HH:MM)')
                ->danger()
                ->send();

            return;
        }

        $rule             = BookingRule::findOrFail($ruleId);
        $overrides        = $rule->time_overrides ?? [];
        $overrides[$date] = $time;
        $rule->update(['time_overrides' => $overrides]);

        $this->showChangeTimeModal = false;

        Notification::make()
            ->title("Hora del {$date} cambiada a {$time}")
            ->success()
            ->send();
    }

    /**
     * Guarda el cambio de hora desde el modal (usa las propiedades públicas).
     */
    public function saveChangeTime(): void
    {
        $this->changeTime($this->changeTimeRuleId, $this->changeTimeDate, $this->changeTimeValue);
    }

    /**
     * Quita la fecha de skip_dates de la regla (reactiva ese día).
     */
    public function reactivateDay(int $ruleId, string $date): void
    {
        $rule = BookingRule::findOrFail($ruleId);
        $skip = array_values(array_filter(
            $rule->skip_dates ?? [],
            fn (string $d) => $d !== $date
        ));
        $rule->update(['skip_dates' => $skip]);

        Notification::make()
            ->title("Día {$date} reactivado")
            ->success()
            ->send();
    }
}
