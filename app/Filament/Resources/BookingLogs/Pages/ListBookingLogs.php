<?php

namespace App\Filament\Resources\BookingLogs\Pages;

use App\Filament\Resources\BookingLogs\BookingLogResource;
use Filament\Resources\Pages\ListRecords;

class ListBookingLogs extends ListRecords
{
    protected static string $resource = BookingLogResource::class;

    // BookingLog es solo lectura: sin acción de crear.
}
