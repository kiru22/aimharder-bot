<?php

namespace App\Filament\Resources\BookingLogs\Pages;

use App\Filament\Resources\BookingLogs\BookingLogResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListBookingLogs extends ListRecords
{
    protected static string $resource = BookingLogResource::class;

    // BookingLog es solo lectura: en vez de "crear", acciones para disparar el bot a mano.
    protected function getHeaderActions(): array
    {
        return [
            Action::make('dryRun')
                ->label('Simular (dry-run)')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->action(fn () => $this->runBookings(true)),
            Action::make('runNow')
                ->label('Ejecutar reservas ahora')
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->modalDescription('Hará login y reservará AHORA las clases de hoy según las reglas activas.')
                ->action(fn () => $this->runBookings(false)),
        ];
    }

    private function runBookings(bool $dry): void
    {
        Artisan::call('bookings:run', $dry ? ['--dry-run' => true] : []);
        $output = trim(Artisan::output());

        Notification::make()
            ->title($dry ? 'Simulación completada' : 'Ejecución completada')
            ->body($output !== '' ? $output : 'Sin reglas activas para hoy.')
            ->success()
            ->send();
    }
}
