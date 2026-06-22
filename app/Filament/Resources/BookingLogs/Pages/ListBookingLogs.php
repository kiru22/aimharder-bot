<?php

namespace App\Filament\Resources\BookingLogs\Pages;

use App\Filament\Resources\BookingLogs\BookingLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBookingLogs extends ListRecords
{
    protected static string $resource = BookingLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
