<?php

namespace App\Filament\Widgets;

use App\Models\BookingLog;
use App\Models\BookingRule;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BookingStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $booked = BookingLog::query()
            ->where('status', 'booked')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $failed = BookingLog::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $active = BookingRule::query()
            ->where('active', true)
            ->count();

        return [
            Stat::make('Reservadas (30 días)', $booked)
                ->description('Reservas completadas')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Fallidas (30 días)', $failed)
                ->description('Reservas con error')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger'),

            Stat::make('Reglas activas', $active)
                ->description('Reglas en funcionamiento')
                ->descriptionIcon('heroicon-m-cog-6-tooth')
                ->color('info'),
        ];
    }
}
